<?php

namespace App\Mail;

use App\Models\ShopVendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewVendorApplicationAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopVendor $vendor,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нова апликација за добавувач — ' . $this->vendor->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor.new-application-admin',
        );
    }
}
