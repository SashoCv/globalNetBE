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
                @if($order->invoice && $order->payment_status === 'paid')
                    Нарачката е целосно покриена со Вашите поени. Фактурата <strong>{{ $order->invoice->invoice_number }}</strong> е во прилог.
                @elseif($order->invoice)
                    Итните нарачки се плаќаат однапред — фактурата <strong>{{ $order->invoice->invoice_number }}</strong> е во прилог.
                @endif
            @elseif($order->cycle)
                @php $weekly = app(\App\Services\Shop\WeeklyCycleService::class); @endphp
                Влегува во неделната збирна нарачка {{ $order->cycle->code }}. Збирниот попуст ќе Ви биде одобрен до
                {{ $weekly->formatLocal($order->cycle->discount_due_at) }}, кога ќе добиете е-пошта со конечниот износ.
                Плаќањето е исклучиво електронско, до {{ $weekly->formatLocal($order->cycle->payment_due_at) }}.
                Доставата е од {{ $weekly->formatDate($order->cycle->delivery_from) }}, во рок до 5 работни дена.
            @else
                Ќе биде вклучена во следната Про-Фактура за Вашата ординација.
            @endif
            @if($order->recurringOrder)
                @php
                    $recurring = $order->recurringOrder;
                    $nextRun = app(\App\Services\Shop\RecurringOrderService::class)->nextRunAt($recurring);
                @endphp
                <br><br>
                Ова е <strong>месец {{ $order->recurring_month }} од {{ $recurring->months_total }}</strong> на Вашата повеќемесечна нарачка.
                @if($nextRun)
                    Следната нарачка со истите производи автоматски ќе се направи на
                    <strong>{{ $nextRun->copy()->setTimezone(config('shop.weekly.timezone'))->format('d.m.Y') }} во {{ $nextRun->copy()->setTimezone(config('shop.weekly.timezone'))->format('H:i') }}</strong>,
                    по цена што не е повисока од денешната. Дотогаш можете да ги менувате количините и да додавате производи
                    во профилот, во делот „Повеќемесечни нарачки“.
                @else
                    Ова е последниот месец од повеќемесечната нарачка.
                @endif
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
                @include('emails.order._totals')
            </tfoot>
        @endif
    </table>

    @php $urgentToPay = $order->orderModel?->code === 'urgent' && $order->invoice && $order->payment_status === 'pending'; @endphp

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 {{ $urgentToPay ? '22px' : '0' }};">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/orders" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    {{ $urgentToPay ? 'Плати ја нарачката со картичка' : 'Погледни ги моите нарачки' }}
                </a>
            </td>
        </tr>
    </table>

    @if($urgentToPay)
        @include('emails.invoice._bank-transfer', ['reference' => $order->invoice->invoice_number])
    @endif
@endsection
