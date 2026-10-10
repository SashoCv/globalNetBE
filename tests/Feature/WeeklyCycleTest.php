<?php

namespace Tests\Feature;

use App\Mail\WeeklyCycleClosedAdminMail;
use App\Mail\WeeklyOrderCancelledMail;
use App\Mail\WeeklyOrderPaymentMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Models\ShopPayment;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use App\Services\Shop\WeeklyCycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * October 2026 is summer time in Skopje (UTC+2): Monday 12 Oct 12:00 local is
 * 10:00 UTC.
 */
class WeeklyCycleTest extends TestCase
{
    use RefreshDatabase;

    private ShopProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['app.shop_admin_email' => 'admin@example.com']);

        $vendor = ShopVendor::create(['name' => 'Добавувач', 'slug' => 'dobavuvac', 'status' => 'active']);

        $this->product = ShopProduct::create([
            'shop_vendor_id' => $vendor->id,
            'name' => 'Ракавици',
            'slug' => 'rakavici',
            'sku' => 'R-1',
            'kind' => 'product',
            'price' => 1000,
            'cost_price' => 700,
            'stock' => 100,
            'status' => 'active',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_window_follows_the_published_deadlines_in_skopje_time(): void
    {
        $w = app(WeeklyCycleService::class)->windowFor($this->local('2026-10-10 10:00'));

        $this->assertSame('2026-W42', $w['code']);
        $this->assertSame('2026-10-12 10:00', $w['cutoff_at']->format('Y-m-d H:i'));       // Mon 12:00
        $this->assertSame('2026-10-13 06:00', $w['discount_due_at']->format('Y-m-d H:i')); // Tue 08:00
        $this->assertSame('2026-10-13 10:00', $w['reminder_at']->format('Y-m-d H:i'));     // Tue 12:00
        $this->assertSame('2026-10-13 12:00', $w['payment_due_at']->format('Y-m-d H:i'));  // Tue 14:00
        $this->assertSame('2026-10-14', $w['delivery_from']);                              // Wed
        $this->assertSame('2026-10-20', $w['delivery_to']);                                // + 5 working days
    }

    public function test_an_order_at_the_cutoff_joins_the_following_week(): void
    {
        $clinic = $this->clinic('a');

        $before = $this->placeAt($clinic, '2026-10-12 11:59');
        $after = $this->placeAt($clinic, '2026-10-12 12:00');

        $this->assertSame('2026-W42', $before->cycle->code);
        $this->assertSame('2026-W43', $after->cycle->code);
        $this->assertSame('awaiting_discount', $before->payment_status);
        $this->assertSame('reserved', $before->status);
        // The starter credit waits for the payment.
        $this->assertEquals(0, $before->loyalty_credit_earned);
        $this->assertEquals(0, $clinic->fresh()->wallet_balance);
    }

    public function test_no_card_payment_before_the_discount_is_approved(): void
    {
        $order = $this->placeAt($this->clinic('a'), '2026-10-11 09:00');

        $this->postJson("/api/clinic/payments/order/{$order->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Збирниот попуст сè уште не е одобрен. Ќе добиете е-пошта со конечниот износ до вторник во 08:00.']);
    }

    public function test_the_whole_week(): void
    {
        $weekly = app(WeeklyCycleService::class);
        $paying = $this->clinic('paying');
        $late = $this->clinic('late');

        $paid = $this->placeAt($paying, '2026-10-11 09:00', 2);   // 2000
        $unpaid = $this->placeAt($late, '2026-10-11 10:00', 3);   // 3000
        $this->assertSame(95, $this->product->fresh()->stock);

        // Mon 12:01 — closed, the admin is asked for the discount.
        $this->tickAt('2026-10-12 12:01');
        $cycle = ShopOrderCycle::where('code', '2026-W42')->first();
        $this->assertSame(ShopOrderCycle::STATUS_CLOSED, $cycle->status);
        $this->assertTrue(AdminNotification::where('type', 'weekly_cycle_closed')->where('data->cycle_id', $cycle->id)->exists());
        Mail::assertSent(WeeklyCycleClosedAdminMail::class);

        // Mon 15:00 — the admin approves 10%.
        Carbon::setTestNow($this->local('2026-10-12 15:00'));
        $weekly->approveDiscount($cycle, 10);

        $paid->refresh();
        $this->assertSame('pending', $paid->payment_status);
        $this->assertEquals(200, $paid->discount_amount);
        $this->assertEquals(1800, $paid->total);
        $this->assertEquals(2700, $unpaid->fresh()->total);
        Mail::assertSent(WeeklyOrderPaymentMail::class, 2);

        // The % is locked once payment is open.
        $this->expectExceptionOnApprove($weekly, $cycle->fresh());

        // The paying clinic pays by card before the deadline.
        Carbon::setTestNow($this->local('2026-10-13 09:30'));
        $this->payByCard($paid);
        $paid->refresh();
        $this->assertSame('paid', $paid->payment_status);
        $this->assertSame('confirmed', $paid->status);
        $this->assertEquals(90, $paid->loyalty_credit_earned);       // 5% of 1800
        $this->assertEquals(90, $paying->fresh()->wallet_balance);

        // Tue 12:00 — reminder only to the clinic that still owes.
        $this->tickAt('2026-10-13 12:00');
        Mail::assertSent(WeeklyOrderPaymentMail::class, fn ($m) => $m->reminder && $m->order->id === $unpaid->id);
        Mail::assertNotSent(WeeklyOrderPaymentMail::class, fn ($m) => $m->reminder && $m->order->id === $paid->id);

        // After 14:00 the card payment is refused.
        Carbon::setTestNow($this->local('2026-10-13 14:00'));
        Sanctum::actingAs($late, [], 'sanctum');
        $this->postJson("/api/clinic/payments/order/{$unpaid->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Рокот за плаќање истече (вторник, 13.10. во 14:00).']);

        // Tue 14:01 — unpaid is cancelled, stock back.
        $this->tickAt('2026-10-13 14:01');
        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertSame('confirmed', $paid->fresh()->status);
        $this->assertSame(98, $this->product->fresh()->stock);
        $this->assertSame(ShopOrderCycle::STATUS_PAYMENT_CLOSED, $cycle->fresh()->status);
        Mail::assertSent(WeeklyOrderCancelledMail::class, fn ($m) => $m->order->id === $unpaid->id);
        $this->assertTrue(AdminNotification::where('type', 'weekly_cycle_payment_closed')->exists());

        // Paid weekly orders go to vendors from their cycle, confirmed only.
        $this->assertSame(2, ShopOrder::where('shop_order_cycle_id', $cycle->id)->count());
    }

    public function test_payment_opens_at_0_percent_when_nobody_approves_by_tuesday_8(): void
    {
        $order = $this->placeAt($this->clinic('a'), '2026-10-11 09:00');

        $this->tickAt('2026-10-12 12:01');
        $this->tickAt('2026-10-13 07:59');
        $this->assertSame(ShopOrderCycle::STATUS_CLOSED, $order->cycle->fresh()->status);

        $this->tickAt('2026-10-13 08:00');
        $cycle = $order->cycle->fresh();
        $this->assertSame(ShopOrderCycle::STATUS_PAYMENT_OPEN, $cycle->status);
        $this->assertTrue($cycle->auto_approved);
        $this->assertEquals(0, $cycle->extra_discount_percent);
        $this->assertEquals(1000, $order->fresh()->total);
        $this->assertSame('pending', $order->fresh()->payment_status);
    }

    public function test_a_payment_still_with_cpay_gets_the_grace_period(): void
    {
        $order = $this->placeAt($this->clinic('a'), '2026-10-11 09:00');
        $this->tickAt('2026-10-12 12:01');
        $this->tickAt('2026-10-13 08:00');

        ShopPayment::create([
            'reference' => '77',
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->id,
            'shop_clinic_id' => $order->shop_clinic_id,
            'amount' => 1000,
            'currency' => 'MKD',
            'status' => ShopPayment::STATUS_REDIRECTED,
            'details1' => 'Naracka',
            'redirected_at' => $this->local('2026-10-13 13:58'),
        ]);

        $this->tickAt('2026-10-13 14:01');
        $this->assertSame('reserved', $order->fresh()->status);
        $this->assertSame(ShopOrderCycle::STATUS_PAYMENT_OPEN, $order->cycle->fresh()->status);

        $this->tickAt('2026-10-13 14:29');
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(ShopOrderCycle::STATUS_PAYMENT_CLOSED, $order->cycle->fresh()->status);
    }

    public function test_the_discount_can_hand_back_wallet_credit(): void
    {
        $clinic = $this->clinic('a', wallet: 1000);
        $order = $this->placeAt($clinic, '2026-10-11 09:00');   // 1000, all paid by wallet

        $this->assertEquals(1000, $order->wallet_applied);
        $this->assertEquals(0, $order->total);

        $this->tickAt('2026-10-12 12:01');
        app(WeeklyCycleService::class)->approveDiscount($order->cycle->fresh(), 20);

        $order->refresh();
        $this->assertEquals(800, $order->wallet_applied);
        $this->assertEquals(0, $order->total);
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertEquals(200, $clinic->fresh()->wallet_balance);
    }

    public function test_the_extra_discount_is_capped_at_20_percent(): void
    {
        $order = $this->placeAt($this->clinic('a'), '2026-10-11 09:00');
        $this->tickAt('2026-10-12 12:01');

        $this->expectException(\RuntimeException::class);
        app(WeeklyCycleService::class)->approveDiscount($order->cycle->fresh(), 25);
    }

    public function test_a_weekly_order_never_lands_on_a_monthly_pro_invoice(): void
    {
        $this->placeAt($this->clinic('a'), '2026-10-11 09:00');

        $result = ShopInvoice::generateForPeriod('2026-10-01', '2026-10-31');

        $this->assertSame(0, $result['orders_count']);
    }

    private function expectExceptionOnApprove(WeeklyCycleService $weekly, ShopOrderCycle $cycle): void
    {
        try {
            $weekly->approveDiscount($cycle, 15);
            $this->fail('A second approval must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Попустот за овој циклус е веќе одобрен.', $e->getMessage());
        }
    }

    private function payByCard(ShopOrder $order): void
    {
        $payment = ShopPayment::create([
            'reference' => (string) (1000 + $order->id),
            'payable_type' => $order->getMorphClass(),
            'payable_id' => $order->id,
            'shop_clinic_id' => $order->shop_clinic_id,
            'amount' => round((float) $order->total),
            'currency' => 'MKD',
            'status' => ShopPayment::STATUS_REDIRECTED,
            'details1' => 'Naracka',
            'redirected_at' => now(),
        ]);

        app(\App\Services\CPay\CPayPaymentService::class)->applyResult($payment, ['cPayPaymentRef' => 'X1'], true);
    }

    private function placeAt(ShopClinic $clinic, string $local, int $qty = 1): ShopOrder
    {
        Carbon::setTestNow($this->local($local));
        Sanctum::actingAs($clinic, [], 'sanctum');

        $id = $this->postJson('/api/clinic/orders', [
            'order_model_code' => 'weekly',
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]],
        ])->assertCreated()->json('id');

        return ShopOrder::with('cycle')->findOrFail($id);
    }

    private function tickAt(string $local): void
    {
        Carbon::setTestNow($this->local($local));
        app(WeeklyCycleService::class)->tick(now());
    }

    private function local(string $time): Carbon
    {
        return Carbon::parse($time, 'Europe/Skopje')->utc();
    }

    private function clinic(string $slug, float $wallet = 0): ShopClinic
    {
        return ShopClinic::create([
            'name' => 'ПЗУ ' . $slug,
            'slug' => 'pzu-' . $slug,
            'edb' => '40000000' . str_pad((string) crc32($slug) % 100000, 5, '0', STR_PAD_LEFT),
            'contact_person' => 'Д-р Име Презиме',
            'email' => $slug . '@example.com',
            'phone' => '070000000',
            'city' => 'Скопје',
            'address' => 'ул. Пример 1',
            'status' => 'approved',
            'password' => bcrypt('secret'),
            'wallet_balance' => $wallet,
        ]);
    }
}
