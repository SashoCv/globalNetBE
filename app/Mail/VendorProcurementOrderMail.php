<?php

namespace App\Mail;

use App\Models\ShopVendor;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VendorProcurementOrderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array $group Aggregated vendor group (vendor, products[], totals)
     */
    public function __construct(
        public ?ShopVendor $vendor,
        public array $group,
        public ?string $note = null,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->group['vendor']['name'] ?? ($this->vendor?->name ?? 'добавувач');
        return new Envelope(
            subject: 'Нарачка од GNA E-Shop — ' . $name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor.procurement-order',
        );
    }
}
