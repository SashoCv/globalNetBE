@php $isServiceRequest = $order->items->isNotEmpty() && $order->items->every(fn ($i) => $i->kind === 'service'); @endphp

@extends('emails.layout')

@section('subject', $isServiceRequest ? 'Вашето барање е примено' : 'Нарачката е примена')

@section('content')
    @php $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $order->currency; @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">
        {{ $isServiceRequest ? 'Ви благодариме за барањето!' : 'Ви благодариме за нарачката!' }}
    </h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        @if($isServiceRequest)
            Го примивме Вашето барање <strong>{{ $order->order_number }}</strong> за услугата подолу.
            Нашиот тим ќе Ве контактира наскоро со понуда.
        @else
            Ја примивме Вашата нарачка <strong>{{ $order->order_number }}</strong>.
            @if($order->orderModel?->code === 'urgent')
                Ќе биде обработена итно, согласно избраниот модел на достава.
            @else
                Ќе биде вклучена во следната Про-Фактура за Вашата ординација.
            @endif
        @endif
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 20px;">
        <thead>
            <tr>
                <th align="left" style="padding:9px 12px; background:#0f172a; color:#fff; font-size:12px; border-radius:8px 0 0 0;">{{ $isServiceRequest ? 'Услуга' : 'Производ' }}</th>
                <th align="center" style="padding:9px 12px; background:#0f172a; color:#fff; font-size:12px; {{ $isServiceRequest ? 'border-radius:0 8px 0 0;' : '' }}">Кол.</th>
                @if(!$isServiceRequest)
                    <th align="right" style="padding:9px 12px; background:#0f172a; color:#fff; font-size:12px; border-radius:0 8px 0 0;">Вредност</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($order->items as $item)
                <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                    <td style="padding:9px 12px; font-size:13px; color:#0f172a; border-bottom:1px solid #e2e8f0;">{{ $item->product_name }}</td>
                    <td align="center" style="padding:9px 12px; font-size:13px; color:#0e7fac; font-weight:700; border-bottom:1px solid #e2e8f0;">{{ $item->quantity }}</td>
                    @if(!$isServiceRequest)
                        <td align="right" style="padding:9px 12px; font-size:13px; color:#334155; border-bottom:1px solid #e2e8f0;">{{ $fmt($item->subtotal) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
        @if(!$isServiceRequest)
            <tfoot>
                <tr>
                    <td colspan="2" style="padding:10px 12px; font-size:13px; font-weight:700; color:#0f172a;">Вкупно</td>
                    <td align="right" style="padding:10px 12px; font-size:14px; font-weight:800; color:#0e7fac;">{{ $fmt($order->total) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/orders" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Погледни ги моите нарачки
                </a>
            </td>
        </tr>
    </table>
@endsection
