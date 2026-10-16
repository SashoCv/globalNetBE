<?php

namespace App\Mail;

use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use App\Services\Shop\InvoiceDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

// The group discount is approved: the final amount and the payment deadline.
// Sent again as a reminder to clinics that still owe a few hours before it.
class WeeklyOrderPaymentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
        public ShopOrderCycle $cycle,
        public bool $reminder = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->reminder
                ? 'Потсетник: платете ја нарачката ' . $this->order->order_number . ' до 14:00 — GNA E-Shop'
                : 'Збирниот попуст е одобрен — нарачка ' . $this->order->order_number . ' — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly.order-payment',
        );
    }

    // The invoice now carries the final, discounted amount.
    public function attachments(): array
    {
        $invoice = $this->order->invoice;

        return $invoice ? [app(InvoiceDocument::class)->attachment($invoice)] : [];
    }
}
