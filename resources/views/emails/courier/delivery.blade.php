@extends('emails.layout')

@section('subject', 'Достава за ординација')

@section('content')
    @php
        $totalQty = 0;
        foreach ($groups as $g) { $totalQty += (int) ($g['total_quantity'] ?? 0); }
    @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Налог за достава</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Почитувани{{ $courierName ? ' ' . $courierName : '' }},
    </p>

    <p style="margin:0 0 18px; font-size:15px; line-height:1.7; color:#334155;">
        Ве молиме организирајте достава до следната ординација. Производите се групирани
        по добавувач (место на подигнување).
    </p>

    {{-- Destination clinic --}}
    <div style="margin:0 0 22px; padding:14px 16px; background:#f0fdfa; border:1px solid #99f6e4; border-radius:10px; font-size:14px; color:#134e4a; line-height:1.7;">
        <strong style="font-size:15px;">📍 {{ $clinic['name'] ?? '—' }}</strong><br>
        @if(!empty($clinic['address']) || !empty($clinic['city']))
            {{ trim(($clinic['address'] ?? '') . (!empty($clinic['address']) && !empty($clinic['city']) ? ', ' : '') . ($clinic['city'] ?? '')) }}<br>
        @endif
        @if(!empty($clinic['contact_person']))
            Контакт: {{ $clinic['contact_person'] }}@if(!empty($clinic['phone'])) · {{ $clinic['phone'] }}@endif
        @elseif(!empty($clinic['phone']))
            Тел: {{ $clinic['phone'] }}
        @endif
    </div>

    @if($note)
        <div style="margin:0 0 22px; padding:14px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; font-size:14px; color:#78350f; line-height:1.6;">
            <strong>Забелешка:</strong> {{ $note }}
        </div>
    @endif

    @foreach($groups as $g)
        @php $v = $g['vendor'] ?? []; @endphp
        <div style="margin:0 0 8px; font-size:14px; font-weight:700; color:#0f172a;">
            🏪 Подигни од: {{ $v['name'] ?? 'добавувач' }}
            @if(!empty($v['city'])) <span style="color:#94a3b8; font-weight:400;">· {{ $v['city'] }}</span>@endif
        </div>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 20px;">
            <thead>
                <tr>
                    <th align="left"  style="padding:9px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:8px 0 0 0;">Производ</th>
                    <th align="center" style="padding:9px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:0 8px 0 0;">Количина</th>
                </tr>
            </thead>
            <tbody>
                @foreach(($g['products'] ?? []) as $p)
                    <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                        <td style="padding:9px 12px; font-size:14px; color:#0f172a; border-bottom:1px solid #e2e8f0;">
                            {{ $p['product_name'] }}
                            @if(!empty($p['product_sku']))
                                <span style="color:#94a3b8; font-size:12px;"> · {{ $p['product_sku'] }}</span>
                            @endif
                        </td>
                        <td align="center" style="padding:9px 12px; font-size:15px; font-weight:700; color:#0e7fac; border-bottom:1px solid #e2e8f0;">
                            {{ $p['total_quantity'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach

    <p style="margin:0 0 4px; font-size:15px; font-weight:700; color:#0f172a;">
        Вкупно единици за достава: {{ $totalQty }}
    </p>
    <p style="margin:0; font-size:13px; line-height:1.6; color:#94a3b8;">
        Производите од добавувачи со сопствена достава не се вклучени тука.
    </p>
@endsection
