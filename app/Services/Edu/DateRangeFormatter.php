<?php

namespace App\Services\Edu;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Обуки: session dates and times as the client writes them in the
 * "Динамика" sheet and on the group documents.
 *
 *   dates(['2026-06-01', '2026-06-02'])     "01-02.06.2026"
 *   dates(['2026-06-30', '2026-07-01'])     "30.06-01.07.2026"
 *   dates(['2026-12-31', '2027-01-01'])     "31.12.2026-01.01.2027"
 *   dates(['2026-06-03', '2026-06-05'])     "03, 05.06.2026"
 *   dates(['2026-06-03'])                   "03.06.2026"
 *   time('08:30', '13:30', 2)               "8.30-13.30 (два дена)"
 *
 * Pure functions on Y-m-d / H:i strings, so it works without the models.
 */
final class DateRangeFormatter
{
    /** Day counts in words, as in "(два дена)". */
    private const DAY_WORDS = [
        1 => 'еден ден',
        2 => 'два дена',
        3 => 'три дена',
        4 => 'четири дена',
        5 => 'пет дена',
        6 => 'шест дена',
        7 => 'седум дена',
        8 => 'осум дена',
        9 => 'девет дена',
        10 => 'десет дена',
    ];

    /**
     * Runs of consecutive calendar days become "dd-dd", other days are
     * separated by ", ". The month and year are written once, after the last
     * day that shares them. Input order and duplicates do not matter; empty
     * values are skipped.
     *
     * @param  iterable<int, string|DateTimeInterface|null>  $dates  Y-m-d (a time part is ignored)
     */
    public static function dates(iterable $dates): string
    {
        $days = self::parseDates($dates);
        if ($days === []) {
            return '';
        }

        // Runs of consecutive days: [[start, end], ...]
        $runs = [];
        foreach ($days as $day) {
            $last = array_key_last($runs);
            if ($last !== null && $runs[$last][1]->modify('+1 day')->format('Y-m-d') === $day->format('Y-m-d')) {
                $runs[$last][1] = $day;
            } else {
                $runs[] = [$day, $day];
            }
        }

        // Endpoints in print order, each with the separator written before it.
        $points = [];
        foreach ($runs as $i => [$start, $end]) {
            $points[] = [$start, $i === 0 ? '' : ', '];
            if ($end != $start) {
                $points[] = [$end, '-'];
            }
        }

        $out = '';
        foreach ($points as $i => [$day, $separator]) {
            $next = $points[$i + 1][0] ?? null;
            $text = $day->format('d');
            if ($next === null || $next->format('Y-m') !== $day->format('Y-m')) {
                $text .= '.' . $day->format('m');
            }
            if ($next === null || $next->format('Y') !== $day->format('Y')) {
                $text .= '.' . $day->format('Y');
            }
            $out .= $separator . $text;
        }

        return $out;
    }

    /** "2026-06-01" → "01.06" (e.g. a "Потпис 01.06" column). */
    public static function dayMonth(string|DateTimeInterface $date): string
    {
        return self::parseDate($date)->format('d.m');
    }

    /** "2026-06-01" → "01.06.2026". */
    public static function date(string|DateTimeInterface $date): string
    {
        return self::parseDate($date)->format('d.m.Y');
    }

    /**
     * "08:30", "13:30" → "8.30-13.30". Accepts H:i, H:i:s or H.i; a missing
     * end gives only the start, a missing start gives "".
     */
    public static function timeRange(?string $start, ?string $end): string
    {
        $from = self::formatClock($start);
        if ($from === '') {
            return '';
        }
        $to = self::formatClock($end);

        return $to === '' ? $from : "{$from}-{$to}";
    }

    /** "8.30-13.30 (два дена)"; without a time only "(два дена)". */
    public static function time(?string $start, ?string $end, int $days): string
    {
        return self::withDays(self::timeRange($start, $end), $days);
    }

    /** 1 → "еден ден", 2 → "два дена" … 10 → "десет дена", 21 → "21 ден", 12 → "12 дена"; 0 → "". */
    public static function dayCount(int $days): string
    {
        if ($days <= 0) {
            return '';
        }
        if (isset(self::DAY_WORDS[$days])) {
            return self::DAY_WORDS[$days];
        }

        return $days . ($days % 10 === 1 && $days % 100 !== 11 ? ' ден' : ' дена');
    }

    /**
     * Both columns for a group's sessions. Days are the distinct dates. When
     * the sessions have different times, each distinct range is listed in
     * date order: "8.30-13.30, 9.00-12.00 (два дена)".
     *
     * @param  iterable<int, array{date?: string|DateTimeInterface|null, start_time?: string|null, end_time?: string|null}>  $sessions
     * @return array{dates: string, time: string, days: int}
     */
    public static function sessions(iterable $sessions): array
    {
        $rows = [];
        foreach ($sessions as $session) {
            $date = $session['date'] ?? null;
            if ($date === null || $date === '') {
                continue;
            }
            $rows[] = [
                'date' => self::parseDate($date)->format('Y-m-d'),
                'range' => self::timeRange($session['start_time'] ?? null, $session['end_time'] ?? null),
            ];
        }
        usort($rows, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $dates = array_values(array_unique(array_column($rows, 'date')));
        $ranges = array_values(array_unique(array_filter(array_column($rows, 'range'), fn ($r) => $r !== '')));

        return [
            'dates' => self::dates($dates),
            'time' => self::withDays(implode(', ', $ranges), count($dates)),
            'days' => count($dates),
        ];
    }

    private static function withDays(string $time, int $days): string
    {
        $words = self::dayCount($days);
        if ($words === '') {
            return $time;
        }

        return trim("{$time} ({$words})");
    }

    /** "08:30" / "8:30:00" / "8.30" → "8.30"; anything else → "". */
    private static function formatClock(?string $time): string
    {
        if ($time === null || !preg_match('/^\s*(\d{1,2})[:.](\d{2})/', $time, $m)) {
            return '';
        }

        return ((int) $m[1]) . '.' . $m[2];
    }

    /**
     * @param  iterable<int, string|DateTimeInterface|null>  $dates
     * @return list<DateTimeImmutable> sorted, unique
     */
    private static function parseDates(iterable $dates): array
    {
        $byKey = [];
        foreach ($dates as $date) {
            if ($date === null || $date === '') {
                continue;
            }
            $day = self::parseDate($date);
            $byKey[$day->format('Y-m-d')] = $day;
        }
        ksort($byKey);

        return array_values($byKey);
    }

    private static function parseDate(string|DateTimeInterface $date): DateTimeImmutable
    {
        if ($date instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', $date->format('Y-m-d'));
        }
        $value = substr(trim($date), 0, 10);
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid date: {$date}");
        }

        return $day;
    }
}
