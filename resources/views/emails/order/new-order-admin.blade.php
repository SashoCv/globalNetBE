@extends('emails.layout')

@php $isServiceRequest = $order->items->isNotEmpty() && $order->items->every(fn ($i) => $i->kind === 'service'); @endphp

@section('subject', $isServiceRequest ? 'Барање за понуда' : 'Нова нарачка')

@section('content')
    @php $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' ' . $order->currency; @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">{{ $isServiceRequest ? 'Барање за понуда за услуга' : 'Нова нарачка' }}</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        @if($isServiceRequest)
            Ординацијата <strong>{{ $order->clinic?->name }}</strong> побара понуда за услугата подолу
            (барање <strong>{{ $order->order_number }}</strong>). Контактирајте ја ординацијата за да и
            испратите понуда со цена.
        @else
            Ординацијата <strong>{{ $order->clinic?->name }}</strong> направи нарачка
            <strong>{{ $order->order_number }}</strong>.
        @endif
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 22px; font-size:14px; color:#334155;">
        <tr><td style="padding:5px 0; color:#94a3b8; width:160px;">Ординација</td><td style="padding:5px 0;"><strong>{{ $order->clinic?->name }}</strong></td></tr>
        @if($order->clinic?->city)<tr><td style="padding:5px 0; color:#94a3b8;">Град</td><td style="padding:5px 0;">{{ $order->clinic->city }}</td></tr>@endif
        @if($order->clinic?->phone)<tr><td style="padding:5px 0; color:#94a3b8;">Телефон</td><td style="padding:5px 0;">{{ $order->clinic->phone }}</td></tr>@endif
        @if($order->clinic?->email)<tr><td style="padding:5px 0; color:#94a3b8;">Е-пошта</td><td style="padding:5px 0;">{{ $order->clinic->email }}</td></tr>@endif
        @if(!$isServiceRequest)<tr><td style="padding:5px 0; color:#94a3b8;">Модел</td><td style="padding:5px 0;">{{ $order->orderModel?->title ?? '—' }}</td></tr>@endif
        <tr><td style="padding:5px 0; color:#94a3b8;">Поднесена</td><td style="padding:5px 0;">{{ $order->placed_at?->format('d.m.Y H:i') }}</td></tr>
    </table>

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

    @if($isServiceRequest)
        <div style="margin:0 0 20px; padding:14px 16px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:10px; font-size:13px; color:#1e3a8a; line-height:1.6;">
            Услугите немаат фиксна цена во каталогот — јавете се на ординацијата за да ги договорите
            деталите и да и испратите понуда.
        </div>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#0f172a;">
                <a href="{{ rtrim(config('app.url'), '/') }}/admin/shop-orders" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Отвори во админ панел
                </a>
            </td>
        </tr>
    </table>
@endsection
