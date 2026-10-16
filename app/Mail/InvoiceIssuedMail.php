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
 * The monthly invoice is out: amount, due date, how to pay, the PDF attached.
 */
class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopInvoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нова фактура ' . $this->invoice->invoice_number . ' — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice.issued',
        );
    }

    public function attachments(): array
    {
        return [app(InvoiceDocument::class)->attachment($this->invoice)];
    }
}
