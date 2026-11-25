<?php

namespace Tests\Feature;

use App\Mail\NewOrderClinicMail;
use App\Mail\RecurringOrderCancelledMail;
use App\Mail\RecurringOrderCancelRequestedMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrderModel;
use App\Models\ShopProduct;
use App\Models\ShopRecurringOrder;
use App\Models\ShopVendor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-month orders (МОДЕЛ 04): ordered once, repeated every billing period
 * once it closes, never above the price at ordering.
 */
class RecurringOrdersTest extends TestCase
{
    use RefreshDatabase;

    private ShopProduct $gloves;
    private ShopProduct $masks;
    private ShopClinic $clinic;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.shop_admin_email' => 'admin@example.com']);

        foreach (['monthly' => 'МОДЕЛ 03', 'quarterly' => 'МОДЕЛ 04'] as $code => $label) {
            ShopOrderModel::updateOrCreate(['code' => $code], ['label' => $label, 'title' => $code, 'is_active' => true]);
        }

        $vendor = ShopVendor::create(['name' => 'Добавувач', 'slug' => 'dobavuvac', 'status' => 'active']);

        $this->gloves = $this->product($vendor, 'Ракавици', 1000);
        $this->masks = $this->product($vendor, 'Маски', 200);

        $this->clinic = ShopClinic::create([
            'name' => 'ПЗУ Тест',
            'slug' => 'pzu-test',
            'contact_person' => 'Д-р Тест',
            'email' => 'clinic@example.com',
            'status' => 'approved',
            'password' => bcrypt('secret'),
        ]);

        $this->at('2026-10-23 10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_number_of_months_is_required_and_at_least_three(): void
    {
        Sanctum::actingAs($this->clinic, [], 'sanctum');

        $this->postJson('/api/clinic/orders', $this->checkout())->assertStatus(422)->assertJsonPath('errors.months.0', 'Изберете број на месеци.');
        $this->postJson('/api/clinic/orders', $this->checkout(2))->assertStatus(422);

        $this->assertSame(0, ShopRecurringOrder::count());
    }

    public function test_the_checkout_order_is_month_one(): void
    {
        $order = $this->order(6);

        $recurring = ShopRecurringOrder::firstOrFail();
        $this->assertSame(6, $recurring->months_total);
        $this->assertSame($recurring->id, $order['shop_recurring_order_id']);
        $this->assertSame(1, $order['recurring_month']);
        $this->assertSame([[$this->gloves->id, 2, '1000.00']], $recurring->items->map(fn ($i) => [$i->shop_product_id, $i->quantity, $i->locked_price])->all());

        Mail::assertSent(NewOrderClinicMail::class, fn (NewOrderClinicMail $mail) => str_contains($mail->render(), 'месец 1 од 6')
            && str_contains($mail->render(), '21.12.2026'));
    }

    public function test_each_month_is_ordered_once_after_its_period_closes(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        // Started on 23.10.: the period that closed on 19.10. is not its own,
        // and month 1 already covers the one that closes on 19.11.
        $this->runAt('2026-10-23 11:05');
        $this->runAt('2026-11-20 00:05');
        $this->assertSame(1, $recurring->orders()->count());

        // 19.12. is a Saturday, reservations run to the end of Monday 21.12.
        $this->runAt('2026-12-21 23:55');
        $this->assertSame(1, $recurring->orders()->count());

        $this->runAt('2026-12-22 00:05');
        $this->runAt('2026-12-22 01:05');
        $this->assertSame([1, 2], $recurring->orders()->orderBy('recurring_month')->pluck('recurring_month')->all());

        // A missed night catches up as long as the period is not invoiced.
        $this->runAt('2027-01-20 22:05');
        $this->assertSame(ShopRecurringOrder::STATUS_COMPLETED, $recurring->fresh()->status);
        $this->assertSame('2027-01-19 22:59', $recurring->orders()->where('recurring_month', 3)->first()->placed_at->format('Y-m-d H:i'));

        $this->runAt('2027-02-20 00:05');
        $this->assertSame(3, $recurring->orders()->count());
    }

    public function test_one_started_after_the_period_closed_is_not_ordered_for_it(): void
    {
        // Reservations closed on 21.12., the invoices go out on 23.12.
        $this->at('2026-12-22 10:00');
        $this->order(3);

        $this->runAt('2026-12-22 11:05');

        $this->assertSame(1, ShopRecurringOrder::firstOrFail()->orders()->count());
    }

    public function test_a_period_already_invoiced_is_not_ordered_late(): void
    {
        $this->order(3);

        $this->runAt('2026-12-23 12:05');

        $this->assertSame(1, ShopRecurringOrder::firstOrFail()->orders()->count());
    }

    public function test_the_price_never_goes_above_the_one_at_ordering(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        $this->gloves->update(['price' => 1200]);
        $this->runAt('2026-12-22 00:05');
        $this->assertSame('1000.00', $recurring->orders()->where('recurring_month', 2)->first()->items->first()->unit_sale_price);

        $this->gloves->update(['price' => 900]);
        $this->runAt('2027-01-20 00:05');
        $this->assertSame('900.00', $recurring->orders()->where('recurring_month', 3)->first()->items->first()->unit_sale_price);
    }

    public function test_the_month_order_goes_on_that_months_invoice(): void
    {
        $this->order(3);
        $this->runAt('2026-12-22 00:05');

        $this->at('2026-12-23 12:00');
        $this->artisan('shop:generate-monthly-invoices')->assertSuccessful();

        $invoice = ShopInvoice::whereDate('period_to', '2026-12-21')->firstOrFail();
        $month2 = ShopRecurringOrder::firstOrFail()->orders()->where('recurring_month', 2)->firstOrFail();
        $this->assertSame($invoice->id, $month2->shop_invoice_id);
    }

    public function test_the_clinic_changes_only_quantities_for_next_month(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        $this->putJson("/api/clinic/recurring-orders/{$recurring->id}/items", ['items' => [
            ['product_id' => $this->gloves->id, 'quantity' => 5],
            ['product_id' => $this->masks->id, 'quantity' => 10],
        ]])->assertStatus(422);

        $this->putJson("/api/clinic/recurring-orders/{$recurring->id}/items", ['items' => [
            ['product_id' => $this->gloves->id, 'quantity' => 5],
        ]])->assertOk()->assertJsonPath('monthly_total', 5000);

        $this->runAt('2026-12-22 00:05');

        $items = $recurring->orders()->where('recurring_month', 2)->first()->items;
        $this->assertSame([['Ракавици', 5, '1000.00']], $items->map(fn ($i) => [$i->product_name, $i->quantity, $i->unit_sale_price])->all());
    }

    public function test_a_product_no_longer_sold_is_skipped_and_reported_once(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        $this->gloves->update(['status' => 'archived']);
        $this->runAt('2026-12-22 00:05');
        $this->runAt('2026-12-22 01:05');

        $this->assertSame(1, $recurring->orders()->count());
        $this->assertSame(1, AdminNotification::where('type', 'recurring_items_unavailable')->count());
    }

    public function test_only_the_admin_cancels_on_the_clinics_request(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        $this->postJson("/api/clinic/recurring-orders/{$recurring->id}/cancel-request", ['note' => 'Затвораме'])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('cancel_request_note', 'Затвораме');

        Mail::assertSent(RecurringOrderCancelRequestedMail::class, fn ($mail) => $mail->hasTo('admin@example.com'));
        $this->assertTrue(AdminNotification::where('type', 'recurring_cancel_request')->exists());

        $this->actingAsAdmin();
        $this->getJson('/api/shop-recurring-orders?status=cancel_requested')->assertOk()->assertJsonCount(1);
        $this->patchJson("/api/shop-recurring-orders/{$recurring->id}/cancel", ['reason' => 'По барање'])
            ->assertOk()
            ->assertJsonPath('status', 'cancelled');

        Mail::assertSent(RecurringOrderCancelledMail::class, fn ($mail) => $mail->hasTo('clinic@example.com'));

        $this->runAt('2026-12-22 00:05');
        $this->assertSame(1, $recurring->orders()->count());
    }

    public function test_a_clinic_cannot_see_another_clinics_multi_month_order(): void
    {
        $this->order(3);
        $other = ShopClinic::create([
            'name' => 'Друга', 'slug' => 'druga', 'email' => 'druga@example.com',
            'status' => 'approved', 'password' => bcrypt('secret'),
        ]);
        Sanctum::actingAs($other, [], 'sanctum');

        $this->getJson('/api/clinic/recurring-orders')->assertOk()->assertJsonCount(0);
        $this->getJson('/api/clinic/recurring-orders/' . ShopRecurringOrder::firstOrFail()->id)->assertNotFound();
    }

    public function test_the_public_models_list_the_month_options(): void
    {
        $models = collect($this->getJson('/api/public/shop-order-models')->assertOk()->json())->keyBy('code');

        $this->assertSame([3, 6, 9, 12], $models['quarterly']['month_options']);
        $this->assertArrayNotHasKey('month_options', $models['monthly']);
    }

    private function order(int $months): array
    {
        Sanctum::actingAs($this->clinic, [], 'sanctum');

        return $this->postJson('/api/clinic/orders', $this->checkout($months))->assertCreated()->json();
    }

    private function checkout(?int $months = null): array
    {
        return array_filter([
            'order_model_code' => 'quarterly',
            'months' => $months,
            'items' => [['product_id' => $this->gloves->id, 'quantity' => 2]],
        ], fn ($v) => $v !== null);
    }

    private function runAt(string $at): void
    {
        $this->artisan('shop:recurring-orders', ['--at' => $at])->assertSuccessful();
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Europe/Skopje')->utc());
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin-user@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]), [], 'sanctum');
    }

    private function product(ShopVendor $vendor, string $name, float $price): ShopProduct
    {
        return ShopProduct::create([
            'shop_vendor_id' => $vendor->id,
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . $price,
            'sku' => 'SKU-' . $name,
            'kind' => 'product',
            'price' => $price,
            'cost_price' => $price * 0.7,
            'stock' => 1000,
            'status' => 'active',
        ]);
    }
}
