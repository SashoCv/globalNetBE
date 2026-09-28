<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShopPayment;
use App\Services\CPay\CPayChecksum;
use App\Services\CPay\CPayPaymentService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives the result of a cPay payment on PaymentOKURL / PaymentFailURL.
 *
 * The very same URL is hit twice for one payment: once by cPay's server (push
 * notification, up to four attempts — it must always get 200 OK) and once by
 * the customer's browser (redirect — it must be sent on to a readable page).
 *
 * Nothing here trusts the URL that was hit: a payment is only marked as paid
 * when the ReturnCheckSum verifies and the echoed amount matches what we asked
 * for. Otherwise anyone could open the OK URL by hand and get goods for free.
 */
class CPayCallbackController extends Controller
{
    public function __construct(
        private readonly CPayPaymentService $payments,
        private readonly CPayChecksum $checksum,
    ) {
    }

    // POST|GET /api/cpay/ok
    public function ok(Request $request): Response
    {
        return $this->handle($request, true);
    }

    // POST|GET /api/cpay/fail
    public function fail(Request $request): Response
    {
        return $this->handle($request, false);
    }

    private function handle(Request $request, bool $success): Response
    {
        $data = $request->all();
        $reference = (string) ($data['Details2'] ?? '');

        // Every notification is recorded, not just the broken ones: a payment
        // that silently did nothing is the hardest kind to chase afterwards.
        Log::info('[cpay] notification received', [
            'url' => $success ? 'ok' : 'fail',
            'details2' => $reference,
            'amount' => $data['AmountToPay'] ?? null,
            'cpay_ref' => $data['cPayPaymentRef'] ?? null,
            'fields' => array_keys($data),
        ]);

        $payment = $reference === ''
            ? null
            : ShopPayment::where('reference', $reference)->first();

        if (! $payment) {
            Log::warning('[cpay] notification for an unknown payment', [
                'details2' => $reference,
                'ok_url' => $success,
            ]);

            // Still 200, otherwise cPay keeps retrying a payment we cannot place.
            return $this->respond($request, null, false);
        }

        $verified = $this->checksum->verifyReturn($data);
        $amountMatches = (string) ($data['AmountToPay'] ?? '')
            === (string) (int) round((float) $payment->amount * 100);

        if (! $verified || ! $amountMatches) {
            Log::warning('[cpay] notification failed validation', [
                'reference' => $reference,
                'checksum_ok' => $verified,
                'amount_ok' => $amountMatches,
            ]);

            $payment->update([
                'response_payload' => $data,
                'failure_reason' => $verified ? 'Amount mismatch' : 'Invalid ReturnCheckSum',
            ]);

            return $this->respond($request, $payment, false);
        }

        // Case 8 in the testing procedures: a back/refresh during payment sends
        // the browser to the FAIL URL while the push later confirms on the OK
        // URL. A payment already marked paid is therefore never downgraded.
        $this->payments->applyResult($payment, $data, $success);

        $payment = $payment->fresh();

        Log::info('[cpay] payment settled', [
            'reference' => $payment->reference,
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);

        return $this->respond($request, $payment, $success);
    }

    /**
     * One response serves both channels. cPay's push client only cares that it
     * gets 200 OK — anything else and it retries for an hour — while a browser
     * follows the redirect in the body. Sniffing headers to tell the two apart
     * is unreliable, so neither is asked to identify itself.
     */
    private function respond(Request $request, ?ShopPayment $payment, bool $success): Response
    {
        $url = rtrim((string) config('cpay.return_url'), '/')
            . '?status=' . ($payment?->status ?? ($success ? 'paid' : 'failed'))
            . '&ref=' . urlencode((string) ($payment?->reference ?? ''));

        $escaped = e($url);

        $html = <<<HTML
            <!doctype html>
            <html lang="mk">
            <head>
            <meta charset="utf-8">
            <meta http-equiv="refresh" content="0;url={$escaped}">
            <title>GNA E-Shop</title>
            </head>
            <body>
            <p>Ве враќаме на <a href="{$escaped}">GNA E-Shop</a>…</p>
            <script>window.location.replace({$this->jsUrl($url)});</script>
            </body>
            </html>
            HTML;

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function jsUrl(string $url): string
    {
        return json_encode($url, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            ?: '"/"';
    }
}
