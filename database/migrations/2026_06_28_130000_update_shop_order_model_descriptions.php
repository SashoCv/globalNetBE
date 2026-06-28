<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('shop_order_models')->where('code', 'urgent')->update([
            'tagline'            => 'Итна нарачка за моментални потреби — достава во 72 часа / 3 работни дена.',
            'description'        => 'Нарачката се обработува веднаш и приоритетно. Плаќањето е задолжително онлине (виртуелен ПОС терминал). Доставата се врши во рок од 72 часа / 3 работни дена по потврдата на плаќањето.',
            'bullets'            => json_encode(['Приоритетна обработка', 'Достава во 72ч / 3 работни дена', 'Задолжително онлине плаќање', 'За неодложни материјали'], JSON_UNESCAPED_UNICODE),
            'max_delivery_hours' => 72,
            'cutoff_day'         => null,
            'cutoff_hour'        => null,
            'delivery_day'       => null,
        ]);

        DB::table('shop_order_models')->where('code', 'monthly')->update([
            'tagline'            => 'Резервирајте до 19-ти — про-фактура 21-ти, уплата до 25-ти, достава 25–30-ти.',
            'description'        => 'Резервирате материјали до 19-ти во месецот. Секоја недела ги проследуваме резервациите до добавувачите за подготовка. На 21-ти добивате про-фактура со дополнителен групен рабат (кредитиран во вашиот портфел). Уплата до 25-ти. Достава 25–30-ти.',
            'bullets'            => json_encode(['Резервирајте до 19-ти', 'Про-фактура на 21-ти со групен рабат', 'Уплата до 25-ти', 'Достава 25–30-ти'], JSON_UNESCAPED_UNICODE),
            'max_delivery_hours' => null,
            'cutoff_day'         => 19,
            'cutoff_hour'        => 17,
            'delivery_day'       => 30,
        ]);

        DB::table('shop_order_models')->where('code', 'quarterly')->update([
            'tagline'            => 'Тромесечна резервација — исти рокови, поголеми количини, поголем рабат.',
            'description'        => 'Резервирате материјали еднаш на три месеци — исти рокови: резервирајте до 19-ти, про-фактура 21-ти, уплата до 25-ти, достава 25–30-ти. Поради поголеми количини добивате подобри цени и поголем рабат во портфел.',
            'bullets'            => json_encode(['Резервирајте до 19-ти', 'Про-фактура на 21-ти со групен рабат', 'Уплата до 25-ти', 'Поголем рабат во портфел'], JSON_UNESCAPED_UNICODE),
            'max_delivery_hours' => null,
            'cutoff_day'         => 19,
            'cutoff_hour'        => 17,
            'delivery_day'       => 30,
        ]);
    }

    public function down(): void
    {
        // Restore previous values
        DB::table('shop_order_models')->where('code', 'urgent')->update([
            'tagline'            => 'Моментални потреби кога нешто ви треба веднаш или во рок до 48 часа.',
            'bullets'            => json_encode(['Приоритетна обработка', 'Најбрза можна достава', 'За неодложни потреби', 'Автоматско пренасочување'], JSON_UNESCAPED_UNICODE),
            'max_delivery_hours' => 48,
        ]);
        DB::table('shop_order_models')->where('code', 'monthly')->update([
            'tagline'    => 'Планирана набавка со одреден ден за достава.',
            'bullets'    => json_encode(['Избирате ден за достава', 'Групирање за подобра цена', 'Планирана набавка', 'Оптимални транспорт трошоци'], JSON_UNESCAPED_UNICODE),
            'cutoff_day' => 25,
            'delivery_day' => 10,
        ]);
        DB::table('shop_order_models')->where('code', 'quarterly')->update([
            'tagline'    => 'Автоматска повторувачка нарачка — секои три месеци исто.',
            'bullets'    => json_encode(['Еднаш поставувате, се повторува', 'Гарантирана цена', 'Без заборавање', 'Можност за промена'], JSON_UNESCAPED_UNICODE),
            'cutoff_day' => 20,
            'delivery_day' => 10,
        ]);
    }
};
