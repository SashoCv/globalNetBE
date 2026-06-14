<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CourierDeliveryMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array  $clinic  ['name','city','address','phone','contact_person']
     * @param array  $groups  list of ['vendor'=>['name',...], 'products'=>[...], 'total_quantity'=>int]
     */
    public function __construct(
        public array $clinic,
        public array $groups,
        public ?string $courierName = null,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Достава за ' . ($this->clinic['name'] ?? 'ординација') . ' — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.courier.delivery',
        );
    }
}
