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
 * at the monthly cutoff, never above the price at ordering.
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
            && str_contains($mail->render(), '19.12.2026 во 15:00'));
    }

    public function test_each_month_is_ordered_once_after_the_cutoff_of_its_period(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        // Month 1 already covers the period that closes on 19.11.
        $this->runAt('2026-11-19 16:00');
        $this->assertSame(1, $recurring->orders()->count());

        $this->runAt('2026-12-19 14:59');
        $this->assertSame(1, $recurring->orders()->count());

        $this->runAt('2026-12-19 15:05');
        $this->runAt('2026-12-19 16:05');
        $this->assertSame([1, 2], $recurring->orders()->orderBy('recurring_month')->pluck('recurring_month')->all());

        $this->runAt('2027-01-19 15:05');
        $this->assertSame(ShopRecurringOrder::STATUS_COMPLETED, $recurring->fresh()->status);

        $this->runAt('2027-02-19 15:05');
        $this->assertSame(3, $recurring->orders()->count());
    }

    public function test_the_price_never_goes_above_the_one_at_ordering(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();

        $this->gloves->update(['price' => 1200]);
        $this->runAt('2026-12-19 15:05');
        $this->assertSame('1000.00', $recurring->orders()->where('recurring_month', 2)->first()->items->first()->unit_sale_price);

        $this->gloves->update(['price' => 900]);
        $this->runAt('2027-01-19 15:05');
        $this->assertSame('900.00', $recurring->orders()->where('recurring_month', 3)->first()->items->first()->unit_sale_price);
    }

    public function test_the_month_order_goes_on_that_months_invoice(): void
    {
        $this->order(3);
        $this->runAt('2026-12-19 15:05');

        $this->at('2026-12-20 01:00');
        $this->artisan('shop:generate-monthly-invoices')->assertSuccessful();

        $invoice = ShopInvoice::whereDate('period_to', '2026-12-19')->firstOrFail();
        $month2 = ShopRecurringOrder::firstOrFail()->orders()->where('recurring_month', 2)->firstOrFail();
        $this->assertSame($invoice->id, $month2->shop_invoice_id);
    }

    public function test_the_clinic_changes_quantities_and_adds_products_for_next_month(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();
        $this->masks->update(['price' => 250]);

        $this->putJson("/api/clinic/recurring-orders/{$recurring->id}/items", ['items' => [
            ['product_id' => $this->gloves->id, 'quantity' => 5],
            ['product_id' => $this->masks->id, 'quantity' => 10],
        ]])->assertOk()->assertJsonPath('monthly_total', 7500);

        $this->masks->update(['price' => 300]);
        $this->runAt('2026-12-19 15:05');

        $items = $recurring->orders()->where('recurring_month', 2)->first()->items->sortBy('product_name')->values();
        $this->assertSame([['Маски', 10, '250.00'], ['Ракавици', 5, '1000.00']], $items->map(fn ($i) => [$i->product_name, $i->quantity, $i->unit_sale_price])->all());
    }

    public function test_an_inactive_product_cannot_be_added_and_is_skipped_later(): void
    {
        $this->order(3);
        $recurring = ShopRecurringOrder::firstOrFail();
        Sanctum::actingAs($this->clinic, [], 'sanctum');

        $this->masks->update(['status' => 'archived']);
        $this->putJson("/api/clinic/recurring-orders/{$recurring->id}/items", ['items' => [
            ['product_id' => $this->masks->id, 'quantity' => 1],
        ]])->assertStatus(422);

        $this->gloves->update(['status' => 'archived']);
        $this->runAt('2026-12-19 15:05');

        $this->assertSame(1, $recurring->orders()->count());
        $this->assertTrue(AdminNotification::where('type', 'recurring_items_unavailable')->exists());
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

        $this->runAt('2026-12-19 15:05');
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
