@php $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $invoice->currency; @endphp

@extends('emails.layout')

@section('subject', 'Фактурата е платена')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Фактурата е платена</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Ја евидентиравме Вашата уплата за фактурата <strong>{{ $invoice->invoice_number }}</strong>.
        Ви благодариме. Фактурата со ознака „ПЛАТЕНО“ е во прилог на оваа порака.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
           style="border-collapse:collapse; margin:0 0 20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px; font-size:12px; color:#64748b;">Платен износ</p>
                <p style="margin:0; font-size:24px; font-weight:800; color:#0f172a;">{{ $fmt($invoice->total) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 24px;">
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Фактура</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $invoice->invoice_number }}</td>
        </tr>
        @if($invoice->paid_at)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Датум на плаќање</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">
                    {{ $invoice->paid_at->setTimezone(config('shop.weekly.timezone'))->format('d.m.Y') }}
                </td>
            </tr>
        @endif
        @if($invoice->payment_method)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Начин на плаќање</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $invoice->payment_method }}</td>
            </tr>
        @endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/invoices" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Погледни ги моите фактури
                </a>
            </td>
        </tr>
    </table>
@endsection
