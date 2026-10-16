<?php

namespace App\Mail;

use App\Models\ShopInvoice;
use App\Services\Shop\InvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin confirmed a bank transfer: the invoice, stamped paid, attached.
 * (Card payments have their own receipt, PaymentReceivedClinicMail.)
 */
class InvoicePaidMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopInvoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Фактурата ' . $this->invoice->invoice_number . ' е платена — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice.paid',
        );
    }

    public function attachments(): array
    {
        return [app(InvoiceDocument::class)->attachment($this->invoice)];
    }
}
