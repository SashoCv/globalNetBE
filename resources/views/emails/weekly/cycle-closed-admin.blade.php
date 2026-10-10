@extends('emails.layout')

@section('subject', 'Внесете збирен попуст')

@section('content')
    @php
        $weekly = app(\App\Services\Shop\WeeklyCycleService::class);
        $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' MKD';
    @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Неделната збирна нарачка {{ $cycle->code }} е затворена</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Нарачките до {{ $weekly->formatLocal($cycle->cutoff_at) }} се собрани. Внесете го дополнителниот
        збирен попуст во админ панелот до <strong>{{ $weekly->formatLocal($cycle->discount_due_at) }}</strong>.
        Ако не внесете попуст до тогаш, плаќањето се отвора без дополнителен попуст.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px; font-size:14px; color:#334155;">
        <tr><td style="padding:5px 0; color:#94a3b8; width:180px;">Нарачки</td><td style="padding:5px 0;"><strong>{{ $stats['orders_count'] }}</strong></td></tr>
        <tr><td style="padding:5px 0; color:#94a3b8;">Ординации</td><td style="padding:5px 0;"><strong>{{ $stats['clinics_count'] }}</strong></td></tr>
        <tr><td style="padding:5px 0; color:#94a3b8;">Вкупна вредност</td><td style="padding:5px 0;"><strong>{{ $fmt($stats['subtotal']) }}</strong></td></tr>
        <tr><td style="padding:5px 0; color:#94a3b8;">Рок за плаќање</td><td style="padding:5px 0;">{{ $weekly->formatLocal($cycle->payment_due_at) }}</td></tr>
        <tr><td style="padding:5px 0; color:#94a3b8;">Достава</td><td style="padding:5px 0;">{{ $weekly->formatDate($cycle->delivery_from) }} – {{ $weekly->formatDate($cycle->delivery_to) }}</td></tr>
    </table>
@endsection
