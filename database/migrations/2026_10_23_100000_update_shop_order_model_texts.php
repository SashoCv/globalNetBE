<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Order model cards as approved on 22.10.2026: numbered 01–04 in the order
 * urgent, weekly, monthly, multi-month. Words between ** are shown in bold.
 * The long descriptions repeated older deadlines, so they are cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        $models = [
            'urgent' => [
                'label' => 'МОДЕЛ 01',
                'title' => 'Итна нарачка',
                'tagline' => 'За **неодложни потреби – ВЕДНАШ** или во рок од **24–72 часа (3 работни дена)**.',
                'bullets' => [
                    '**Приоритетна обработка**',
                    '**Плаќањето е исклучиво online – електронски**',
                    '**Најбрза можна достава**',
                    '**Индивидуална испорака**',
                ],
                'icon' => 'HiBolt',
                'accent_color' => '#dc2626',
                'accent_color_soft' => '#fdecec',
                'sort_order' => 1,
            ],
            'weekly' => [
                'label' => 'МОДЕЛ 02',
                'title' => 'Неделна збирна нарачка',
                'tagline' => 'Планирана неделна нарачка за групна набавка и достава во договорен период.',
                'bullets' => [
                    'Нарачки секој ден, **до понеделник до 12:00**',
                    'Збирен попуст во про-фактура: **вторник до 08:00**',
                    'Плаќање исклучиво електронски: **вторник до 14:00**',
                    'Достава **од среда**, во рок до 5 работни дена',
                ],
                'icon' => 'HiCalendar',
                'accent_color' => '#1ca6e0',
                'accent_color_soft' => '#e3f3fb',
                'sort_order' => 2,
            ],
            'monthly' => [
                'label' => 'МОДЕЛ 03',
                'title' => 'Месечна збирна нарачка',
                'tagline' => 'Планирана месечна нарачка за групна набавка и достава во договорен период.',
                'bullets' => [
                    'Нарачки секој ден, **до 19-ти до 15:00**',
                    'Про-фактура со збирен попуст: **до 21-ви до 10:00**',
                    'Плаќање online или во банка: **до 25-ти до 13:00**',
                    'Достава **од 26-ти**, во рок до 7 работни дена',
                ],
                'icon' => 'HiCalendarDays',
                'accent_color' => '#14b8a6',
                'accent_color_soft' => '#e3f6f3',
                'sort_order' => 3,
            ],
            'quarterly' => [
                'label' => 'МОДЕЛ 04',
                'title' => 'Повеќемесечна збирна нарачка',
                'tagline' => 'Иста, континуирана месечна нарачка која се повторува повеќе од 3 месеци.',
                'bullets' => [
                    'Нарачката се евидентира **само еднаш** – се бира бројот на месеци',
                    '**Гарантирана цена** за целиот период',
                    'Можност за **промена на количина** во секое време',
                    'Рокови и достава исти како месечната нарачка',
                ],
                'icon' => 'HiArrowPath',
                'accent_color' => '#8b5cf6',
                'accent_color_soft' => '#efeafd',
                'sort_order' => 4,
            ],
        ];

        foreach ($models as $code => $values) {
            DB::table('shop_order_models')->where('code', $code)->update([
                ...$values,
                'bullets' => json_encode($values['bullets'], JSON_UNESCAPED_UNICODE),
                'description' => null,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Texts only; the previous wording is not restored.
    }
};
