<?php

namespace App\Services\Edu;

use App\Models\EduSchool;
use Illuminate\Support\Collection;

/**
 * Finds the school a free-text name means (participant lists, per-school
 * counts, re-imports).
 *
 * Tries, in order:
 *  1. the exact name_key in the municipality;
 *  2. the key without the village in the municipality, when only one school has it;
 *  3. the exact name_key anywhere in the level, when only one school has it;
 *  4. fuzzy on the ASCII key (normalised Levenshtein ≥ threshold), within the
 *     municipality when it is known.
 *
 * Below the threshold the row needs a manual choice from `candidates`.
 */
class EduSchoolMatcher
{
    /** @var array<string, Collection<int, array{school: EduSchool, key: string, key_without: string, translit: string, translit_without: string, translit_sq: string}>> */
    private array $cache = [];

    public function __construct(private ?float $threshold = null)
    {
        $this->threshold ??= (float) config('edu.school_match_threshold', 0.85);
    }

    /** Forget the loaded schools (after an import wrote new ones). */
    public function refresh(): void
    {
        $this->cache = [];
    }

    /**
     * @return array{school_id: int|null, score: float, method: string|null, candidates: list<array{id: int, display_name: string, municipality_id: int, municipality: string|null, score: float}>}
     */
    public function match(?string $text, string $level, ?int $municipalityId = null, ?string $municipalityName = null): array
    {
        $result = ['school_id' => null, 'score' => 0.0, 'method' => null, 'candidates' => []];
        $display = EduText::clean($text);
        if ($display === '') {
            return $result;
        }
        [$name, $nameSq] = EduText::splitBilingual($display);
        $schools = $this->schools($level);
        if ($municipalityId && $municipalityName === null) {
            $municipalityName = \App\Models\EduMunicipality::query()->whereKey($municipalityId)->value('name');
        }

        $key = EduText::key($name, $municipalityName);
        $keyWithPrefix = EduText::keyWithPrefix($name, $municipalityName);
        $keyWithout = EduText::keyWithoutSettlement($name, $municipalityName);
        $inMunicipality = $municipalityId ? $schools->filter(fn ($s) => $s['school']->municipality_id === $municipalityId) : collect();

        // 1. exact key in the municipality (with the type prefix for schools
        //    that differ only by it, e.g. СОУ / СОМУ „Гостивар“)
        if ($municipalityId && ($hit = $inMunicipality->first(fn ($s) => $s['key'] === $key || $s['key'] === $keyWithPrefix))) {
            return $this->found($result, $hit, 1.0, 'exact', $inMunicipality, $key);
        }

        // 2. key without the village, only when unique
        if ($municipalityId && $keyWithout !== '') {
            $hits = $inMunicipality->filter(fn ($s) => $s['key_without'] === $keyWithout);
            if ($hits->count() === 1) {
                return $this->found($result, $hits->first(), 0.95, 'without_settlement', $inMunicipality, $key);
            }
        }

        // 3. exact key anywhere in the level, only when unique
        $hits = $schools->filter(fn ($s) => $s['key'] === $key || $s['key'] === $keyWithPrefix);
        if ($hits->count() === 1) {
            return $this->found($result, $hits->first(), 0.9, 'level_exact', $municipalityId ? $inMunicipality : $schools, $key);
        }

        // 4. fuzzy on the ASCII key
        $pool = $municipalityId ? $inMunicipality : $schools;
        $ranked = $this->rank($pool, $key, $nameSq);
        $result['candidates'] = $this->candidates($ranked);
        $best = $ranked->first();
        $second = $ranked->get(1);
        // Two schools about as close (e.g. the same name in two villages): let the admin pick.
        $ambiguous = $second && $best['score'] - $second['score'] < 0.02;
        if ($best && $best['score'] >= $this->threshold && !$ambiguous) {
            $result['school_id'] = $best['school']->id;
            $result['score'] = $best['score'];
            $result['method'] = 'fuzzy';
        } elseif ($best) {
            $result['score'] = $best['score'];
        }

        return $result;
    }

    private function found(array $result, array $hit, float $score, string $method, Collection $pool, string $key): array
    {
        $result['school_id'] = $hit['school']->id;
        $result['score'] = $score;
        $result['method'] = $method;
        $ranked = $this->rank($pool, $key, null);
        $result['candidates'] = $this->candidates($ranked);

        return $result;
    }

    /** @return Collection<int, array{school: EduSchool, score: float}> */
    private function rank(Collection $pool, string $key, ?string $nameSq): Collection
    {
        $translit = EduText::translitKey($key);
        $translitSq = $nameSq ? EduText::translitKey(EduText::key($nameSq)) : null;

        return $pool->map(function ($s) use ($translit, $translitSq) {
            $score = max(
                EduText::similarity($translit, $s['translit']),
                EduText::similarity($translit, $s['translit_without']),
                $s['translit_sq'] !== '' ? EduText::similarity($translit, $s['translit_sq']) : 0.0,
                $translitSq && $s['translit_sq'] !== '' ? EduText::similarity($translitSq, $s['translit_sq']) : 0.0,
            );

            return ['school' => $s['school'], 'score' => round($score, 3)];
        })->sortByDesc('score')->values();
    }

    private function candidates(Collection $ranked): array
    {
        return $ranked->take(3)->map(fn ($r) => [
            'id' => $r['school']->id,
            'display_name' => $r['school']->display_name,
            'municipality_id' => $r['school']->municipality_id,
            'municipality' => $r['school']->municipality?->name,
            'score' => $r['score'],
        ])->values()->all();
    }

    private function schools(string $level): Collection
    {
        return $this->cache[$level] ??= EduSchool::query()
            ->with('municipality:id,name')
            ->where('level', $level)
            ->orderBy('id')
            ->get()
            ->map(function (EduSchool $school) {
                $muni = $school->municipality?->name;
                $without = EduText::keyWithoutSettlement($school->name, $muni);

                return [
                    'school' => $school,
                    'key' => $school->name_key,
                    'key_without' => $without,
                    'translit' => EduText::translitKey($school->name_key),
                    'translit_without' => EduText::translitKey($without),
                    'translit_sq' => $school->name_sq ? EduText::translitKey(EduText::key($school->name_sq)) : '',
                ];
            });
    }
}
