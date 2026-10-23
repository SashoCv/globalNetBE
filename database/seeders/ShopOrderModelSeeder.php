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
                'title' => 'Итна нарачка',
                'tagline' => 'За **неодложни потреби – ВЕДНАШ** или во рок од **24–72 часа (3 работни дена)**.',
                'description' => null,
                'bullets' => [
                    '**Приоритетна обработка**',
                    '**Плаќањето е исклучиво online – електронски**',
                    '**Најбрза можна достава**',
                    '**Индивидуална испорака**',
                ],
                'icon' => 'HiBolt',
                'accent_color' => '#dc2626',
                'accent_color_soft' => '#fdecec',
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
                'label' => 'МОДЕЛ 03',
                'title' => 'Месечна збирна нарачка',
                'tagline' => 'Планирана месечна нарачка за групна набавка и достава во договорен период.',
                'description' => null,
                'bullets' => [
                    'Нарачки секој ден, **до 19-ти до 15:00**',
                    'Про-фактура со збирен попуст: **до 21-ви до 10:00**',
                    'Плаќање online или во банка: **до 25-ти до 13:00**',
                    'Достава **од 26-ти**, во рок до 7 работни дена',
                ],
                'icon' => 'HiCalendarDays',
                'accent_color' => '#14b8a6',
                'accent_color_soft' => '#e3f6f3',
                'cutoff_day' => 25,
                'cutoff_hour' => 17,
                'delivery_day' => 5,
                'max_delivery_hours' => null,
                'repeat_months' => 1,
                'auto_repeat' => false,
                'sort_order' => 3,
            ],
            [
                'code' => 'quarterly',
                'label' => 'МОДЕЛ 04',
                'title' => 'Повеќемесечна збирна нарачка',
                'tagline' => 'Иста, континуирана месечна нарачка која се повторува повеќе од 3 месеци.',
                'description' => null,
                'bullets' => [
                    'Нарачката се евидентира **само еднаш** – се бира бројот на месеци',
                    '**Гарантирана цена** за целиот период',
                    'Можност за **промена на количина** во секое време',
                    'Рокови и достава исти како месечната нарачка',
                ],
                'icon' => 'HiArrowPath',
                'accent_color' => '#8b5cf6',
                'accent_color_soft' => '#efeafd',
                'cutoff_day' => 20,
                'cutoff_hour' => 17,
                'delivery_day' => 10,
                'max_delivery_hours' => null,
                'repeat_months' => 3,
                'auto_repeat' => true,
                'sort_order' => 4,
            ],
            [
                'code' => 'weekly',
                'label' => 'МОДЕЛ 02',
                'title' => 'Неделна збирна нарачка',
                'tagline' => 'Планирана неделна нарачка за групна набавка и достава во договорен период.',
                'description' => null,
                'bullets' => [
                    'Нарачки секој ден, **до понеделник до 12:00**',
                    'Збирен попуст во про-фактура: **вторник до 08:00**',
                    'Плаќање исклучиво електронски: **вторник до 14:00**',
                    'Достава **од среда**, во рок до 5 работни дена',
                ],
                'icon' => 'HiCalendar',
                'accent_color' => '#1ca6e0',
                'accent_color_soft' => '#e3f3fb',
                // Deadlines live in config/shop.php (weekday based), not here.
                'cutoff_day' => null,
                'cutoff_hour' => 12,
                'delivery_day' => null,
                'max_delivery_hours' => null,
                'repeat_months' => 0,
                'auto_repeat' => false,
                'sort_order' => 2,
            ],
        ];

        foreach ($models as $m) {
            ShopOrderModel::updateOrCreate(['code' => $m['code']], $m);
        }

        $this->command->info(count($models) . ' order models seeded.');
    }
}
