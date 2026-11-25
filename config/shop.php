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
    | Non-working days (Article 7, paragraph 2)
    |--------------------------------------------------------------------------
    |
    | Public holidays as Y-m-d. A deadline on a weekend or on one of these days
    | moves to the next working day. A holiday that falls on a Sunday makes the
    | Monday after it non-working too: list that Monday.
    |
    | Extend every year. Eid al-Fitr 2027 is not listed until it is announced.
    |
    */

    'holidays' => [
        '2026-12-08', // Св. Климент Охридски
        '2027-01-01', // Нова година
        '2027-01-07', // Божиќ
        '2027-05-03', // Велигден (понеделник)
        '2027-05-01', // Ден на трудот
        '2027-05-24', // Св. Кирил и Методиј
        '2027-08-02', // Ден на Републиката
        '2027-09-08', // Ден на независноста
        '2027-10-11', // Ден на народното востание
        '2027-10-23', // Ден на македонската револуционерна борба
        '2027-12-08', // Св. Климент Охридски
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

        // Vendors send their pro-invoices by Tuesday 09:00; the group discount
        // is approved by 10:00. If nobody approves it by then, payment opens
        // with no extra discount.
        'proforma_time' => '09:00',
        'discount_day' => 2,
        'discount_time' => '10:00',

        // Electronic payment only, by Tuesday 14:00. Clinics that still owe get
        // a reminder at 12:00.
        'payment_day' => 2,
        'payment_time' => '14:00',
        'reminder_time' => '12:00',

        // Delivery from Wednesday, within 5 working days (Mon–Fri).
        'delivery_day' => 3,
        'delivery_working_days' => 5,

        // Vendors are paid for the paid orders by 13:00 on the day delivery starts.
        'vendor_payment_time' => '13:00',

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
    | The clinic orders once for a number of months. Every month, once the
    | monthly reservations close, the same products are ordered again and go on
    | that month's invoice. The price never rises above the one at ordering.
    |
    */

    'recurring' => [
        'model_code' => 'quarterly',
        'month_options' => [3, 6, 9, 12],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monthly group orders (Месечни збирни нарачки)
    |--------------------------------------------------------------------------
    |
    | Days of the month, Skopje time, before any shift for non-working days.
    | Multi-month orders follow the same deadlines.
    |
    */

    'monthly' => [
        'model_code' => 'monthly',
        // Reservations are taken to the end of the 19th.
        'cutoff_day' => 19,
        // One order per vendor: sent in the morning, due by 15:00 on the 20th.
        'consolidate_day' => 20,
        'consolidate_from' => '09:00',
        'consolidate_time' => '15:00',
        // The vendor's pro-invoice is in by the 21st 12:00; ours goes out then.
        'proforma_day' => 21,
        'proforma_time' => '12:00',
        // Clinics pay to the 25th and are reminded a working day earlier.
        'payment_day' => 25,
        'reminder_time' => '09:00',
        // Vendors are paid for the paid orders by the 26th 12:00.
        'vendor_payment_day' => 26,
        'vendor_payment_time' => '12:00',
        'delivery_working_days' => 7,
        // Starter credit for the next order, earned once the invoice is paid.
        'loyalty_percent' => 5,
    ],

];
