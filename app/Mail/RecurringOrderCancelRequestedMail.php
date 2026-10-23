<?php

namespace App\Mail;

use App\Models\ShopRecurringOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RecurringOrderCancelRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopRecurringOrder $recurring,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Барање за откажување на повеќемесечна нарачка — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.recurring.cancel-requested-admin',
        );
    }
}
