<?php

namespace App\Services\Edu;

use App\Models\EduMunicipality;
use Illuminate\Support\Collection;

/**
 * Finds the municipality a cell in the client's sheets means.
 *
 * Tries, in order: the exact name (Macedonian or Albanian), the aliases and
 * matrix short name ("Куман."), the punctuation-free key ("Чешиново
 * Облешево"), and a dotted abbreviation ("Кавад."). A bilingual cell
 * ("Тетово/ Tetovë") is tried by its halves, and extra text after the name
 * ("Радовиш  Goce 070-790-497") is cut off and returned as the remainder.
 */
class EduMunicipalityResolver
{
    /** @var Collection<int, EduMunicipality>|null */
    private ?Collection $municipalities = null;

    /** @var array<string, array<string, list<int>>> lookup table => normalised text => ids */
    private array $index = [];

    /** Reloads the municipalities, e.g. after an import created new ones. */
    public function refresh(): void
    {
        $this->municipalities = EduMunicipality::query()->with('region:id,name')->orderBy('id')->get()->keyBy('id');
        $this->index = ['exact' => [], 'alias' => [], 'compact' => []];

        foreach ($this->municipalities as $m) {
            foreach (array_filter([$m->name, $m->name_sq]) as $name) {
                $this->add('exact', self::lower($name), $m->id);
                $this->add('compact', self::compact($name), $m->id);
            }
            foreach (array_filter(array_merge($m->aliases ?? [], [$m->hub_short_name])) as $alias) {
                $this->add('alias', self::lower($alias), $m->id);
                $this->add('compact', self::compact($alias), $m->id);
            }
        }
    }

    /** @return Collection<int, EduMunicipality> */
    public function all(): Collection
    {
        if ($this->municipalities === null) {
            $this->refresh();
        }

        return $this->municipalities;
    }

    public function find(?int $id): ?EduMunicipality
    {
        return $id ? $this->all()->get($id) : null;
    }

    /**
     * @return array{municipality: EduMunicipality|null, remainder: string|null, method: string|null}
     */
    public function resolve(?string $text): array
    {
        $none = ['municipality' => null, 'remainder' => null, 'method' => null];
        $text = EduText::squish($text);
        if ($text === '') {
            return $none;
        }
        $this->all();

        if ($hit = $this->tryText($text)) {
            return ['municipality' => $hit[0], 'remainder' => null, 'method' => $hit[1]];
        }

        // Extra text after the name: keep the longest leading run of words
        // that is a municipality, the rest goes to the comment.
        $words = explode(' ', $text);
        for ($n = count($words) - 1; $n >= 1; $n--) {
            $head = implode(' ', array_slice($words, 0, $n));
            if ($hit = $this->tryText($head)) {
                return [
                    'municipality' => $hit[0],
                    'remainder' => implode(' ', array_slice($words, $n)),
                    'method' => $hit[1] . '+truncated',
                ];
            }
        }

        return $none;
    }

    /** The training centre a trainer-matrix column header ("Куман.", "Гевгел") means. */
    public function hubFromHeader(?string $header): ?EduMunicipality
    {
        $header = EduText::squish($header);
        if ($header === '') {
            return null;
        }
        $hubs = $this->all()->filter(fn ($m) => $m->is_training_hub);
        $h = self::compact($header);

        foreach ($hubs as $hub) {
            $forms = array_filter(array_merge([$hub->name, $hub->name_sq, $hub->hub_short_name], $hub->aliases ?? []));
            foreach ($forms as $form) {
                if (self::compact($form) === $h) {
                    return $hub;
                }
            }
        }

        // "Гевгел" → Гевгелија: a unique centre whose name starts with the header.
        if (mb_strlen($h) >= 4) {
            $matches = $hubs->filter(fn ($hub) => str_starts_with(self::compact($hub->name), $h)
                || ($hub->hub_short_name && str_starts_with(self::compact($hub->hub_short_name), $h)));
            if ($matches->count() === 1) {
                return $matches->first();
            }
        }

        return null;
    }

    /** @return array{0: EduMunicipality, 1: string}|null */
    private function tryText(string $text): ?array
    {
        $candidates = [$text];
        if (str_contains($text, '/')) {
            [$mk, $sq] = EduText::splitBilingual($text, false);
            $candidates = array_merge($candidates, array_filter([$mk, $sq]));
            foreach (preg_split('~\s*/\s*~u', $text) ?: [] as $piece) {
                $candidates[] = $piece;
            }
        }

        foreach (array_unique(array_filter($candidates)) as $c) {
            foreach (['exact' => self::lower($c), 'alias' => self::lower($c), 'compact' => self::compact($c)] as $table => $needle) {
                $ids = $this->index[$table][$needle] ?? [];
                if (count($ids) === 1) {
                    return [$this->municipalities->get($ids[0]), $table];
                }
            }

            // "Кавад." / "Св.Никол" style abbreviations.
            $prefix = self::compact($c);
            if (str_ends_with($c, '.') && mb_strlen($prefix) >= 4) {
                $matches = $this->municipalities->filter(fn ($m) => str_starts_with(self::compact($m->name), $prefix));
                if ($matches->count() === 1) {
                    return [$matches->first(), 'abbreviation'];
                }
            }
        }

        return null;
    }

    private function add(string $table, string $key, int $id): void
    {
        if ($key === '') {
            return;
        }
        $ids = $this->index[$table][$key] ?? [];
        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }
        $this->index[$table][$key] = $ids;
    }

    private static function lower(string $s): string
    {
        return mb_strtolower(EduText::fixHomoglyphs(EduText::squish($s)));
    }

    /** Letters and digits only: "Чешиново-Облешево" = "Чешиново Облешево". */
    private static function compact(string $s): string
    {
        return str_replace(' ', '', EduText::words(EduText::fixHomoglyphs($s)));
    }
}
