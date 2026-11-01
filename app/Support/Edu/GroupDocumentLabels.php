<?php

namespace App\Support\Edu;

/**
 * Обуки: the labels of the printed group documents (PDF and Word), from
 * config('edu.document_labels'). Albanian groups get bilingual labels,
 * "Обучувач / Trajner" (decision 4); values (names, schools) never change.
 */
final class GroupDocumentLabels
{
    /** Bilingual unless overridden: auto (null) = the group is Albanian. */
    public static function bilingual(?string $language, ?bool $override = null): bool
    {
        return $override ?? $language === 'sq';
    }

    /**
     * Every label as printed: "Обучувач" or "Обучувач / Trajner". ":n" is
     * left in "group" and "day" (see self::number()); "staff" and
     * "languages" are maps by staff type / language code.
     *
     * @return array<string, string|array<string, string>>
     */
    public static function make(bool $bilingual): array
    {
        $config = (array) config('edu.document_labels', []);
        $mk = (array) ($config['mk'] ?? []);
        if (!$bilingual) {
            return $mk;
        }
        $sq = (array) ($config['sq'] ?? []);
        $special = (array) ($config['mk_sq'] ?? []);
        $separator = (string) ($config['separator'] ?? ' / ');

        $labels = [];
        foreach ($mk as $key => $value) {
            if (is_array($value)) {
                $labels[$key] = [];
                foreach ($value as $k => $v) {
                    $labels[$key][$k] = self::join($v, $sq[$key][$k] ?? null, $separator);
                }
            } else {
                $labels[$key] = $special[$key] ?? self::join($value, $sq[$key] ?? null, $separator);
            }
        }

        return $labels;
    }

    /** "Група :n / Grupi :n" → "Група 3 / Grupi 3". */
    public static function number(string $label, int|string $n): string
    {
        return str_replace(':n', (string) $n, $label);
    }

    private static function join(string $mk, ?string $sq, string $separator): string
    {
        return $sq === null || $sq === '' || $sq === $mk ? $mk : $mk . $separator . $sq;
    }
}
