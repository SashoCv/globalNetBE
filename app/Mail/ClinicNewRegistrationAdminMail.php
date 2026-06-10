<?php

namespace App\Mail;

use App\Models\ShopClinic;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClinicNewRegistrationAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopClinic $clinic,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нова регистрација на ординација — ' . $this->clinic->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.clinic.new-registration-admin',
        );
    }
}
