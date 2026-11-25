<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Working days for the e-shop deadlines (Article 7, paragraph 2): weekends and
 * the holidays in config('shop.holidays') are not working days. Dates are
 * local (Skopje) dates; the time of day is kept.
 */
final class WorkingDays
{
    public static function isWorking(Carbon $day): bool
    {
        return ! $day->isWeekend()
            && ! in_array($day->toDateString(), config('shop.holidays', []), true);
    }

    /** $day itself, or the first working day after it. */
    public static function onOrAfter(Carbon $day): Carbon
    {
        $d = $day->copy();
        while (! self::isWorking($d)) {
            $d->addDay();
        }

        return $d;
    }

    /** The first working day strictly after $day. */
    public static function after(Carbon $day): Carbon
    {
        return self::onOrAfter($day->copy()->addDay());
    }

    /**
     * A deadline that must come on a later day than the step before it: moved
     * to a working day, and past $previous when a shift made them collide.
     */
    public static function following(Carbon $day, Carbon $previous): Carbon
    {
        $d = self::onOrAfter($day);

        if ($d->toDateString() > $previous->toDateString()) {
            return $d;
        }

        return self::after($previous)->setTime($d->hour, $d->minute, $d->second);
    }

    public static function add(Carbon $day, int $days): Carbon
    {
        $d = $day->copy();
        for ($i = 0; $i < $days; $i++) {
            $d = self::after($d);
        }

        return $d;
    }
}
