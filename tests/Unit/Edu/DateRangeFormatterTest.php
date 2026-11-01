<?php

namespace Tests\Unit\Edu;

use App\Services\Edu\DateRangeFormatter;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DateRangeFormatterTest extends TestCase
{
    public static function dateCases(): array
    {
        return [
            'empty' => [[], ''],
            'single day' => [['2026-06-03'], '03.06.2026'],
            'two consecutive days' => [['2026-06-01', '2026-06-02'], '01-02.06.2026'],
            'three consecutive days' => [['2026-05-27', '2026-05-28', '2026-05-29'], '27-29.05.2026'],
            'month crossing' => [['2026-06-30', '2026-07-01'], '30.06-01.07.2026'],
            'year crossing' => [['2026-12-31', '2027-01-01'], '31.12.2026-01.01.2027'],
            'non-consecutive same month' => [['2026-06-03', '2026-06-05'], '03, 05.06.2026'],
            'non-consecutive across months' => [['2026-05-29', '2026-06-02', '2026-06-03'], '29.05, 02-03.06.2026'],
            'range then single in next month' => [['2026-06-01', '2026-06-02', '2026-07-03'], '01-02.06, 03.07.2026'],
            'non-consecutive across years' => [['2026-12-30', '2027-01-04'], '30.12.2026, 04.01.2027'],
            'weekend gap is not a range' => [['2026-06-05', '2026-06-08'], '05, 08.06.2026'],
            'unsorted with duplicates and blanks' => [['2026-06-02', null, '2026-06-01', '', '2026-06-02'], '01-02.06.2026'],
            'datetime strings' => [['2026-06-01 00:00:00', '2026-06-02T00:00:00'], '01-02.06.2026'],
            'leap day range' => [['2028-02-28', '2028-02-29', '2028-03-01'], '28.02-01.03.2028'],
        ];
    }

    #[DataProvider('dateCases')]
    public function test_dates(array $dates, string $expected): void
    {
        $this->assertSame($expected, DateRangeFormatter::dates($dates));
    }

    public function test_dates_accepts_date_objects(): void
    {
        $this->assertSame('01-02.06.2026', DateRangeFormatter::dates([
            new DateTimeImmutable('2026-06-02 10:00'),
            new DateTimeImmutable('2026-06-01'),
        ]));
    }

    public function test_invalid_date_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DateRangeFormatter::dates(['2026-02-30']);
    }

    public static function timeCases(): array
    {
        return [
            'two days' => ['08:30', '13:30', 2, '8.30-13.30 (два дена)'],
            'with seconds' => ['08:00:00', '13:00:00', 2, '8.00-13.00 (два дена)'],
            'one day' => ['09:00', '15:30', 1, '9.00-15.30 (еден ден)'],
            'three days' => ['10:00', '14:00', 3, '10.00-14.00 (три дена)'],
            'dotted input' => ['8.30', '13.30', 2, '8.30-13.30 (два дена)'],
            'no days' => ['08:30', '13:30', 0, '8.30-13.30'],
            'no end' => ['08:30', null, 2, '8.30 (два дена)'],
            'no time' => [null, null, 2, '(два дена)'],
            'nothing' => [null, null, 0, ''],
        ];
    }

    #[DataProvider('timeCases')]
    public function test_time(?string $start, ?string $end, int $days, string $expected): void
    {
        $this->assertSame($expected, DateRangeFormatter::time($start, $end, $days));
    }

    public static function dayCountCases(): array
    {
        return [
            [0, ''],
            [1, 'еден ден'],
            [2, 'два дена'],
            [4, 'четири дена'],
            [5, 'пет дена'],
            [10, 'десет дена'],
            [11, '11 дена'],
            [12, '12 дена'],
            [21, '21 ден'],
            [111, '111 дена'],
        ];
    }

    #[DataProvider('dayCountCases')]
    public function test_day_count(int $days, string $expected): void
    {
        $this->assertSame($expected, DateRangeFormatter::dayCount($days));
    }

    public function test_sessions_same_time(): void
    {
        $this->assertSame([
            'dates' => '01-02.06.2026',
            'time' => '8.30-13.30 (два дена)',
            'days' => 2,
        ], DateRangeFormatter::sessions([
            ['date' => '2026-06-02', 'start_time' => '08:30', 'end_time' => '13:30'],
            ['date' => '2026-06-01', 'start_time' => '08:30:00', 'end_time' => '13:30:00'],
        ]));
    }

    public function test_sessions_different_times_and_skipped_dates(): void
    {
        $this->assertSame([
            'dates' => '30.06-01.07.2026',
            'time' => '8.30-13.30, 9.00-12.00 (два дена)',
            'days' => 2,
        ], DateRangeFormatter::sessions([
            ['date' => '2026-07-01', 'start_time' => '09:00', 'end_time' => '12:00'],
            ['date' => null, 'start_time' => '10:00', 'end_time' => '11:00'],
            ['date' => '2026-06-30', 'start_time' => '08:30', 'end_time' => '13:30'],
        ]));
    }

    public function test_sessions_empty(): void
    {
        $this->assertSame(['dates' => '', 'time' => '', 'days' => 0], DateRangeFormatter::sessions([]));
    }

    public function test_short_dates(): void
    {
        $this->assertSame('01.06', DateRangeFormatter::dayMonth('2026-06-01'));
        $this->assertSame('01.06.2026', DateRangeFormatter::date('2026-06-01'));
        $this->assertSame('8.30-13.30', DateRangeFormatter::timeRange('08:30', '13:30'));
        $this->assertSame('', DateRangeFormatter::timeRange(null, '13:30'));
    }
}
