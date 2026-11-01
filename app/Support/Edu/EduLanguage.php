<?php

namespace App\Support\Edu;

/**
 * Teaching languages in the Обуки module. Schools, trainers and participants
 * store these codes; an empty list means the language is not known yet. The
 * workbook import fills an empty school language cell with
 * edu.import.school_default_language (Macedonian) and gives trainers with a
 * Latin-script name Albanian; both only where the language is still unknown.
 */
final class EduLanguage
{
    public const CODES = ['mk', 'sq', 'tr'];

    /** Short labels used in tables and exports. */
    public const LABELS = ['mk' => 'мк', 'sq' => 'алб', 'tr' => 'тр'];

    /** Full names for pickers. */
    public const NAMES = ['mk' => 'Македонски', 'sq' => 'Албански', 'tr' => 'Турски'];

    /** Suffix after a count in exports, e.g. "(1+1т)". */
    public const SHORT = ['mk' => '', 'sq' => 'а', 'tr' => 'т'];

    public static function isValid(?string $code): bool
    {
        return $code !== null && in_array($code, self::CODES, true);
    }

    /**
     * Keeps the known codes, drops duplicates and sorts them in CODES order,
     * so the same set always compares equal.
     *
     * @param  array<int, mixed>|null  $codes
     * @return list<string>
     */
    public static function normalize(?array $codes): array
    {
        $codes = array_map(fn ($c) => is_string($c) ? strtolower(trim($c)) : $c, $codes ?? []);

        return array_values(array_filter(self::CODES, fn ($c) => in_array($c, $codes, true)));
    }

    /** @return list<array{code: string, label: string, name: string}> */
    public static function options(): array
    {
        return array_map(fn ($c) => [
            'code' => $c,
            'label' => self::LABELS[$c],
            'name' => self::NAMES[$c],
        ], self::CODES);
    }
}
