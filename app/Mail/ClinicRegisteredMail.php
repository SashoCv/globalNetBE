<?php

namespace App\Mail;

use App\Models\ShopClinic;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClinicRegisteredMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopClinic $clinic,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Вашата регистрација е примена — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.clinic.registered',
        );
    }
}
