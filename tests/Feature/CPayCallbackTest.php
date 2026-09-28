<?php

namespace Tests\Feature;

use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopPayment;
use App\Services\CPay\CPayChecksum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CPayCallbackTest extends TestCase
{
    use RefreshDatabase;

    private ShopInvoice $invoice;
    private ShopPayment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cpay.checksum_key' => 'TEST_PASS',
            // Pinned so the test does not depend on how the local terminal is
            // configured; the algorithm itself is covered in the unit test.
            'cpay.checksum_algo' => 'sha256',
            'cpay.merchant_id' => '1234567890',
            'cpay.merchant_name' => 'GNAESHOP',
        ]);

        $clinic = ShopClinic::create([
            'name' => 'Ординација Тест',
            'slug' => 'ordinacija-test',
            'edb' => '4000000000000',
            'email' => 'test@example.com',
            'phone' => '070220070',
            'city' => 'Skopje',
            'status' => 'approved',
            'password' => bcrypt('secret'),
        ]);

        $this->invoice = ShopInvoice::create([
            'invoice_number' => 'PF-2026-0001',
            'shop_clinic_id' => $clinic->id,
            'period_from' => now()->startOfMonth()->toDateString(),
            'period_to' => now()->startOfMonth()->addDays(18)->toDateString(),
            'subtotal' => 1200,
            'surcharge_amount' => 0,
            'total' => 1200,
            'status' => 'pending',
            'issued_at' => now(),
        ]);

        $this->payment = ShopPayment::create([
            'reference' => '1',
            'payable_type' => $this->invoice->getMorphClass(),
            'payable_id' => $this->invoice->id,
            'shop_clinic_id' => $clinic->id,
            'amount' => 1200,
            'currency' => 'MKD',
            'status' => ShopPayment::STATUS_REDIRECTED,
            'details1' => 'Profaktura PF-2026-0001',
        ]);
    }

    public function test_a_signed_success_notification_marks_the_invoice_paid(): void
    {
        $this->post('/api/cpay/ok', $this->notification())->assertOk();

        $this->assertSame(ShopPayment::STATUS_PAID, $this->payment->fresh()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertNotNull($this->invoice->fresh()->paid_at);
    }

    /**
     * The whole point of the ReturnCheckSum: opening the OK URL by hand must
     * never hand out goods.
     */
    public function test_an_unsigned_hit_on_the_ok_url_pays_nothing(): void
    {
        $this->post('/api/cpay/ok', [
            'Details2' => '1',
            'AmountToPay' => '120000',
        ])->assertOk();

        $this->assertSame(ShopPayment::STATUS_REDIRECTED, $this->payment->fresh()->status);
        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    public function test_a_notification_for_a_different_amount_is_refused(): void
    {
        $tampered = $this->notification(['AmountToPay' => '100']);

        $this->post('/api/cpay/ok', $tampered)->assertOk();

        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    public function test_a_failure_notification_marks_the_payment_failed(): void
    {
        $this->post('/api/cpay/fail', $this->notification())->assertOk();

        $this->assertSame(ShopPayment::STATUS_FAILED, $this->payment->fresh()->status);
        $this->assertSame('pending', $this->invoice->fresh()->status);
    }

    /**
     * cPay repeats the same push up to four times, and the browser redirect
     * arrives on top of that.
     */
    public function test_repeated_notifications_are_harmless(): void
    {
        $this->post('/api/cpay/ok', $this->notification())->assertOk();
        $this->post('/api/cpay/ok', $this->notification())->assertOk();

        $this->assertSame(1, ShopPayment::where('reference', '1')->count());
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    /**
     * Case 8 of the testing procedures: a back/refresh sends the browser to the
     * FAIL URL while the card was in fact charged, and the push confirms it on
     * the OK URL afterwards. A paid payment must not be downgraded.
     */
    public function test_a_late_failure_does_not_undo_a_successful_payment(): void
    {
        $this->post('/api/cpay/ok', $this->notification())->assertOk();
        $this->post('/api/cpay/fail', $this->notification())->assertOk();

        $this->assertSame(ShopPayment::STATUS_PAID, $this->payment->fresh()->status);
        $this->assertSame('paid', $this->invoice->fresh()->status);
    }

    public function test_a_notification_for_an_unknown_payment_still_returns_200(): void
    {
        // cPay retries anything that is not 200 OK, four times, for an hour.
        $this->post('/api/cpay/ok', ['Details2' => '999999'])->assertOk();
    }

    /**
     * The same 200 OK that satisfies the push client also carries the redirect
     * for a browser that lands on the URL.
     */
    public function test_the_response_carries_the_redirect_to_the_result_page(): void
    {
        $response = $this->post('/api/cpay/ok', $this->notification());

        $response->assertOk();
        $response->assertSee('status=paid', false);
        $response->assertSee('ref=1', false);
    }

    /**
     * A cPay response: the request echoed back, first two parameters swapped,
     * cPayPaymentRef appended, signed with the merchant's key.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function notification(array $overrides = []): array
    {
        $checksum = new CPayChecksum('TEST_PASS', 'sha256');

        $ordered = array_merge([
            'PayToMerchant' => '1234567890',
            'AmountToPay' => '120000',
            'MerchantName' => 'GNAESHOP',
            'AmountCurrency' => 'MKD',
            'Details1' => 'Profaktura PF-2026-0001',
            'Details2' => '1',
            'PaymentOKURL' => 'https://www.gnaeshop.mk/cpay/ok',
            'PaymentFailURL' => 'https://www.gnaeshop.mk/cpay/fail',
            'cPayPaymentRef' => '25710201',
        ], $overrides);

        return $ordered + [
            'ReturnCheckSumHeader' => $checksum->header($ordered),
            'ReturnCheckSum' => $checksum->checksum($ordered),
        ];
    }
}
