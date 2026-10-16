<?php

namespace App\Mail;

use App\Models\ShopInvoice;
use App\Models\ShopPayment;
use App\Services\Shop\InvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt for the clinic after a successful card payment, with the invoice
 * (now stamped paid) attached.
 */
class PaymentReceivedClinicMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopPayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Плаќањето е успешно — ' . $this->payment->details1 . ' · GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.received-clinic',
            with: ['invoice' => $this->invoice()],
        );
    }

    public function attachments(): array
    {
        $invoice = $this->invoice();

        return $invoice ? [app(InvoiceDocument::class)->attachment($invoice)] : [];
    }

    // The invoice that was paid, or the one an urgent/weekly order sits on.
    private function invoice(): ?ShopInvoice
    {
        $payable = $this->payment->payable;

        return $payable instanceof ShopInvoice ? $payable : $payable?->invoice;
    }
}
