<?php

namespace App\Mail;

use App\Models\ShopPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Heads-up for the admins that a clinic has paid by card.
 */
class PaymentReceivedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopPayment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Примена уплата со картичка — '
                . ($this->payment->clinic?->name ?? 'ординација')
                . ' · GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.payment.received-admin',
        );
    }
}
