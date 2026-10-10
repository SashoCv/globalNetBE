<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Weekly group orders (Неделни збирни нарачки)
    |--------------------------------------------------------------------------
    |
    | One cycle per week. Times are wall-clock times in the given timezone;
    | each cycle stores its own deadlines as UTC timestamps when it is created,
    | so changing these values only affects cycles created afterwards.
    |
    | Days use ISO numbering: 1 = Monday … 7 = Sunday.
    |
    */

    'weekly' => [
        'model_code' => 'weekly',
        'timezone' => env('SHOP_WEEKLY_TIMEZONE', 'Europe/Skopje'),

        // Orders and reservations are taken until Monday 12:00.
        'cutoff_day' => 1,
        'cutoff_time' => '12:00',

        // The group discount is approved by Tuesday 08:00. If nobody approves
        // it by then, payment opens with no extra discount.
        'discount_day' => 2,
        'discount_time' => '08:00',

        // Electronic payment only, by Tuesday 14:00. Clinics that still owe get
        // a reminder at 12:00.
        'payment_day' => 2,
        'payment_time' => '14:00',
        'reminder_time' => '12:00',

        // Delivery from Wednesday, within 5 working days (Mon–Fri).
        'delivery_day' => 3,
        'delivery_working_days' => 5,

        // The admin enters the extra group discount by hand, 0–20%.
        'max_extra_discount_percent' => 20,

        // A payment already handed to cPay when the deadline hits gets this
        // long to come back before its order is cancelled.
        'payment_grace_minutes' => 30,

        // Starter credit for the next order, earned once the order is paid.
        'loyalty_percent' => 5,
    ],

];
