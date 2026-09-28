<?php

namespace App\Services\CPay;

use App\Mail\PaymentReceivedAdminMail;
use App\Mail\PaymentReceivedClinicMail;
use App\Models\AdminNotification;
use App\Models\ShopClinic;
use App\Models\ShopInvoice;
use App\Models\ShopOrder;
use App\Models\ShopPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds the browser-redirect payment request for cPay and applies the result
 * of a cPay notification to the order or invoice that was being paid.
 */
class CPayPaymentService
{
    public function __construct(private readonly CPayChecksum $checksum)
    {
    }

    /**
     * Start (or reuse) a payment for an order or an invoice.
     */
    public function start(Model $payable, ShopClinic $clinic): ShopPayment
    {
        $amount = $this->chargeableAmount($payable);

        if ($amount <= 0) {
            throw new RuntimeException('Нема износ за плаќање.');
        }

        // cPay declines anything over 10 MKD while the TEST_PASS key is in use.
        // The TestChecksum page is exempt: it only validates the checksum and
        // never touches a card, so the real amount can be sent there.
        $charges = ! str_contains((string) config('cpay.payment_url'), 'TestChecksum');

        if ($charges && config('cpay.test_mode') && ! config('cpay.test_amount_override')
            && $amount > (float) config('cpay.test_max_amount')) {
            throw new RuntimeException(
                'Во тест режим cPay одбива трансакции над ' . config('cpay.test_max_amount') . ' денари.'
            );
        }

        // A customer who returns to the payment page after abandoning cPay gets
        // the same payment row back — cPay may still send notifications for it.
        $existing = ShopPayment::query()
            ->where('payable_type', $payable->getMorphClass())
            ->where('payable_id', $payable->getKey())
            ->whereIn('status', [ShopPayment::STATUS_NEW, ShopPayment::STATUS_REDIRECTED])
            ->where('amount', $amount)
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $payment = new ShopPayment([
            'reference' => '0',
            'payable_type' => $payable->getMorphClass(),
            'payable_id' => $payable->getKey(),
            'shop_clinic_id' => $clinic->id,
            'amount' => $amount,
            'currency' => (string) config('cpay.currency'),
            'status' => ShopPayment::STATUS_NEW,
            'details1' => $this->details1($payable),
        ]);
        $payment->save();

        // Details2 must be unique per payment and at most 10 characters.
        $payment->update(['reference' => (string) $payment->id]);

        return $payment->fresh();
    }

    /**
     * The hidden fields the e-shop posts to cPay, checksum included.
     *
     * @return array{action: string, fields: array<string, string>}
     */
    public function redirectForm(ShopPayment $payment): array
    {
        $params = [
            // cPay wants the amount multiplied by 100, and only whole denars.
            'AmountToPay' => $this->amountToPay($payment),
            'PayToMerchant' => (string) config('cpay.merchant_id'),
            'MerchantName' => (string) config('cpay.merchant_name'),
            'AmountCurrency' => $payment->currency,
            'Details1' => $payment->details1,
            'Details2' => $payment->reference,
            'PaymentOKURL' => (string) config('cpay.ok_url'),
            'PaymentFailURL' => (string) config('cpay.fail_url'),
        ];

        // Cardholder details. Visa and Mastercard recommend sending these in the
        // 3DS2 authentication: they raise the share of frictionless payments and
        // cut false declines. An order carries its own delivery contact, which
        // is closer to the person paying than the clinic's registration data.
        $payable = $payment->payable;
        $clinic = $payment->clinic;

        [$firstName, $lastName] = $this->splitName(
            $payable instanceof ShopOrder && $payable->delivery_contact
                ? $payable->delivery_contact
                : $clinic?->contact_person
        );

        $city = $this->clean($this->preferOrder($payable, 'delivery_city', $clinic?->city), 50);

        $params += array_filter([
            'FirstName' => $firstName,
            'LastName' => $lastName,
            'Address' => $this->clean($this->preferOrder($payable, 'delivery_address', $clinic?->address), 50, '/'),
            'City' => $city,
            // ISO 3166-1 numeric for North Macedonia. Only meaningful next to a
            // city, so the two are sent together or not at all.
            'Country' => $city ? '807' : null,
            'Telephone' => $this->phone($this->preferOrder($payable, 'delivery_phone', $clinic?->phone)),
            'Email' => $this->clean($this->preferOrder($payable, 'delivery_email', $clinic?->email), 256, '@.-_'),
        ], fn ($v) => $v !== null && $v !== '');

        $ordered = $this->checksum->orderRequest($params);

        $fields = $params + [
            // isSimple is never part of the checksum.
            'isSimple' => 'true',
            'CheckSumHeader' => $this->checksum->header($ordered),
            'CheckSum' => $this->checksum->checksum($ordered),
        ];

        $payment->update([
            'status' => ShopPayment::STATUS_REDIRECTED,
            'redirected_at' => now(),
            'request_payload' => $fields,
        ]);

        return [
            'action' => (string) config('cpay.payment_url'),
            'fields' => $fields,
        ];
    }

    /**
     * Apply a verified cPay notification. Safe to call more than once: cPay
     * sends the same result over the browser redirect and up to four pushes.
     *
     * @param  array<string, mixed>  $response
     */
    public function applyResult(ShopPayment $payment, array $response, bool $success): ShopPayment
    {
        if ($payment->status === ShopPayment::STATUS_PAID) {
            return $payment;
        }

        $payment->fill([
            'response_payload' => $response,
            'cpay_payment_ref' => $response['cPayPaymentRef'] ?? $payment->cpay_payment_ref,
        ]);

        if ($success) {
            $payment->status = ShopPayment::STATUS_PAID;
            $payment->paid_at = now();
            $payment->failure_reason = null;
            $payment->save();

            $this->markPayableAsPaid($payment);
            $this->notifyAdmins($payment);
            $this->sendReceipts($payment);

            return $payment->fresh();
        }

        $payment->status = ShopPayment::STATUS_FAILED;
        $payment->failed_at = now();
        $payment->save();

        return $payment->fresh();
    }

    /**
     * A card payment lands without anyone watching, so the admin panel is told
     * about it. It must never be able to fail the payment itself — the money
     * has already moved.
     */
    private function notifyAdmins(ShopPayment $payment): void
    {
        try {
            $payable = $payment->payable;
            $isInvoice = $payable instanceof ShopInvoice;

            $what = $isInvoice
                ? 'фактура ' . $payable->invoice_number
                : 'нарачка ' . ($payable->order_number ?? '#' . $payment->payable_id);

            AdminNotification::notify(
                type: 'card_payment',
                title: 'Успешно плаќање со картичка',
                body: sprintf(
                    '%s плати %s %s за %s.',
                    $payment->clinic?->name ?? 'Ординација',
                    number_format((float) $payment->amount, 2),
                    $payment->currency,
                    $what,
                ),
                data: [
                    'payment_id' => $payment->id,
                    'reference' => $payment->reference,
                    'cpay_payment_ref' => $payment->cpay_payment_ref,
                    'amount' => (float) $payment->amount,
                    'payable_type' => class_basename($payment->payable_type),
                    'payable_id' => $payment->payable_id,
                ],
                link: '/admin/shop-payments',
            );
        } catch (\Throwable $e) {
            Log::error('[cpay] admin notification failed', [
                'payment' => $payment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Receipt to the clinic, heads-up to the admins. Like the notification,
     * neither may fail the payment — the card has already been charged.
     */
    private function sendReceipts(ShopPayment $payment): void
    {
        $payment->loadMissing('clinic', 'payable');

        $clinicEmail = $payment->clinic?->email;

        if ($clinicEmail) {
            try {
                Mail::to($clinicEmail)->send(new PaymentReceivedClinicMail($payment));
            } catch (\Throwable $e) {
                Log::error('[cpay] clinic receipt mail failed', [
                    'payment' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $adminEmail = config('app.shop_admin_email');

        if ($adminEmail) {
            try {
                Mail::to($adminEmail)->send(new PaymentReceivedAdminMail($payment));
            } catch (\Throwable $e) {
                Log::error('[cpay] admin payment mail failed', [
                    'payment' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function markPayableAsPaid(ShopPayment $payment): void
    {
        $payable = $payment->payable;

        if ($payable instanceof ShopOrder) {
            $payable->update(['payment_status' => 'paid']);

            return;
        }

        if ($payable instanceof ShopInvoice) {
            $payable->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_method' => 'Платежна картичка (cPay)',
            ]);
        }
    }

    /**
     * cPay accepts whole denars only, so the charged amount is rounded.
     */
    private function chargeableAmount(Model $payable): float
    {
        if ($override = config('cpay.test_amount_override')) {
            return (float) $override;
        }

        return round((float) $payable->total);
    }

    private function amountToPay(ShopPayment $payment): string
    {
        return (string) (int) round((float) $payment->amount * 100);
    }

    private function details1(Model $payable): string
    {
        $text = $payable instanceof ShopOrder
            ? 'Naracka ' . $payable->order_number
            : 'Profaktura ' . $payable->invoice_number;

        return $this->clean($text, 32) ?? 'GNA E-Shop';
    }

    /**
     * cPay restricts the character set and blocks anything that looks like an
     * injection attempt (quotes, doubled @). Keep it to plain text.
     */
    private function clean(?string $value, int $max, string $extra = ''): ?string
    {
        if ($value === null) {
            return null;
        }

        // cPay accepts Latin letters and digits only, so "Скопје" has to travel
        // as "Skopje" rather than be thrown away.
        $value = Str::ascii($value);
        $value = preg_replace('/[^0-9A-Za-z ' . preg_quote($extra, '/') . '-]/u', ' ', $value) ?? '';
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /**
     * An order's own delivery details win over the clinic's registration data.
     */
    private function preferOrder(mixed $payable, string $field, ?string $fallback): ?string
    {
        if ($payable instanceof ShopOrder && ! empty($payable->{$field})) {
            return (string) $payable->{$field};
        }

        return $fallback;
    }

    /**
     * cPay takes the cardholder's name in two fields. Macedonian contacts are
     * often stored with a title ("Д-р Иван Ивановски"), which is not a name.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function splitName(?string $value): array
    {
        $clean = $this->clean($value, 128);

        if ($clean === null) {
            return [null, null];
        }

        $parts = array_values(array_filter(
            explode(' ', $clean),
            fn (string $part) => ! preg_match('/^(d-?r|prof|m-?r|mag|mgr)\.?$/i', $part),
        ));

        if ($parts === []) {
            return [null, null];
        }

        $first = array_shift($parts);
        $last = implode(' ', $parts);

        return [
            mb_substr($first, 0, 64),
            $last === '' ? null : mb_substr($last, 0, 64),
        ];
    }

    /**
     * Digits only, with the country code, per ITU-E.164 (e.g. 38970220070).
     */
    private function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '0')) {
            $digits = '389' . ltrim($digits, '0');
        }

        return mb_substr($digits, 0, 15);
    }
}
