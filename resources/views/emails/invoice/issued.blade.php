@php
    $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $invoice->currency;
    $ordersCount = $invoice->orders()->where('status', '!=', 'cancelled')->count();
@endphp

@extends('emails.layout')

@section('subject', 'Нова фактура')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Нова фактура</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Издадена е фактурата <strong>{{ $invoice->invoice_number }}</strong> за Вашите нарачки од
        {{ $invoice->period_from->format('d.m.Y') }} до {{ $invoice->period_to->format('d.m.Y') }}.
        Фактурата е во прилог на оваа порака.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
           style="border-collapse:collapse; margin:0 0 20px; background:#f1f9fd; border:1px solid #cdeaf8; border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px; font-size:12px; color:#64748b;">За плаќање</p>
                <p style="margin:0; font-size:24px; font-weight:800; color:#0f172a;">{{ $fmt($invoice->total) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 24px;">
        @if((float) $invoice->surcharge_amount > 0 || (float) $invoice->discount_amount > 0 || (float) $invoice->credit_amount > 0)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Производи</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a;">{{ $fmt($invoice->subtotal) }}</td>
            </tr>
            @if((float) $invoice->surcharge_amount > 0)
                <tr>
                    <td style="padding:7px 0; font-size:13px; color:#64748b;">Надомест за итна нарачка</td>
                    <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a;">+{{ $fmt($invoice->surcharge_amount) }}</td>
                </tr>
            @endif
            @if((float) $invoice->discount_amount > 0)
                <tr>
                    <td style="padding:7px 0; font-size:13px; color:#64748b;">Збирен попуст</td>
                    <td align="right" style="padding:7px 0; font-size:13px; color:#15803d;">−{{ $fmt($invoice->discount_amount) }}</td>
                </tr>
            @endif
            @if((float) $invoice->credit_amount > 0)
                <tr>
                    <td style="padding:7px 0; font-size:13px; color:#64748b;">Попуст (искористени поени)</td>
                    <td align="right" style="padding:7px 0; font-size:13px; color:#15803d;">−{{ $fmt($invoice->credit_amount) }}</td>
                </tr>
            @endif
        @endif
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Фактура</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $invoice->invoice_number }}</td>
        </tr>
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Број на нарачки</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $ordersCount }}</td>
        </tr>
        @if($invoice->due_at)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Рок за плаќање</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $invoice->due_at->format('d.m.Y') }}</td>
            </tr>
        @endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/invoices" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Плати со картичка
                </a>
            </td>
        </tr>
    </table>

    @include('emails.invoice._bank-transfer', ['reference' => $invoice->invoice_number])

    <p style="margin:0; font-size:12px; line-height:1.7; color:#94a3b8;">
        Фактурата можете да ја видите и преземете и во Вашиот профил, во делот „Фактури“.
    </p>
@endsection
