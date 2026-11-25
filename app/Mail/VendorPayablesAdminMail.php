<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * What to pay each vendor, for the orders the clinics paid.
 */
class VendorPayablesAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array{vendor: string, orders: int, quantity: int, total: float}>  $payables
     */
    public function __construct(
        public string $title,
        public string $deadline,
        public array $payables,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'За плаќање кон добавувачи — ' . $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vendor.payables-admin');
    }
}
