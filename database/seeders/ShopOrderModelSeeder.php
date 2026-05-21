<?php

namespace Database\Seeders;

use App\Models\ShopOrderModel;
use Illuminate\Database\Seeder;

class ShopOrderModelSeeder extends Seeder
{
    public function run(): void
    {
        $models = [
            [
                'code' => 'urgent',
                'label' => 'МОДЕЛ 01',
                'title' => 'Итни нарачки',
                'tagline' => 'Моментални потреби кога нешто ви треба веднаш или во рок до 48 часа.',
                'description' => 'За неодложни ситуации — нарачката се обработува со највисок приоритет, без чекање за збирен циклус. Може да има надомест за итна испорака.',
                'bullets' => [
                    'Приоритетна обработка',
                    'Најбрза можна достава',
                    'За неодложни потреби',
                    'Автоматско пренасочување',
                ],
                'icon' => 'HiBolt',
                'accent_color' => '#dc2626',
                'accent_color_soft' => '#fef2f2',
                'cutoff_day' => null,
                'cutoff_hour' => null,
                'delivery_day' => null,
                'max_delivery_hours' => 48,
                'repeat_months' => 0,
                'auto_repeat' => false,
                'surcharge_percent' => 10.00,
                'sort_order' => 1,
            ],
            [
                'code' => 'monthly',
                'label' => 'МОДЕЛ 02',
                'title' => 'Месечни – збирни нарачки',
                'tagline' => 'Планирана набавка со одреден ден за достава.',
                'description' => 'Сите нарачки до определениот ден во месецот се групираат и испорачуваат заедно — оптимални транспортни трошоци и подобри цени за поголеми количини.',
                'bullets' => [
                    'Избирате ден за достава',
                    'Групирање за подобра цена',
                    'Планирана набавка',
                    'Оптимални транспорт трошоци',
                ],
                'icon' => 'HiCalendarDays',
                'accent_color' => '#1ca6e0',
                'accent_color_soft' => '#eaf6fc',
                'cutoff_day' => 25,
                'cutoff_hour' => 17,
                'delivery_day' => 5,
                'max_delivery_hours' => null,
                'repeat_months' => 1,
                'auto_repeat' => false,
                'sort_order' => 2,
            ],
            [
                'code' => 'quarterly',
                'label' => 'МОДЕЛ 03',
                'title' => 'Тромесечни – збирни нарачки',
                'tagline' => 'Автоматска повторувачка нарачка — секои три месеци исто.',
                'description' => 'Еднаш поставувате — нарачката автоматски се повторува на секои три месеци, со гарантирана цена за целиот период. Може да се откаже или промени во секое време.',
                'bullets' => [
                    'Еднаш поставувате, се повторува',
                    'Гарантирана цена',
                    'Без заборавање',
                    'Можност за промена',
                ],
                'icon' => 'HiArrowPath',
                'accent_color' => '#0e7fac',
                'accent_color_soft' => '#e0f2fb',
                'cutoff_day' => 20,
                'cutoff_hour' => 17,
                'delivery_day' => 10,
                'max_delivery_hours' => null,
                'repeat_months' => 3,
                'auto_repeat' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($models as $m) {
            ShopOrderModel::updateOrCreate(['code' => $m['code']], $m);
        }

        $this->command->info(count($models) . ' order models seeded.');
    }
}
