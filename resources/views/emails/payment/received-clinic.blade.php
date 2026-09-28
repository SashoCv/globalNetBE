@php
    $isInvoice = class_basename($payment->payable_type) === 'ShopInvoice';
    $target = $payment->payable;
    $number = $isInvoice ? ($target->invoice_number ?? null) : ($target->order_number ?? null);
    $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $payment->currency;
@endphp

@extends('emails.layout')

@section('subject', 'Плаќањето е успешно')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Плаќањето е успешно</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Ја примивме Вашата уплата со платежна картичка
        @if($number)
            за {{ $isInvoice ? 'про-фактурата' : 'нарачката' }} <strong>{{ $number }}</strong>.
        @else
            .
        @endif
        Ви благодариме.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%"
           style="border-collapse:collapse; margin:0 0 20px; background:#f1f9fd; border:1px solid #cdeaf8; border-radius:12px;">
        <tr>
            <td style="padding:16px 18px;">
                <p style="margin:0 0 4px; font-size:12px; color:#64748b;">Наплатен износ</p>
                <p style="margin:0; font-size:24px; font-weight:800; color:#0f172a;">{{ $fmt($payment->amount) }}</p>
            </td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 24px;">
        @if($number)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">{{ $isInvoice ? 'Про-фактура' : 'Нарачка' }}</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $number }}</td>
            </tr>
        @endif
        @if($payment->cpay_payment_ref)
            <tr>
                <td style="padding:7px 0; font-size:13px; color:#64748b;">Референца на трансакција</td>
                <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">{{ $payment->cpay_payment_ref }}</td>
            </tr>
        @endif
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Датум и време</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">
                {{ optional($payment->paid_at)->format('d.m.Y H:i') }}
            </td>
        </tr>
        <tr>
            <td style="padding:7px 0; font-size:13px; color:#64748b;">Начин на плаќање</td>
            <td align="right" style="padding:7px 0; font-size:13px; color:#0f172a; font-weight:700;">Платежна картичка</td>
        </tr>
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/{{ $isInvoice ? 'invoices' : 'orders' }}" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    {{ $isInvoice ? 'Погледни ги моите фактури' : 'Погледни ги моите нарачки' }}
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0; font-size:12px; line-height:1.7; color:#94a3b8;">
        Задолжувањето на картичката се врши во МКД. Податоците од Вашата картичка се
        внесени исклучиво на сигурната страница на банката — GNA E-Shop нема пристап до нив.
    </p>
@endsection
