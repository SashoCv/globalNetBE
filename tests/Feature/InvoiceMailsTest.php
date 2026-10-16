<?php

namespace Tests\Feature;

use App\Mail\InvoiceCancelledMail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoicePaidMail;
use App\Mail\NewOrderClinicMail;
use App\Mail\PaymentReceivedClinicMail;
use App\Mail\WeeklyOrderPaymentMail;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Models\ShopOrderModel;
use App\Models\ShopPayment;
use App\Models\ShopProduct;
use App\Models\ShopVendor;
use App\Models\User;
use App\Services\CPay\CPayPaymentService;
use App\Services\Shop\WeeklyCycleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Which mail carries the invoice, for every way an order is invoiced and paid.
 */
class InvoiceMailsTest extends TestCase
{
    use RefreshDatabase;

    private ShopProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        foreach (['urgent' => 'МОДЕЛ 01', 'monthly' => 'МОДЕЛ 02'] as $code => $label) {
            ShopOrderModel::updateOrCreate(['code' => $code], ['label' => $label, 'title' => $code, 'is_active' => true]);
        }

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

    public function test_billing_periods_run_from_the_20th_to_the_19th(): void
    {
        $at = fn (string $day) => ShopInvoice::billingPeriod(Carbon::parse($day . ' 12:00', 'Europe/Skopje'));

        $this->assertSame(['2026-09-20', '2026-10-19'], $at('2026-10-16'));
        $this->assertSame(['2026-09-20', '2026-10-19'], $at('2026-10-19'));
        $this->assertSame(['2026-10-20', '2026-11-19'], $at('2026-10-20'));
        $this->assertSame(['2026-12-20', '2027-01-19'], $at('2026-12-31'));
        $this->assertSame(['2027-01-20', '2027-02-19'], $at('2027-01-31'));

        $closed = fn (string $day) => ShopInvoice::lastClosedBillingPeriod(Carbon::parse($day . ' 01:00', 'Europe/Skopje'));

        $this->assertSame(['2026-09-20', '2026-10-19'], $closed('2026-10-20'));
        $this->assertSame(['2026-08-20', '2026-09-19'], $closed('2026-10-16'));
    }

    /**
     * An order placed after the 19th used to fall between two invoice runs and
     * was never billed; it now goes on next month's invoice, which is mailed.
     */
    public function test_the_monthly_run_bills_orders_from_the_20th_and_mails_the_invoice(): void
    {
        $clinic = $this->clinic('a');

        Carbon::setTestNow(Carbon::parse('2026-09-25 10:00', 'Europe/Skopje'));
        $late = $this->place($clinic, 'monthly');
        Carbon::setTestNow(Carbon::parse('2026-10-10 10:00', 'Europe/Skopje'));
        $early = $this->place($clinic, 'monthly');
        Carbon::setTestNow(Carbon::parse('2026-10-20 01:00', 'Europe/Skopje'));
        $next = $this->place($clinic, 'monthly');

        $this->artisan('shop:generate-monthly-invoices')->assertSuccessful();

        $invoice = ShopInvoice::firstOrFail();
        $this->assertSame([$late->id, $early->id], $invoice->orders()->orderBy('id')->pluck('id')->all());
        $this->assertNull($next->fresh()->shop_invoice_id);

        Mail::assertSent(InvoiceIssuedMail::class, fn (InvoiceIssuedMail $mail) => $mail->hasTo('a@example.com')
            && $this->carriesPdf($mail));
    }

    public function test_generating_from_the_admin_mails_every_clinic(): void
    {
        $this->place($this->clinic('a'), 'monthly');
        $this->place($this->clinic('b'), 'monthly');
        $this->actingAsAdmin();

        $this->postJson('/api/shop-invoices/generate', [
            'period_from' => now()->subDay()->toDateString(),
            'period_to' => now()->addDay()->toDateString(),
        ])->assertCreated();

        Mail::assertSent(InvoiceIssuedMail::class, 2);
        Mail::assertSent(InvoiceIssuedMail::class, fn ($mail) => $mail->hasTo('b@example.com'));
    }

    public function test_an_urgent_order_confirmation_carries_its_invoice(): void
    {
        $this->place($this->clinic('a'), 'urgent');

        Mail::assertSent(NewOrderClinicMail::class, fn (NewOrderClinicMail $mail) => $this->carriesPdf($mail)
            && str_contains($mail->render(), 'Плати ја нарачката со картичка'));
    }

    public function test_a_monthly_order_confirmation_has_no_invoice_yet(): void
    {
        $this->place($this->clinic('a'), 'monthly');

        Mail::assertSent(NewOrderClinicMail::class, fn (NewOrderClinicMail $mail) => $mail->attachments() === []);
    }

    public function test_the_weekly_payment_mail_carries_the_discounted_invoice(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-11 09:00', 'Europe/Skopje')->utc());
        $order = $this->place($this->clinic('a'), 'weekly', 2);

        // Not with the confirmation: the amount is not final yet.
        Mail::assertSent(NewOrderClinicMail::class, fn ($mail) => $mail->attachments() === []);

        $weekly = app(WeeklyCycleService::class);
        Carbon::setTestNow(Carbon::parse('2026-10-12 12:01', 'Europe/Skopje')->utc());
        $weekly->tick(now());
        $weekly->approveDiscount(ShopOrderCycle::firstOrFail(), 10);

        Mail::assertSent(WeeklyOrderPaymentMail::class, fn (WeeklyOrderPaymentMail $mail) => $mail->order->is($order)
            && $this->carriesPdf($mail)
            && str_contains($mail->render(), $order->invoice->invoice_number));
    }

    public function test_the_card_receipt_carries_the_paid_invoice(): void
    {
        $order = $this->place($this->clinic('a'), 'urgent');

        $this->payByCard($order);

        Mail::assertSent(PaymentReceivedClinicMail::class, fn (PaymentReceivedClinicMail $mail) => $this->carriesPdf($mail)
            && str_contains($mail->render(), $order->invoice->invoice_number));
    }

    public function test_marking_an_invoice_paid_mails_it_once(): void
    {
        $invoice = $this->monthlyInvoice();
        $this->actingAsAdmin();

        $this->patchJson("/api/shop-invoices/{$invoice->id}/mark-paid", ['payment_method' => 'Банкарски трансфер'])->assertOk();
        $this->patchJson("/api/shop-invoices/{$invoice->id}/mark-paid")->assertOk();

        Mail::assertSent(InvoicePaidMail::class, 1);
        Mail::assertSent(InvoicePaidMail::class, fn (InvoicePaidMail $mail) => $mail->hasTo('a@example.com')
            && $this->carriesPdf($mail));
    }

    public function test_marking_an_urgent_order_paid_mails_its_invoice(): void
    {
        $order = $this->place($this->clinic('a'), 'urgent');
        $this->actingAsAdmin();

        $this->patchJson("/api/shop-orders/{$order->id}/mark-paid")->assertOk();

        $this->assertSame('paid', $order->invoice->fresh()->status);
        Mail::assertSent(InvoicePaidMail::class, fn ($mail) => $mail->invoice->is($order->invoice));
    }

    public function test_cancelling_an_invoice_tells_the_clinic(): void
    {
        $invoice = $this->monthlyInvoice();
        $this->actingAsAdmin();

        $this->patchJson("/api/shop-invoices/{$invoice->id}/cancel")->assertOk();
        $this->patchJson("/api/shop-invoices/{$invoice->id}/cancel")->assertOk();

        Mail::assertSent(InvoiceCancelledMail::class, 1);
        Mail::assertSent(InvoiceCancelledMail::class, fn (InvoiceCancelledMail $mail) => ! $mail->wasPaid
            && str_contains($mail->render(), 'не треба да се плаќа'));
    }

    public function test_cancelling_a_paid_invoice_promises_a_refund(): void
    {
        $invoice = $this->monthlyInvoice();
        $invoice->update(['status' => 'paid', 'paid_at' => now()]);
        $this->actingAsAdmin();

        $this->patchJson("/api/shop-invoices/{$invoice->id}/cancel")->assertOk();

        Mail::assertSent(InvoiceCancelledMail::class, fn (InvoiceCancelledMail $mail) => $mail->wasPaid
            && str_contains($mail->render(), 'враќање'));
    }

    private function carriesPdf(Mailable $mail): bool
    {
        $attachments = $mail->attachments();

        if (count($attachments) !== 1) {
            return false;
        }

        $data = $attachments[0]->attachWith(fn () => null, fn ($data) => $data());

        return str_starts_with($data, '%PDF');
    }

    private function monthlyInvoice(): ShopInvoice
    {
        $this->place($this->clinic('a'), 'monthly');

        return ShopInvoice::generateForPeriod(now()->subDay()->toDateString(), now()->addDay()->toDateString())['invoices']->first();
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs(User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
            'role' => 'admin',
        ]), [], 'sanctum');
    }

    private function place(ShopClinic $clinic, string $model, int $qty = 1): ShopOrder
    {
        Sanctum::actingAs($clinic, [], 'sanctum');

        $id = $this->postJson('/api/clinic/orders', [
            'order_model_code' => $model,
            'items' => [['product_id' => $this->product->id, 'quantity' => $qty]],
        ])->assertCreated()->json('id');

        return ShopOrder::with('invoice', 'items')->findOrFail($id);
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

    private function clinic(string $slug): ShopClinic
    {
        return ShopClinic::firstOrCreate(['slug' => 'pzu-' . $slug], [
            'name' => 'ПЗУ ' . $slug,
            'contact_person' => 'Д-р Име Презиме',
            'email' => $slug . '@example.com',
            'status' => 'approved',
            'password' => bcrypt('secret'),
        ]);
    }
}
