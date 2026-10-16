<?php

namespace App\Mail;

use App\Models\ShopOrder;
use App\Services\Shop\InvoiceDocument;
use App\Services\Shop\ShopOrderSettlement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewOrderClinicMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ShopOrder $order,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Нарачката ' . $this->order->order_number . ' е примена — GNA E-Shop',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.order.new-order-clinic',
        );
    }

    /**
     * An urgent order is invoiced and payable the moment it is placed, so its
     * invoice comes with the confirmation. A weekly one waits for the group
     * discount — its invoice goes out with the final amount.
     */
    public function attachments(): array
    {
        $invoice = $this->order->invoice;

        if (! $invoice || ! ShopOrderSettlement::isUrgent($this->order->orderModel)) {
            return [];
        }

        return [app(InvoiceDocument::class)->attachment($invoice)];
    }
}
