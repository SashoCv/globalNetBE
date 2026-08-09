<?php

namespace App\Mail;

use App\Models\ShopOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нова нарачка ' . $this->order->order_number . ' — ' . ($this->order->clinic?->name ?? 'GNA E-Shop'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.new-order-admin',
        );
    }
}
