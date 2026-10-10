<?php

namespace App\Mail;

use App\Models\ShopOrder;
use App\Models\ShopOrderCycle;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyOrderCancelledMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
        public ShopOrderCycle $cycle,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нарачката ' . $this->order->order_number . ' е откажана — не е платена во рок',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly.order-cancelled',
        );
    }
}
