<?php

namespace App\Services\Edu;

use App\Models\EduGroup;
use App\Models\EduProgram;
use App\Models\EduProgramSchool;
use App\Models\EduProgramZone;
use App\Models\EduProgramZoneMunicipality as Member;
use App\Models\EduRegion;
use App\Support\Edu\EduLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Обуки: the grouping zones of a program.
 *
 * A new program gets one zone per region with every municipality of the
 * region for all languages ('*'). Per program a municipality can then move
 * to another zone for every language or only for one (an exception row),
 * zones can be merged, created and renamed. The global regions never
 * change because of a program.
 *
 * Lookup for a participant of language L grouped in language G:
 * the exception for L, then the exception for G, then '*'.
 */
class ProgramZoneService
{
    /** One zone per region (regions without municipalities are skipped). */
    public function initFromRegions(EduProgram $program): void
    {
        DB::transaction(function () use ($program) {
            $regions = EduRegion::query()->with('municipalities:id,region_id')->orderBy('sort_order')->orderBy('name')->get();
            $order = (int) EduProgramZone::where('program_id', $program->id)->max('sort_order');
            $now = now();
            foreach ($regions as $region) {
                if ($region->municipalities->isEmpty()) {
                    continue;
                }
                $zone = EduProgramZone::create([
                    'program_id' => $program->id,
                    'region_id' => $region->id,
                    'name' => $region->name,
                    'sort_order' => ++$order,
                ]);
                Member::insert($region->municipalities->map(fn ($m) => [
                    'program_id' => $program->id,
                    'zone_id' => $zone->id,
                    'municipality_id' => $m->id,
                    'language' => Member::ALL,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            }
        });
    }

    /**
     * Back to one zone per region. Groups keep pointing at the zone of the
     * region their old zone came from (or none).
     */
    public function reset(EduProgram $program): void
    {
        DB::transaction(function () use ($program) {
            $oldRegion = EduProgramZone::where('program_id', $program->id)->pluck('region_id', 'id');
            $groups = EduGroup::where('program_id', $program->id)->whereNotNull('zone_id')->get(['id', 'zone_id']);

            Member::where('program_id', $program->id)->delete();
            EduProgramZone::where('program_id', $program->id)->delete();
            $this->initFromRegions($program);

            $byRegion = EduProgramZone::where('program_id', $program->id)->pluck('id', 'region_id');
            foreach ($groups as $group) {
                $regionId = $oldRegion[$group->zone_id] ?? null;
                EduGroup::whereKey($group->id)->update(['zone_id' => $regionId ? ($byRegion[$regionId] ?? null) : null]);
            }
        });
    }

    /**
     * municipality id => [language|'*' => zone id].
     *
     * @return array<int, array<string, int>>
     */
    public function map(EduProgram $program): array
    {
        $map = [];
        foreach (Member::where('program_id', $program->id)->get(['municipality_id', 'zone_id', 'language']) as $row) {
            $map[$row->municipality_id][$row->language] = $row->zone_id;
        }

        return $map;
    }

    /** The zone of a municipality for a participant language (and grouping language). */
    public static function lookup(array $map, int $municipalityId, ?string $language, ?string $groupLanguage = null): ?int
    {
        $row = $map[$municipalityId] ?? [];

        return ($language ? ($row[$language] ?? null) : null)
            ?? ($groupLanguage ? ($row[$groupLanguage] ?? null) : null)
            ?? ($row[Member::ALL] ?? null);
    }

    /**
     * Moves a municipality to a zone, for every language ('*') or only for
     * one. An exception equal to the default zone is dropped.
     *
     * @return array{from_zone_id: int|null, to_zone_id: int}
     */
    public function assign(EduProgram $program, int $municipalityId, EduProgramZone $zone, string $language = Member::ALL): array
    {
        return DB::transaction(function () use ($program, $municipalityId, $zone, $language) {
            $rows = Member::where('program_id', $program->id)->where('municipality_id', $municipalityId)->get()->keyBy('language');
            $default = $rows->get(Member::ALL);

            if ($language === Member::ALL) {
                $from = $default?->zone_id;
                if ($default) {
                    $default->update(['zone_id' => $zone->id]);
                } else {
                    Member::create(['program_id' => $program->id, 'zone_id' => $zone->id, 'municipality_id' => $municipalityId, 'language' => Member::ALL]);
                }
            } else {
                $exception = $rows->get($language);
                $from = $exception?->zone_id ?? $default?->zone_id;
                if ($default && $default->zone_id === $zone->id) {
                    $exception?->delete();
                } elseif ($exception) {
                    $exception->update(['zone_id' => $zone->id]);
                } else {
                    Member::create(['program_id' => $program->id, 'zone_id' => $zone->id, 'municipality_id' => $municipalityId, 'language' => $language]);
                }
            }
            $this->dropRedundant($program, [$municipalityId]);

            return ['from_zone_id' => $from, 'to_zone_id' => $zone->id];
        });
    }

    /** A new zone, optionally with municipalities moved into it. */
    public function store(EduProgram $program, string $name, array $municipalityIds = [], string $language = Member::ALL): EduProgramZone
    {
        return DB::transaction(function () use ($program, $name, $municipalityIds, $language) {
            $zone = EduProgramZone::create([
                'program_id' => $program->id,
                'name' => $name,
                'sort_order' => (int) EduProgramZone::where('program_id', $program->id)->max('sort_order') + 1,
            ]);
            foreach (array_unique($municipalityIds) as $municipalityId) {
                $this->assign($program, (int) $municipalityId, $zone, $language);
            }

            return $zone;
        });
    }

    /** Moves every municipality and group of $from into $into and deletes $from. */
    public function merge(EduProgramZone $from, EduProgramZone $into): EduProgramZone
    {
        return DB::transaction(function () use ($from, $into) {
            $municipalityIds = Member::where('zone_id', $from->id)->pluck('municipality_id')->unique()->all();
            Member::where('zone_id', $from->id)->update(['zone_id' => $into->id]);
            EduGroup::where('zone_id', $from->id)->update(['zone_id' => $into->id]);
            $from->delete();
            $this->dropRedundant($into->program, $municipalityIds);

            return $into->fresh();
        });
    }

    /** Language exceptions pointing at the same zone as '*' say nothing. */
    private function dropRedundant(EduProgram $program, array $municipalityIds): void
    {
        $rows = Member::where('program_id', $program->id)->whereIn('municipality_id', $municipalityIds)->get()->groupBy('municipality_id');
        foreach ($rows as $memberRows) {
            $default = $memberRows->firstWhere('language', Member::ALL);
            if (!$default) {
                continue;
            }
            foreach ($memberRows as $row) {
                if ($row->language !== Member::ALL && $row->zone_id === $default->zone_id) {
                    $row->delete();
                }
            }
        }
    }

    /**
     * Zones with their municipalities and the participants they hold
     * (each school's demand goes to the zone it resolves to for its language).
     *
     * @return Collection<int, array>
     */
    public function overview(EduProgram $program): Collection
    {
        $zones = EduProgramZone::where('program_id', $program->id)->orderBy('sort_order')->orderBy('id')->get();
        $members = Member::where('program_id', $program->id)
            ->with('municipality:id,name,region_id,hub_municipality_id,is_training_hub')
            ->get();
        $map = $this->map($program);

        // Demand per municipality and participant language.
        $demand = [];
        $rows = EduProgramSchool::query()
            ->join('edu_schools', 'edu_schools.id', '=', 'edu_program_schools.school_id')
            ->where('edu_program_schools.program_id', $program->id)
            ->groupBy('edu_schools.municipality_id')
            ->selectRaw('edu_schools.municipality_id as municipality_id, SUM(count_mk) as mk, SUM(count_sq) as sq, SUM(count_tr) as tr')
            ->get();
        foreach ($rows as $row) {
            foreach (EduLanguage::CODES as $lang) {
                $demand[(int) $row->municipality_id][$lang] = (int) $row->{$lang};
            }
        }

        // Which member row (municipality + '*' or language) takes each language's demand.
        $rowDemand = [];
        foreach ($demand as $municipalityId => $byLanguage) {
            foreach ($byLanguage as $lang => $n) {
                if ($n <= 0) {
                    continue;
                }
                $groupLanguage = $program->groupLanguage($lang);
                $row = $map[$municipalityId] ?? [];
                $key = isset($row[$lang]) ? $lang : (isset($row[$groupLanguage]) ? $groupLanguage : (isset($row[Member::ALL]) ? Member::ALL : null));
                if ($key !== null) {
                    $rowDemand[$municipalityId][$key][$lang] = ($rowDemand[$municipalityId][$key][$lang] ?? 0) + $n;
                }
            }
        }

        $byZone = $members->groupBy('zone_id');

        return $zones->map(function (EduProgramZone $zone) use ($byZone, $rowDemand) {
            $totals = ['mk' => 0, 'sq' => 0, 'tr' => 0, 'total' => 0];
            $municipalities = collect($byZone->get($zone->id, []))
                ->sortBy(fn ($m) => [$m->municipality?->name, $m->language])
                ->map(function (Member $m) use ($rowDemand, &$totals) {
                    $d = ['mk' => 0, 'sq' => 0, 'tr' => 0];
                    foreach ($rowDemand[$m->municipality_id][$m->language] ?? [] as $lang => $n) {
                        $d[$lang] = $n;
                        $totals[$lang] += $n;
                        $totals['total'] += $n;
                    }

                    return [
                        'id' => $m->municipality_id,
                        'name' => $m->municipality?->name,
                        'language' => $m->language,
                        'region_id' => $m->municipality?->region_id,
                        'hub_municipality_id' => $m->municipality?->hub_municipality_id,
                        'is_training_hub' => (bool) $m->municipality?->is_training_hub,
                        'demand' => $d,
                    ];
                })->values();

            return [
                'id' => $zone->id,
                'name' => $zone->name,
                'region_id' => $zone->region_id,
                'sort_order' => $zone->sort_order,
                'municipalities' => $municipalities,
                'totals' => $totals,
            ];
        })->values();
    }
}
