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

    public const ORDER = 'order';
    // Reservations not paid yet: the vendor answers with a pro-invoice.
    public const RESERVATION = 'reservation';
    // The paid part of reservations the vendor already has — not a new order.
    public const CONFIRMATION = 'confirmation';

    /**
     * @param array $group Aggregated vendor group (vendor, products[], totals)
     */
    public function __construct(
        public ?ShopVendor $vendor,
        public array $group,
        public ?string $note = null,
        public string $kind = self::ORDER,
    ) {}

    public function envelope(): Envelope
    {
        $name = $this->group['vendor']['name'] ?? ($this->vendor?->name ?? 'добавувач');
        return new Envelope(
            subject: match ($this->kind) {
                self::RESERVATION => 'Збирна нарачка од GNA E-Shop — ',
                self::CONFIRMATION => 'Потврдена нарачка од GNA E-Shop — ',
                default => 'Нарачка од GNA E-Shop — ',
            } . $name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.vendor.procurement-order',
        );
    }
}
