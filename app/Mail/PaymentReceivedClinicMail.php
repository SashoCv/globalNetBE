<?php

namespace App\Mail;

use App\Models\ShopPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Receipt for the clinic after a successful card payment.
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
        );
    }
}
