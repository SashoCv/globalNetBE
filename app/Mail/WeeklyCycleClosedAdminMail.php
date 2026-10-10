<?php

namespace App\Mail;

use App\Models\ShopOrderCycle;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyCycleClosedAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, int|float>  $stats
     */
    public function __construct(
        public ShopOrderCycle $cycle,
        public array $stats,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Неделна збирна нарачка ' . $this->cycle->code . ' — внесете збирен попуст',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.weekly.cycle-closed-admin',
        );
    }
}
