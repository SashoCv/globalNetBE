<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduGroupSchool;
use App\Models\EduMunicipality;
use App\Models\EduParticipant;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Обуки: automatic grouping of a program (plan §5.1).
 *
 *  1. Demand: per school and participant language, minus what locked groups
 *     already hold. The group language comes from program.language_map
 *     (tr → mk by default; the allocation keeps "tr").
 *  2. Zone per (municipality, language): the language exception, then '*'.
 *  3. Buckets: (zone, group language). Optionally (merge_undersized) a bucket
 *     under the minimum is merged with a neighbouring zone (config
 *     edu.zone_adjacency) — only as a suggestion in zone_merges.
 *  4. Group count: k = ceil(T / (max + overflow)); fewer when the groups
 *     would fall under the minimum.
 *  5. Packing, geography first: training-centre blocks (largest first) go to
 *     a group that already has the same or a neighbouring centre
 *     (edu.hub_adjacency), else to the emptiest one; a block that does not
 *     fit is split by municipality, then school, then (split_schools) by
 *     count. A final pass tops up groups under the minimum.
 *  6. Invited schools (0 participants) join the group with the most
 *     participants from their municipality, else their centre.
 *  7. Numbering: zone order, language (mk, sq, tr), larger groups first,
 *     continuing after the kept (locked) groups.
 *  8. Saving (unless dry_run) replaces every unlocked group.
 *
 * Deterministic: the same data always gives the same groups.
 */
class AutoGrouper
{
    private int $min = 1;

    private int $max = 1;

    private int $cap = 1;

    private int $target = 1;

    private bool $splitSchools = true;

    /** @var array<int, array<int, true>> hub id => adjacent hub ids (itself included) */
    private array $adjacent = [];

    /** @var array<int, int> bin index => load (during packing) */
    private array $bins = [];

    public function __construct(
        private ProgramZoneService $zones,
        private EduProgramCounts $counts,
    ) {
    }

    /**
     * @param  array{dry_run?: bool, keep_locked?: bool, split_schools?: bool, merge_undersized?: bool, max_overflow?: int|null}  $options
     */
    public function run(EduProgram $program, array $options = []): array
    {
        $dryRun = $options['dry_run'] ?? true;
        $keepLocked = $options['keep_locked'] ?? true;
        $this->splitSchools = $options['split_schools'] ?? true;
        $mergeUndersized = $options['merge_undersized'] ?? false;
        $overflow = max(0, (int) ($options['max_overflow'] ?? config('edu.auto_group.max_overflow', 0)));
        $this->min = max(1, (int) $program->group_size_min);
        $this->max = max($this->min, (int) $program->group_size_max);
        $this->cap = $this->max + $overflow;

        $municipalities = EduMunicipality::query()->get(['id', 'name', 'region_id', 'hub_municipality_id'])->keyBy('id');
        $this->buildAdjacency($municipalities);
        $zones = EduProgramZone::where('program_id', $program->id)->with('region:id,name')->orderBy('sort_order')->orderBy('id')->get()->keyBy('id');
        $zoneMap = $this->zones->map($program);

        $kept = $keepLocked
            ? EduGroup::where('program_id', $program->id)->where('is_locked', true)->orderBy('number')->get()
            : collect();
        $keptAllocated = $kept->isEmpty() ? [] : $this->counts->allocated($program, $kept->pluck('id')->all());
        $keptSchools = $kept->isEmpty() ? collect() : EduGroupSchool::whereIn('group_id', $kept->pluck('id'))->pluck('school_id')->flip();

        // 1–2. Demand items and invited schools.
        $warnings = [];
        $items = [];
        $invited = [];
        $programSchools = EduProgramSchool::where('program_id', $program->id)
            ->with('school:id,display_name,municipality_id,languages')
            ->orderBy('id')->get();
        foreach ($programSchools as $ps) {
            $school = $ps->school;
            $municipality = $municipalities->get($school->municipality_id);
            $hubId = $municipality?->hub_municipality_id ?: $school->municipality_id;
            $base = [
                'school_id' => $school->id,
                'display_name' => $school->display_name,
                'municipality_id' => $school->municipality_id,
                'municipality_name' => $municipality?->name,
                'hub_id' => $hubId,
                'hub_name' => $municipalities->get($hubId)?->name,
            ];
            if ($ps->count_total === 0) {
                if (!isset($keptSchools[$school->id])) {
                    $invited[] = $base + ['languages' => EduLanguage::normalize($school->languages)];
                }
                continue;
            }
            foreach ($ps->counts() as $lang => $n) {
                $n -= $keptAllocated[$school->id][$lang] ?? 0;
                if ($n <= 0) {
                    continue;
                }
                $groupLanguage = $program->groupLanguage($lang);
                $zoneId = ProgramZoneService::lookup($zoneMap, $school->municipality_id, $lang, $groupLanguage);
                if (!$zoneId || !$zones->has($zoneId)) {
                    $warnings[] = [
                        'code' => 'no_zone',
                        'message' => "„{$school->display_name}“ ({$municipality?->name}) не е во ниедна зона; {$n} учесници не се распоредени.",
                        'school_id' => $school->id,
                        'municipality_id' => $school->municipality_id,
                        'language' => $lang,
                        'count' => $n,
                    ];
                    continue;
                }
                $items[] = $base + ['language' => $lang, 'group_language' => $groupLanguage, 'zone_id' => $zoneId, 'count' => $n];
            }
        }
        if ($program->isPersons()) {
            $noSchool = EduParticipant::where('program_id', $program->id)->whereNull('school_id')->count();
            if ($noSchool > 0) {
                $warnings[] = ['code' => 'no_school', 'message' => "{$noSchool} учесници немаат училиште и не се распоредени.", 'count' => $noSchool];
            }
        }

        // 3. Buckets per (zone, group language).
        $buckets = [];
        foreach ($items as $item) {
            $key = $item['zone_id'] . '|' . $item['group_language'];
            $buckets[$key] ??= ['zone_id' => $item['zone_id'], 'zone_ids' => [$item['zone_id']], 'language' => $item['group_language'], 'items' => [], 'total' => 0];
            $buckets[$key]['items'][] = $item;
            $buckets[$key]['total'] += $item['count'];
        }
        $zoneOrder = fn (int $zoneId) => [$zones->get($zoneId)?->sort_order ?? PHP_INT_MAX, $zoneId];
        uksort($buckets, function ($a, $b) use ($buckets, $zoneOrder) {
            return [$zoneOrder($buckets[$a]['zone_id']), self::langOrder($buckets[$a]['language'])]
                <=> [$zoneOrder($buckets[$b]['zone_id']), self::langOrder($buckets[$b]['language'])];
        });

        $zoneMerges = $mergeUndersized ? $this->mergeUndersized($buckets, $zones) : [];
        foreach ($buckets as $bucket) {
            if ($bucket['total'] < $this->min) {
                $zone = $zones->get($bucket['zone_id']);
                $label = EduLanguage::LABELS[$bucket['language']] ?? $bucket['language'];
                $neighbours = $this->adjacentZones($bucket['zone_ids'], $zones)->map(fn ($z) => ['id' => $z->id, 'name' => $z->name])->values()->all();
                $warnings[] = [
                    'code' => 'undersized',
                    'message' => "Зоната „{$zone?->name}“ има само {$bucket['total']} учесници на {$label} (минимум {$this->min})."
                        . ($neighbours ? ' Предлог: спојување со ' . implode(', ', array_map(fn ($z) => "„{$z['name']}“", $neighbours)) . '.' : ''),
                    'zone_id' => $bucket['zone_id'],
                    'language' => $bucket['language'],
                    'count' => $bucket['total'],
                    'suggest_into' => $neighbours,
                ];
            }
        }

        // 4–5. Pack each bucket.
        $groups = [];
        foreach ($buckets as $bucket) {
            foreach ($this->pack($bucket['items']) as $binIndex => $binItems) {
                $groups[] = [
                    'zone_id' => $bucket['zone_id'],
                    'zone_ids' => $bucket['zone_ids'],
                    'language' => $bucket['language'],
                    'bin' => $binIndex,
                    'items' => $binItems,
                ];
            }
        }

        // 6. Invited schools.
        foreach ($invited as $school) {
            $language = count($school['languages']) === 1 ? $school['languages'][0] : null;
            $groupLanguage = $language ? $program->groupLanguage($language) : null;
            $best = null;
            $bestScore = null;
            foreach ($groups as $i => $group) {
                if ($groupLanguage && $group['language'] !== $groupLanguage) {
                    continue;
                }
                $sameMunicipality = $sameHub = 0;
                foreach ($group['items'] as $item) {
                    if ($item['municipality_id'] === $school['municipality_id']) {
                        $sameMunicipality += $item['count'];
                    }
                    if ($item['hub_id'] === $school['hub_id']) {
                        $sameHub += $item['count'];
                    }
                }
                if ($sameMunicipality === 0 && $sameHub === 0) {
                    continue;
                }
                $score = [$sameMunicipality, $sameHub];
                if ($bestScore === null || $score > $bestScore) {
                    [$best, $bestScore] = [$i, $score];
                }
            }
            if ($best === null) {
                $warnings[] = [
                    'code' => 'invited_unplaced',
                    'message' => "Поканетото училиште „{$school['display_name']}“ (без учесници) нема група од својата општина или центар.",
                    'school_id' => $school['school_id'],
                ];
                continue;
            }
            $groups[$best]['items'][] = array_diff_key($school, ['languages' => true]) + [
                'language' => $language ?? $groups[$best]['language'],
                'group_language' => $groups[$best]['language'],
                'zone_id' => $groups[$best]['zone_id'],
                'count' => 0,
            ];
        }

        // 7. Merge the pieces per (school, language), venue, numbering.
        foreach ($groups as &$group) {
            $group['allocations'] = $this->allocations($group['items']);
            $group['total'] = array_sum(array_column($group['allocations'], 'participant_count'));
            [$group['venue_municipality_suggestion'], $group['venue_school_suggestion']] = $this->venue($group['allocations'], $municipalities);
        }
        unset($group);
        usort($groups, fn ($a, $b) => [$zoneOrder($a['zone_id']), self::langOrder($a['language']), -$a['total'], $a['allocations'][0]['municipality_name'] ?? '', $a['bin']]
            <=> [$zoneOrder($b['zone_id']), self::langOrder($b['language']), -$b['total'], $b['allocations'][0]['municipality_name'] ?? '', $b['bin']]);
        $number = (int) $kept->max('number');
        foreach ($groups as &$group) {
            $group['number'] = ++$number;
        }
        unset($group);

        // 8. Save.
        if (!$dryRun) {
            $this->save($program, $groups, $kept->pluck('id')->all());
        }

        $total = array_sum(array_column($items, 'count'));
        $placed = array_sum(array_column($groups, 'total'));

        return [
            'dry_run' => $dryRun,
            'groups' => array_map(fn ($g) => $this->groupOut($g, $zones), $groups),
            'kept_groups' => $kept->map(fn (EduGroup $g) => [
                'id' => $g->id,
                'number' => $g->number,
                'language' => $g->language,
                'zone_id' => $g->zone_id,
                'total' => (int) EduGroupSchool::where('group_id', $g->id)->sum('participant_count'),
            ])->values()->all(),
            'zone_merges' => $zoneMerges,
            'warnings' => $warnings,
            'summary' => [
                'groups' => count($groups),
                'kept_groups' => $kept->count(),
                'participants' => $total,
                'placed' => $placed,
                'unplaced' => array_sum(array_map(fn ($w) => $w['code'] === 'no_zone' ? $w['count'] : 0, $warnings)),
                'undersized' => count(array_filter($groups, fn ($g) => $g['total'] < $this->min)),
                'oversized' => count(array_filter($groups, fn ($g) => $g['total'] > $this->max)),
            ],
        ];
    }

    // ── buckets ──────────────────────────────────────────────────────────

    /**
     * Merges buckets under the minimum into a neighbouring zone's bucket of
     * the same language until they reach it (or have no neighbour left).
     *
     * @return list<array>
     */
    private function mergeUndersized(array &$buckets, Collection $zones): array
    {
        $merges = [];
        for ($guard = 0; $guard < 100; $guard++) {
            $small = array_filter($buckets, fn ($b) => $b['total'] < $this->min);
            uasort($small, fn ($a, $b) => $a['total'] <=> $b['total']);
            $done = false;
            foreach ($small as $key => $bucket) {
                $neighbourIds = $this->adjacentZones($bucket['zone_ids'], $zones)->keys()->all();
                $candidates = array_filter($buckets, fn ($b, $k) => $k !== $key && $b['language'] === $bucket['language']
                    && array_intersect($b['zone_ids'], $neighbourIds), ARRAY_FILTER_USE_BOTH);
                if (!$candidates) {
                    continue;
                }
                // Prefer another small bucket, then the smallest neighbour.
                uasort($candidates, fn ($a, $b) => [$a['total'] >= $this->min, $a['total']] <=> [$b['total'] >= $this->min, $b['total']]);
                $intoKey = array_key_first($candidates);
                $into = $buckets[$intoKey];
                $label = EduLanguage::LABELS[$bucket['language']] ?? $bucket['language'];
                $from = $zones->get($bucket['zone_id']);
                $merges[] = [
                    'from' => ['id' => $from?->id, 'name' => $from?->name],
                    'into' => ['id' => $into['zone_id'], 'name' => $zones->get($into['zone_id'])?->name],
                    'language' => $bucket['language'],
                    'from_total' => $bucket['total'],
                    'into_total' => $into['total'],
                    'reason' => "Зоната „{$from?->name}“ има само {$bucket['total']} учесници на {$label} (минимум {$this->min}).",
                ];
                $buckets[$intoKey]['items'] = array_merge($into['items'], $bucket['items']);
                $buckets[$intoKey]['zone_ids'] = array_values(array_unique(array_merge($into['zone_ids'], $bucket['zone_ids'])));
                $buckets[$intoKey]['total'] += $bucket['total'];
                unset($buckets[$key]);
                $done = true;
                break;
            }
            if (!$done) {
                break;
            }
        }

        return $merges;
    }

    /** Zones next to any of the given ones (config edu.zone_adjacency, by zone or region name). */
    private function adjacentZones(array $zoneIds, Collection $zones): Collection
    {
        $names = fn ($z) => array_filter([$z->name, $z->region?->name]);
        $pairs = config('edu.zone_adjacency', []);
        $own = $zones->only($zoneIds);

        return $zones->filter(function ($z) use ($zoneIds, $own, $names, $pairs) {
            if (in_array($z->id, $zoneIds, true)) {
                return false;
            }
            foreach ($own as $o) {
                foreach ($pairs as [$a, $b]) {
                    if ((in_array($a, $names($o), true) && in_array($b, $names($z), true))
                        || (in_array($b, $names($o), true) && in_array($a, $names($z), true))) {
                        return true;
                    }
                }
            }

            return false;
        });
    }

    // ── packing ──────────────────────────────────────────────────────────

    /**
     * @param  list<array>  $items  demand of one bucket
     * @return list<list<array>> items per group
     */
    private function pack(array $items): array
    {
        $total = array_sum(array_column($items, 'count'));
        if ($total <= 0) {
            return [];
        }
        $k = max(1, (int) ceil($total / $this->cap));
        if ($k > 1 && $total / $k < $this->min) {
            $k = max(1, intdiv($total, $this->min));
        }
        $this->target = (int) ceil($total / $k);

        // Centre blocks → municipality blocks → school pieces.
        $tree = [];
        foreach ($items as $item) {
            $tree[$item['hub_id']][$item['municipality_id']][$item['school_id']][] = $item;
        }
        $blocks = [];
        foreach ($tree as $hubId => $municipalities) {
            $children = [];
            foreach ($municipalities as $municipalityId => $schools) {
                $schoolPieces = [];
                foreach ($schools as $schoolItems) {
                    $schoolPieces[] = $this->piece($hubId, null, $schoolItems, $municipalityId);
                }
                $children[] = $this->piece($hubId, $this->sortPieces($schoolPieces), [], $municipalityId);
            }
            $blocks[] = $this->piece($hubId, $this->sortPieces($children), []);
        }
        $blocks = $this->sortPieces($blocks);

        $this->bins = array_fill(0, $k, 0);
        $bins = array_fill(0, $k, ['hubs' => [], 'municipalities' => [], 'items' => []]);
        foreach ($blocks as $block) {
            $this->place($bins, $block);
        }
        if ($k > 1) {
            $this->topUp($bins);
        }

        return array_values(array_filter(array_map(fn ($b) => $b['items'], $bins)));
    }

    private function piece(int $hubId, ?array $children, array $items, ?int $municipalityId = null): array
    {
        $size = $children !== null ? array_sum(array_column($children, 'size')) : array_sum(array_column($items, 'count'));
        $first = $children !== null ? ($children[0]['name'] ?? '') : (($items[0]['municipality_name'] ?? '') . ' ' . ($items[0]['display_name'] ?? ''));

        return ['hub_id' => $hubId, 'municipality_id' => $municipalityId, 'size' => $size, 'children' => $children, 'items' => $items, 'name' => $first];
    }

    private function sortPieces(array $pieces): array
    {
        usort($pieces, fn ($a, $b) => [-$a['size'], $a['name'], $a['hub_id']] <=> [-$b['size'], $b['name'], $b['hub_id']]);

        return $pieces;
    }

    private function place(array &$bins, array $piece): void
    {
        $size = $piece['size'];
        if ($size <= 0) {
            return;
        }
        $all = array_keys($bins);
        $adjacent = array_values(array_filter($all, fn ($i) => $this->touches($bins[$i], $piece['hub_id'])));
        // Part of a municipality: where the rest of the municipality went.
        $same = $piece['municipality_id'] ? array_values(array_filter($all, fn ($i) => isset($bins[$i]['municipalities'][$piece['municipality_id']]))) : [];
        foreach ([[$same, $this->target], [$adjacent, $this->target], [$all, $this->target], [$same, $this->cap], [$adjacent, $this->cap], [$all, $this->cap]] as [$set, $limit]) {
            $fit = array_values(array_filter($set, fn ($i) => $this->bins[$i] + $size <= $limit));
            if ($fit) {
                $this->add($bins, $this->emptiest($fit), $piece);

                return;
            }
        }

        if ($piece['children'] !== null) {
            foreach ($piece['children'] as $child) {
                $this->place($bins, $child);
            }

            return;
        }

        if ($this->splitSchools && $size > 1) {
            // Fill the group with the most room (a neighbour first), then place the rest.
            foreach ([$this->target, $this->cap] as $limit) {
                $room = array_values(array_filter($all, fn ($i) => $this->bins[$i] < $limit));
                if (!$room) {
                    continue;
                }
                $rank = fn ($i) => [
                    !($piece['municipality_id'] && isset($bins[$i]['municipalities'][$piece['municipality_id']])),
                    !$this->touches($bins[$i], $piece['hub_id']),
                    $this->bins[$i] - $limit,
                    $i,
                ];
                usort($room, fn ($a, $b) => $rank($a) <=> $rank($b));
                $i = $room[0];
                [$part, $rest] = $this->take($piece, $limit - $this->bins[$i]);
                $this->add($bins, $i, $part);
                $this->place($bins, $rest);

                return;
            }
        }

        $this->add($bins, $this->emptiest($all), $piece);
    }

    /**
     * Groups under the minimum take schools (or, with split_schools, part of
     * a school) from groups that can spare them, neighbours first.
     */
    private function topUp(array &$bins): void
    {
        for ($guard = 0; $guard < 500; $guard++) {
            $short = array_keys(array_filter($this->bins, fn ($load) => $load < $this->min));
            if (!$short) {
                return;
            }
            usort($short, fn ($a, $b) => [$this->bins[$a], $a] <=> [$this->bins[$b], $b]);
            $moved = false;
            foreach ($short as $d) {
                $need = $this->min - $this->bins[$d];
                $room = $this->cap - $this->bins[$d];
                $options = [];
                foreach ($bins as $b => $bin) {
                    if ($b === $d) {
                        continue;
                    }
                    foreach ($this->schoolsIn($bin) as $schoolId => $school) {
                        $options[] = ['bin' => $b, 'school_id' => $schoolId] + $school;
                    }
                }
                // Whole schools the donor can spare.
                $whole = array_filter($options, fn ($o) => $o['size'] <= $room && $this->bins[$o['bin']] - $o['size'] >= $this->min);
                usort($whole, fn ($a, $b) => [!$this->touches($bins[$d], $a['hub_id']), $a['size'] < $need, $a['size'] >= $need ? $a['size'] : -$a['size'], $a['bin'], $a['school_id']]
                    <=> [!$this->touches($bins[$d], $b['hub_id']), $b['size'] < $need, $b['size'] >= $need ? $b['size'] : -$b['size'], $b['bin'], $b['school_id']]);
                if ($whole && ($this->touches($bins[$d], $whole[0]['hub_id']) || $this->bins[$d] === 0 || !$this->splitSchools)) {
                    $this->moveSchool($bins, $whole[0]['bin'], $d, $whole[0]['school_id'], $whole[0]['size']);
                    $moved = true;
                    break;
                }
                // Part of a school.
                if ($this->splitSchools) {
                    $partial = array_filter($options, fn ($o) => $this->bins[$o['bin']] > $this->min);
                    usort($partial, fn ($a, $b) => [!$this->touches($bins[$d], $a['hub_id']), -($this->bins[$a['bin']] - $this->min), -$a['size'], $a['bin'], $a['school_id']]
                        <=> [!$this->touches($bins[$d], $b['hub_id']), -($this->bins[$b['bin']] - $this->min), -$b['size'], $b['bin'], $b['school_id']]);
                    if ($partial) {
                        $o = $partial[0];
                        $units = min($need, $room, $o['size'], $this->bins[$o['bin']] - $this->min);
                        if ($units > 0) {
                            $this->moveSchool($bins, $o['bin'], $d, $o['school_id'], $units);
                            $moved = true;
                            break;
                        }
                    }
                }
                if ($whole) {
                    $this->moveSchool($bins, $whole[0]['bin'], $d, $whole[0]['school_id'], $whole[0]['size']);
                    $moved = true;
                    break;
                }
            }
            if (!$moved) {
                return;
            }
        }
    }

    /** @return array<int, array{size: int, hub_id: int}> */
    private function schoolsIn(array $bin): array
    {
        $out = [];
        foreach ($bin['items'] as $item) {
            $out[$item['school_id']] ??= ['size' => 0, 'hub_id' => $item['hub_id']];
            $out[$item['school_id']]['size'] += $item['count'];
        }
        ksort($out);

        return $out;
    }

    /** Moves $units participants of a school (its languages in order) between groups. */
    private function moveSchool(array &$bins, int $from, int $to, int $schoolId, int $units): void
    {
        $keep = [];
        $moved = [];
        foreach ($bins[$from]['items'] as $item) {
            if ($item['school_id'] !== $schoolId || $units <= 0) {
                $keep[] = $item;
                continue;
            }
            $n = min($units, $item['count']);
            $units -= $n;
            $moved[] = ['count' => $n] + $item;
            if ($item['count'] > $n) {
                $keep[] = ['count' => $item['count'] - $n] + $item;
            }
        }
        $bins[$from]['items'] = $keep;
        $this->bins[$from] = array_sum(array_column($keep, 'count'));
        $bins[$from]['hubs'] = array_fill_keys(array_column($keep, 'hub_id'), true);
        $bins[$from]['municipalities'] = array_fill_keys(array_column($keep, 'municipality_id'), true);
        $this->add($bins, $to, $this->piece($moved[0]['hub_id'] ?? 0, null, $moved));
    }

    /** Splits a school piece: the first $n participants and the rest. */
    private function take(array $piece, int $n): array
    {
        $part = [];
        $rest = [];
        foreach ($piece['items'] as $item) {
            if ($n <= 0) {
                $rest[] = $item;
                continue;
            }
            $k = min($n, $item['count']);
            $n -= $k;
            $part[] = ['count' => $k] + $item;
            if ($item['count'] > $k) {
                $rest[] = ['count' => $item['count'] - $k] + $item;
            }
        }

        return [$this->piece($piece['hub_id'], null, $part), $this->piece($piece['hub_id'], null, $rest)];
    }

    private function add(array &$bins, int $i, array $piece): void
    {
        foreach ($this->leaves($piece) as $item) {
            $bins[$i]['items'][] = $item;
            $bins[$i]['hubs'][$item['hub_id']] = true;
            $bins[$i]['municipalities'][$item['municipality_id']] = true;
            $this->bins[$i] += $item['count'];
        }
    }

    private function leaves(array $piece): array
    {
        if ($piece['children'] === null) {
            return $piece['items'];
        }
        $out = [];
        foreach ($piece['children'] as $child) {
            array_push($out, ...$this->leaves($child));
        }

        return $out;
    }

    private function emptiest(array $indexes): int
    {
        usort($indexes, fn ($a, $b) => [$this->bins[$a], $a] <=> [$this->bins[$b], $b]);

        return $indexes[0];
    }

    /** The group already has this centre or a neighbouring one. */
    private function touches(array $bin, int $hubId): bool
    {
        foreach (array_keys($bin['hubs']) as $h) {
            if (isset($this->adjacent[$hubId][$h])) {
                return true;
            }
        }

        return false;
    }

    private function buildAdjacency(Collection $municipalities): void
    {
        $this->adjacent = [];
        $byName = $municipalities->pluck('id', 'name');
        foreach ($municipalities as $m) {
            $this->adjacent[$m->id][$m->id] = true;
        }
        foreach (config('edu.hub_adjacency', []) as [$a, $b]) {
            if (isset($byName[$a], $byName[$b])) {
                $this->adjacent[$byName[$a]][$byName[$b]] = true;
                $this->adjacent[$byName[$b]][$byName[$a]] = true;
            }
        }
    }

    // ── output ───────────────────────────────────────────────────────────

    /** One row per (school, language), ordered by municipality and school. */
    private function allocations(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $key = $item['school_id'] . '|' . $item['language'];
            if (isset($out[$key])) {
                $out[$key]['participant_count'] += $item['count'];
                continue;
            }
            $out[$key] = [
                'school_id' => $item['school_id'],
                'display_name' => $item['display_name'],
                'municipality_id' => $item['municipality_id'],
                'municipality_name' => $item['municipality_name'],
                'hub_municipality_id' => $item['hub_id'],
                'language' => $item['language'],
                'participant_count' => $item['count'],
            ];
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => [$a['municipality_name'], $a['display_name'], $a['school_id'], self::langOrder($a['language'])]
            <=> [$b['municipality_name'], $b['display_name'], $b['school_id'], self::langOrder($b['language'])]);

        return $out;
    }

    /** The centre with the most participants, and the largest school there. */
    private function venue(array $allocations, Collection $municipalities): array
    {
        $byHub = [];
        foreach ($allocations as $a) {
            $byHub[$a['hub_municipality_id']] = ($byHub[$a['hub_municipality_id']] ?? 0) + $a['participant_count'];
        }
        if (!$byHub) {
            return [null, null];
        }
        uksort($byHub, fn ($a, $b) => [-$byHub[$a], $municipalities->get($a)?->name] <=> [-$byHub[$b], $municipalities->get($b)?->name]);
        $hubId = array_key_first($byHub);
        $hub = $municipalities->get($hubId);

        $bySchool = [];
        foreach ($allocations as $a) {
            $bySchool[$a['school_id']] ??= ['id' => $a['school_id'], 'display_name' => $a['display_name'], 'municipality_id' => $a['municipality_id'], 'hub' => $a['hub_municipality_id'], 'n' => 0];
            $bySchool[$a['school_id']]['n'] += $a['participant_count'];
        }
        $pick = function (callable $filter) use ($bySchool) {
            $list = array_values(array_filter($bySchool, $filter));
            usort($list, fn ($a, $b) => [-$a['n'], $a['id']] <=> [-$b['n'], $b['id']]);

            return $list[0] ?? null;
        };
        $school = $pick(fn ($s) => $s['municipality_id'] === $hubId && $s['n'] > 0) ?? $pick(fn ($s) => $s['hub'] === $hubId && $s['n'] > 0);

        return [
            $hub ? ['id' => $hub->id, 'name' => $hub->name] : null,
            $school ? ['id' => $school['id'], 'display_name' => $school['display_name'], 'municipality_id' => $school['municipality_id']] : null,
        ];
    }

    private function groupOut(array $group, Collection $zones): array
    {
        $zone = $zones->get($group['zone_id']);
        $warnings = [];
        if ($group['total'] < $this->min) {
            $warnings[] = ['code' => 'undersized', 'message' => "Групата има {$group['total']} учесници, под минимумот {$this->min}."];
        } elseif ($group['total'] > $this->max) {
            $warnings[] = ['code' => 'oversized', 'message' => "Групата има {$group['total']} учесници, над максимумот {$this->max}."];
        }

        return [
            'id' => $group['id'] ?? null,
            'number' => $group['number'],
            'zone_id' => $group['zone_id'],
            'zone' => $zone ? ['id' => $zone->id, 'name' => $zone->name] : null,
            'merged_zone_ids' => array_values(array_diff($group['zone_ids'], [$group['zone_id']])),
            'language' => $group['language'],
            'total' => $group['total'],
            'undersized' => $group['total'] < $this->min,
            'oversized' => $group['total'] > $this->max,
            'warnings' => $warnings,
            'allocations' => $group['allocations'],
            'venue_municipality_suggestion' => $group['venue_municipality_suggestion'],
            'venue_school_suggestion' => $group['venue_school_suggestion'],
        ];
    }

    private function save(EduProgram $program, array &$groups, array $keptIds): void
    {
        DB::transaction(function () use ($program, &$groups, $keptIds) {
            $old = EduGroup::where('program_id', $program->id)->whereNotIn('id', $keptIds)->pluck('id')->all();
            if ($old) {
                EduParticipant::whereIn('group_id', $old)->update(['group_id' => null]);
                EduGroup::whereIn('id', $old)->delete();
            }
            $now = now();
            foreach ($groups as &$group) {
                $model = EduGroup::create([
                    'program_id' => $program->id,
                    'number' => $group['number'],
                    'zone_id' => $group['zone_id'],
                    'language' => $group['language'],
                    'venue_municipality_id' => $group['venue_municipality_suggestion']['id'] ?? null,
                    'status' => 'draft',
                ]);
                $group['id'] = $model->id;
                EduGroupSchool::insert(array_map(fn ($a, $i) => [
                    'group_id' => $model->id,
                    'school_id' => $a['school_id'],
                    'language' => $a['language'],
                    'participant_count' => $a['participant_count'],
                    'sort_order' => $i + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $group['allocations'], array_keys($group['allocations'])));
                if ($program->isPersons()) {
                    foreach ($group['allocations'] as $a) {
                        $this->counts->movePersons($program, $a['school_id'], $a['language'], $a['participant_count'], null, $model->id);
                    }
                }
            }
            unset($group);
            if ($program->isPersons()) {
                $this->counts->syncAllocations($program, array_column($groups, 'id'));
            }
        });
    }

    private static function langOrder(string $language): int
    {
        $i = array_search($language, EduLanguage::CODES, true);

        return $i === false ? 9 : $i;
    }
}
