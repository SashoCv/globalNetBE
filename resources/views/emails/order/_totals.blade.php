{{-- Footer rows of an order's item table: how the item values add up to what is paid. --}}
@php
    $cell = 'padding:6px 12px; font-size:13px; color:#64748b;';
    $value = 'padding:6px 12px; font-size:13px; color:#334155;';
    $adjusted = (float) $order->surcharge_amount > 0 || (float) $order->discount_amount > 0 || (float) $order->wallet_applied > 0;
@endphp
@if($adjusted)
    <tr>
        <td colspan="2" style="{{ $cell }}">Производи</td>
        <td align="right" style="{{ $value }}">{{ $fmt($order->subtotal) }}</td>
    </tr>
    @if((float) $order->surcharge_amount > 0)
        <tr>
            <td colspan="2" style="{{ $cell }}">Надомест за итна нарачка</td>
            <td align="right" style="{{ $value }}">+{{ $fmt($order->surcharge_amount) }}</td>
        </tr>
    @endif
    @if((float) $order->discount_amount > 0)
        <tr>
            <td colspan="2" style="{{ $cell }}">Збирен попуст {{ rtrim(rtrim(number_format((float) $order->discount_percent, 2, ',', ''), '0'), ',') }}%</td>
            <td align="right" style="{{ $value }} color:#15803d;">−{{ $fmt($order->discount_amount) }}</td>
        </tr>
    @endif
    @if((float) $order->wallet_applied > 0)
        <tr>
            <td colspan="2" style="{{ $cell }}">Попуст (искористени поени)</td>
            <td align="right" style="{{ $value }} color:#15803d;">−{{ $fmt($order->wallet_applied) }}</td>
        </tr>
    @endif
@endif
<tr>
    <td colspan="2" style="padding:10px 12px; font-size:13px; font-weight:700; color:#0f172a; {{ $adjusted ? 'border-top:1px solid #e2e8f0;' : '' }}">Вкупно</td>
    <td align="right" style="padding:10px 12px; font-size:14px; font-weight:800; color:#0e7fac; {{ $adjusted ? 'border-top:1px solid #e2e8f0;' : '' }}">{{ $fmt($order->total) }}</td>
</tr>
