<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $row = [
            'label'              => 'МОДЕЛ 04',
            'title'              => 'Неделни – збирни нарачки',
            'tagline'            => 'Нарачки секој ден до понеделник до 12:00 ч. Збирен попуст до вторник 08:00 ч., плаќање електронски до вторник 14:00 ч., достава од среда.',
            'description'        => 'Резервирајте или нарачајте секој ден до понеделник до 12:00 часот. Вкупниот збирен попуст Ви се одобрува до вторник до 08:00 часот. Платете електронски до вторник до 14:00 часот. Доставата се врши од среда, во рок до 5 работни дена.',
            'bullets'            => json_encode([
                'Резервирајте или нарачајте секој ден до понеделник до 12:00 ч.',
                'Вкупниот збирен попуст Ви се одобрува до вторник до 08:00 ч.',
                'Платете електронски до вторник до 14:00 ч.',
                'Достава од среда, во рок до 5 работни дена',
            ], JSON_UNESCAPED_UNICODE),
            'icon'               => 'HiCalendarDays',
            'accent_color'       => '#0b5f86',
            'accent_color_soft'  => '#e6f1f7',
            'cutoff_day'         => null,
            'cutoff_hour'        => 12,
            'delivery_day'       => null,
            'max_delivery_hours' => null,
            'repeat_months'      => 0,
            'auto_repeat'        => false,
            'is_active'          => true,
            'sort_order'         => 4,
            'updated_at'         => now(),
        ];

        if (DB::table('shop_order_models')->where('code', 'weekly')->exists()) {
            DB::table('shop_order_models')->where('code', 'weekly')->update($row);
            return;
        }

        DB::table('shop_order_models')->insert($row + ['code' => 'weekly', 'created_at' => now()]);
    }

    public function down(): void
    {
        DB::table('shop_order_models')->where('code', 'weekly')->delete();
    }
};
