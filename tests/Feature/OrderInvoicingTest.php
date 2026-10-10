<?php

namespace Tests\Feature;

use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Models\ShopOrderModel;
use App\Models\ShopPayment;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use App\Services\CPay\CPayPaymentService;
use App\Services\Shop\WeeklyCycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Urgent and weekly orders get their own invoice when they are placed; the
 * order and the invoice settle together.
 */
class OrderInvoicingTest extends TestCase
{
    use RefreshDatabase;

    private ShopProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        ShopOrderModel::updateOrCreate(['code' => 'urgent'], [
            'label' => 'МОДЕЛ 01',
            'title' => 'Итни нарачки',
            'is_active' => true,
        ]);
        ShopOrderModel::updateOrCreate(['code' => 'monthly'], [
            'label' => 'МОДЕЛ 02',
            'title' => 'Месечни',
            'is_active' => true,
        ]);

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

    public function test_an_urgent_order_is_invoiced_at_once_and_earns_5_percent_when_paid(): void
    {
        $clinic = $this->clinic('a');
        $order = $this->place($clinic, 'urgent', 2);

        $invoice = $order->invoice;
        $this->assertNotNull($invoice);
        $this->assertSame('pending', $invoice->status);
        $this->assertEquals(2000, $invoice->total);
        // The credit waits for the payment.
        $this->assertEquals(0, $clinic->fresh()->wallet_balance);

        $this->payByCard($order);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(100, $order->loyalty_credit_earned);
        $this->assertEquals(100, $clinic->fresh()->wallet_balance);
        $this->assertSame('paid', $invoice->fresh()->status);

        // Never twice, whichever handle is paid next.
        app(\App\Services\Shop\ShopOrderSettlement::class)->invoicePaid($invoice->fresh(), null);
        $this->assertEquals(100, $clinic->fresh()->wallet_balance);
    }

    public function test_paying_the_invoice_pays_the_urgent_order(): void
    {
        $clinic = $this->clinic('a');
        $order = $this->place($clinic, 'urgent');

        $this->payByCard($order->invoice);

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertEquals(50, $order->loyalty_credit_earned);

        $this->postJson("/api/clinic/payments/order/{$order->id}")->assertStatus(422);
    }

    public function test_cancelling_an_urgent_order_cancels_its_invoice(): void
    {
        $order = $this->place($this->clinic('a'), 'urgent');

        $this->patchJson("/api/clinic/orders/{$order->id}/cancel")->assertOk();

        $this->assertSame('cancelled', $order->invoice->fresh()->status);
        $this->postJson("/api/clinic/payments/invoice/{$order->shop_invoice_id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Фактурата е откажана.']);
    }

    public function test_an_urgent_order_covered_by_wallet_is_settled_on_the_spot(): void
    {
        $order = $this->place($this->clinic('a', wallet: 5000), 'urgent');

        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('paid', $order->invoice->status);
        $this->assertSame('Поени', $order->invoice->payment_method);
    }

    public function test_a_weekly_invoice_follows_the_group_discount(): void
    {
        $clinic = $this->clinic('a');
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00', 'Europe/Skopje')->utc());
        $order = $this->place($clinic, 'weekly', 2);

        $invoice = $order->invoice;
        $this->assertEquals(2000, $invoice->total);
        $this->assertSame('2026-10-13', $invoice->due_at->toDateString());

        // Not payable before the discount, through either handle.
        $this->postJson("/api/clinic/payments/invoice/{$invoice->id}")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Збирниот попуст сè уште не е одобрен. Ќе добиете е-пошта со конечниот износ до вторник во 08:00.']);

        $weekly = app(WeeklyCycleService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:01', 'Europe/Skopje')->utc());
        $weekly->tick(now());
        $weekly->approveDiscount(ShopOrderCycle::firstOrFail(), 10);

        $this->assertEquals(1800, $invoice->fresh()->total);

        $this->payByCard($invoice->fresh());

        $order->refresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertEquals(90, $order->loyalty_credit_earned);
    }

    public function test_an_unpaid_weekly_order_takes_its_invoice_with_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00', 'Europe/Skopje')->utc());
        $order = $this->place($this->clinic('a'), 'weekly');

        $weekly = app(WeeklyCycleService::class);
        foreach (['2026-10-12 12:01', '2026-10-13 08:01', '2026-10-13 14:01'] as $at) {
            Carbon::setTestNow(Carbon::parse($at, 'Europe/Skopje')->utc());
            $weekly->tick(now());
        }

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->invoice->fresh()->status);
    }

    public function test_monthly_orders_still_wait_for_the_pro_invoice(): void
    {
        $order = $this->place($this->clinic('a'), 'monthly');

        $this->assertNull($order->shop_invoice_id);
        $this->assertSame(1, ShopInvoice::generateForPeriod(now()->startOfMonth()->toDateString(), now()->toDateString())['orders_count']);
    }

    private function place(ShopClinic $clinic, string $model, int $qty = 1): ShopOrder
    {
        Sanctum::actingAs($clinic, [], 'sanctum');

        $id = $this->postJson('/api/clinic/orders', [
            'order_model_code' => $model,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]],
        ])->assertCreated()->json('id');

        return ShopOrder::with('invoice')->findOrFail($id);
    }

    private function payByCard(Model $payable): void
    {
        $payment = ShopPayment::create([
            'reference' => (string) (1000 + ShopPayment::count()),
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'shop_clinic_id' => $payable->shop_clinic_id,
            'amount' => round((float) $payable->total),
            'currency' => 'MKD',
            'status' => ShopPayment::STATUS_REDIRECTED,
            'details1' => 'Test',
            'redirected_at' => now(),
        ]);

        app(CPayPaymentService::class)->applyResult($payment, ['cPayPaymentRef' => 'X' . $payment->id], true);
    }

    private function clinic(string $slug, float $wallet = 0): ShopClinic
    {
        return ShopClinic::create([
            'name' => 'ПЗУ ' . $slug,
            'slug' => 'pzu-' . $slug,
            'contact_person' => 'Д-р Име Презиме',
            'email' => $slug . '@example.com',
            'status' => 'approved',
            'password' => bcrypt('secret'),
            'wallet_balance' => $wallet,
        ]);
    }
}
