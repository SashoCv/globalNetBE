<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Order model cards with the deadlines of Article 7 of the vendor contract.
 * Words between ** are shown in bold. Titles and colours stay as approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $models = [
            'urgent' => [
                'bullets' => [
                    '**Приоритетна обработка**, нарачки 24/7',
                    '**Плаќањето е исклучиво online – електронски**',
                    'Испорака **24–72 часа (3 работни дена)** од уплатата',
                    'Индивидуална или групна испорака',
                ],
            ],
            'weekly' => [
                'bullets' => [
                    'Нарачки секој ден, **до понеделник до 12:00**',
                    'Збирен попуст во про-фактура: **вторник до 10:00**',
                    'Плаќање исклучиво електронски: **вторник до 14:00**',
                    'Достава **од среда**, во рок до 5 работни дена',
                ],
            ],
            'monthly' => [
                'bullets' => [
                    'Нарачки секој ден, **до 19-ти во месецот**',
                    'Про-фактура: **на 21-ви**',
                    'Плаќање online или во банка: **од 21-ви до 25-ти**',
                    'Достава **од 26-ти**, во рок до 7 работни дена',
                ],
                'cutoff_hour' => null,
            ],
            'quarterly' => [
                'tagline' => 'Иста, континуирана месечна нарачка која се повторува најмалку 3 месеци.',
                'bullets' => [
                    'Нарачката се евидентира **само еднаш** – се бира бројот на месеци',
                    '**Гарантирана цена** за целиот период',
                    'Промена на количина за наредниот месец: **до 19-ти**',
                    'Рокови и достава исти како месечната нарачка',
                ],
                'cutoff_hour' => null,
            ],
        ];

        foreach ($models as $code => $values) {
            DB::table('shop_order_models')->where('code', $code)->update([
                ...$values,
                'bullets' => json_encode($values['bullets'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Texts only; the previous wording is not restored.
    }
};
