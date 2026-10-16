<?php

namespace App\Mail;

use App\Models\ShopInvoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The admin cancelled an invoice. No PDF: its orders have already been taken
 * off it, so there is nothing left to print.
 */
class InvoiceCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopInvoice $invoice,
        public bool $wasPaid = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Фактурата ' . $this->invoice->invoice_number . ' е сторнирана — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.invoice.cancelled',
        );
    }
}
