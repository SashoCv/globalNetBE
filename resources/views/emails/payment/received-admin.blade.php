@php
    $isInvoice = class_basename($payment->payable_type) === 'ShopInvoice';
    $target = $payment->payable;
    $number = $isInvoice ? ($target->invoice_number ?? null) : ($target->order_number ?? null);
    $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $payment->currency;
@endphp

@extends('emails.layout')

@section('subject', 'Примена уплата со картичка')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Примена уплата со картичка</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        <strong>{{ $payment->clinic?->name ?? 'Ординација' }}</strong>
        @if($payment->clinic?->city)
            ({{ $payment->clinic->city }})
        @endif
        изврши онлајн плаќање
        @if($number)
            за {{ $isInvoice ? 'про-фактурата' : 'нарачката' }} <strong>{{ $number }}</strong>.
        @else
            .
        @endif
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
           style="border-collapse:collapse; margin:0 0 20px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px; font-size:12px; color:#64748b;">Наплатен износ</p>
                <p style="margin:0; font-size:24px; font-weight:800; color:#15803d;">{{ $fmt($payment->amount) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 24px;">
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Ординација</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $payment->clinic?->name ?? '—' }}</td>
        </tr>
        @if($number)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">{{ $isInvoice ? 'Про-фактура' : 'Нарачка' }}</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $number }}</td>
            </tr>
        @endif
        @if($target?->total !== null && (float) $target->total !== (float) $payment->amount)
            {{-- cPay charges whole denars, so the paid amount can differ from the document. --}}
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Износ на документот</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#b45309; font-weight:700;">{{ $fmt($target->total) }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Наша референца</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $payment->reference }}</td>
        </tr>
        @if($payment->cpay_payment_ref)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">cPay референца</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $payment->cpay_payment_ref }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Датум и време</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">
                {{ optional($payment->paid_at)->format('d.m.Y H:i') }}
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#0f172a;">
                <a href="{{ rtrim(config('app.url'), '/') }}/admin/shop-payments" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Отвори ги плаќањата
                </a>
            </td>
        </tr>
    </table>
@endsection
