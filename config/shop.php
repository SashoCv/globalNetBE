<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Associations (Здруженија)
    |--------------------------------------------------------------------------
    |
    | A clinic picks the association it is a member of when it registers. The
    | choice is required once this list has at least one name.
    |
    */

    'associations' => [
    ],

    /*
    |--------------------------------------------------------------------------
    | Urgent orders (Итни нарачки)
    |--------------------------------------------------------------------------
    |
    | Paid up front on their own invoice. Once paid they earn starter credit
    | for the next order, like the weekly ones.
    |
    */

    // Seller details and wording printed on every invoice.
    'invoice' => [
        'number_prefix' => 'ЕШ',
        'default_vat_rate' => 18,
        // Urgent delivery surcharge is a service taxed at the general rate.
        'surcharge_vat_rate' => 18,
        'complaint_days' => 8,
        'company' => [
            'name' => 'ГНА Е-ШОП',
            'legal_form' => 'ДООЕЛ, Скопје',
            'address' => 'ул. Мексичка 13 Б-1, 1000 Скопје, Карпош',
            'edb' => '4057026581857',
            'bank_account' => '240-0501227772-43',
            'bank' => 'Уни Банка АД Скопје',
            'phone' => '02/322-41-42 • 070/220-070',
            'website' => 'www.gnaeshop.mk',
            'email' => 'eshop@gnaeshop.mk',
            'signatory' => 'Марјан Димески',
        ],
    ],

    'urgent' => [
        'model_code' => 'urgent',
        'loyalty_percent' => 5,
    ],

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


    /*
    |--------------------------------------------------------------------------
    | Multi-month orders (Повеќемесечни збирни нарачки)
    |--------------------------------------------------------------------------
    |
    | The clinic orders once for a number of months. Every month, at the
    | monthly reservation cutoff, the same products are ordered again and go on
    | that month's invoice. The price never rises above the one at ordering.
    |
    */

    'recurring' => [
        'model_code' => 'quarterly',
        'month_options' => [3, 6, 9, 12],
        // The monthly reservation cutoff: the 19th at 15:00, Skopje time.
        'cutoff_day' => 19,
        'cutoff_time' => '15:00',
    ],

];
