<?php

use App\Services\Shop\WeeklyCycleService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A cycle stores its deadlines when it is created. The ones still taking
 * orders get the new ones (discount by 10:00, holidays skipped).
 */
return new class extends Migration
{
    public function up(): void
    {
        $weekly = app(WeeklyCycleService::class);

        foreach (DB::table('shop_order_cycles')->where('status', 'open')->get() as $cycle) {
            $window = $weekly->windowFor(Carbon::parse($cycle->opens_at, 'UTC')->addMinute());

            if ($window['code'] !== $cycle->code) {
                continue;
            }

            $dates = collect($window)->except('code')
                ->map(fn ($v) => $v instanceof Carbon ? $v->format('Y-m-d H:i:s') : $v)
                ->all();

            DB::table('shop_order_cycles')->where('id', $cycle->id)->update([...$dates, 'updated_at' => now()]);
            DB::table('shop_orders')->where('shop_order_cycle_id', $cycle->id)
                ->where('status', '!=', 'cancelled')
                ->update(['payment_due_at' => $dates['payment_due_at']]);
        }
    }

    public function down(): void
    {
        // Deadlines only; the previous ones are not restored.
    }
};
