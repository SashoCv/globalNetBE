<?php

namespace App\Services\Shop;

use App\Mail\InvoiceCancelledMail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoicePaidMail;
use App\Models\ShopInvoice;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Invoice mails to the clinic. Like every other shop mail, a failure is logged
 * and never undoes what already happened (the invoice exists, the money moved).
 */
class InvoiceMailer
{
    public function issued(ShopInvoice $invoice): void
    {
        $this->send($invoice, new InvoiceIssuedMail($invoice), 'issued');
    }

    public function paid(ShopInvoice $invoice): void
    {
        $this->send($invoice, new InvoicePaidMail($invoice), 'paid');
    }

    public function cancelled(ShopInvoice $invoice, bool $wasPaid): void
    {
        $this->send($invoice, new InvoiceCancelledMail($invoice, $wasPaid), 'cancelled');
    }

    private function send(ShopInvoice $invoice, Mailable $mail, string $what): void
    {
        $email = $invoice->clinic?->email;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send($mail);
        } catch (\Throwable $e) {
            Log::error("[invoice] {$what} mail failed", [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
