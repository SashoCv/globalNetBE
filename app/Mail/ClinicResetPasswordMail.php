<?php

namespace App\Mail;

use App\Models\ShopClinic;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClinicResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopClinic $clinic,
        public string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ресетирање на лозинка — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.clinic.reset-password',
        );
    }
}
