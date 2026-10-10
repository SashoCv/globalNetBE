@extends('emails.layout')

@section('subject', $reminder ? 'Потсетник за плаќање' : 'Збирниот попуст е одобрен')

@section('content')
    @php
        $weekly = app(\App\Services\Shop\WeeklyCycleService::class);
        $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $order->currency;
        $owes = $order->payment_status !== 'paid' && (float) $order->total > 0;
    @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">
        {{ $reminder ? 'Нарачката сè уште не е платена' : 'Збирниот попуст е одобрен' }}
    </h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        @if($reminder)
            Нарачката <strong>{{ $order->order_number }}</strong> од неделната збирна нарачка {{ $cycle->code }}
            чека плаќање. Платете електронски до <strong>{{ $weekly->formatLocal($order->payment_due_at) }}</strong>,
            инаку нарачката автоматски се откажува и не влегува во доставата.
        @elseif($owes)
            Неделната збирна нарачка {{ $cycle->code }} е затворена и збирниот попуст е пресметан.
            Платете ја нарачката <strong>{{ $order->order_number }}</strong> електронски до
            <strong>{{ $weekly->formatLocal($order->payment_due_at) }}</strong>.
        @else
            Неделната збирна нарачка {{ $cycle->code }} е затворена. Нарачката <strong>{{ $order->order_number }}</strong>
            е целосно покриена со кредит од Вашиот паричник и нема износ за плаќање.
        @endif
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px; font-size:14px; color:#334155;">
        <tr><td style="padding:5px 0; color:#94a3b8; width:200px;">Износ на производите</td><td align="right" style="padding:5px 0;">{{ $fmt($order->subtotal) }}</td></tr>
        @if((float) $order->discount_amount > 0)
            <tr><td style="padding:5px 0; color:#94a3b8;">Збирен попуст {{ rtrim(rtrim(number_format((float) $order->discount_percent, 2, ',', ''), '0'), ',') }}%</td><td align="right" style="padding:5px 0; color:#15803d;">−{{ $fmt($order->discount_amount) }}</td></tr>
        @endif
        @if((float) $order->surcharge_amount > 0)
            <tr><td style="padding:5px 0; color:#94a3b8;">Надомест</td><td align="right" style="padding:5px 0;">{{ $fmt($order->surcharge_amount) }}</td></tr>
        @endif
        @if((float) $order->wallet_applied > 0)
            <tr><td style="padding:5px 0; color:#94a3b8;">Искористен кредит</td><td align="right" style="padding:5px 0;">−{{ $fmt($order->wallet_applied) }}</td></tr>
        @endif
        <tr><td style="padding:9px 0 5px; font-weight:700; color:#0f172a; border-top:1px solid #e2e8f0;">За плаќање</td><td align="right" style="padding:9px 0 5px; font-size:16px; font-weight:800; color:#0e7fac; border-top:1px solid #e2e8f0;">{{ $fmt($order->total) }}</td></tr>
        <tr><td style="padding:5px 0; color:#94a3b8;">Достава</td><td align="right" style="padding:5px 0;">од {{ $weekly->formatDate($cycle->delivery_from) }}, до {{ $weekly->formatDate($cycle->delivery_to) }}</td></tr>
    </table>

    @if($owes)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
            <tr>
                <td align="center" style="border-radius:10px; background:#1ca6e0;">
                    <a href="{{ rtrim(config('app.shop_url'), '/') }}/orders" target="_blank"
                       style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                        Плати ја нарачката
                    </a>
                </td>
            </tr>
        </table>
    @endif
@endsection
