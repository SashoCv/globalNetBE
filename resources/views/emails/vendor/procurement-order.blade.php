@extends('emails.layout')

@section('subject', 'Нарачка од GNA E-Shop')

@section('content')
    @php
        $v = $group['vendor'] ?? [];
        $products = $group['products'] ?? [];
        $fmt = fn ($n) => number_format((float) $n, 0, ',', '.') . ' MKD';
    @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Нова нарачка</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Почитувани <strong>{{ $v['contact_person'] ?? $v['name'] ?? 'добавувач' }}</strong>,
    </p>

    <p style="margin:0 0 22px; font-size:15px; line-height:1.7; color:#334155;">
        Ве молиме обезбедете ги следните производи. Подолу се збирните количини
        нарачани преку GNA E-Shop.
    </p>

    @if($note)
        <div style="margin:0 0 22px; padding:14px 16px; background:#fffbeb; border:1px solid #fde68a; border-radius:10px; font-size:14px; color:#78350f; line-height:1.6;">
            <strong>Забелешка:</strong> {{ $note }}
        </div>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 22px;">
        <thead>
            <tr>
                <th align="left"  style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:8px 0 0 0;">Производ</th>
                <th align="center" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px;">Количина</th>
                <th align="right" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:0 8px 0 0;">Вредност</th>
            </tr>
        </thead>
        <tbody>
            @foreach($products as $p)
                <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                    <td style="padding:10px 12px; font-size:14px; color:#0f172a; border-bottom:1px solid #e2e8f0;">
                        {{ $p['product_name'] }}
                        @if(!empty($p['product_sku']))
                            <span style="color:#94a3b8; font-size:12px;"> · {{ $p['product_sku'] }}</span>
                        @endif
                    </td>
                    <td align="center" style="padding:10px 12px; font-size:15px; font-weight:700; color:#0e7fac; border-bottom:1px solid #e2e8f0;">
                        {{ $p['total_quantity'] }}
                    </td>
                    <td align="right" style="padding:10px 12px; font-size:14px; color:#334155; border-bottom:1px solid #e2e8f0;">
                        {{ $fmt($p['line_cost']) }}
                    </td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td style="padding:12px; font-size:14px; font-weight:700; color:#0f172a;">Вкупно</td>
                <td align="center" style="padding:12px; font-size:15px; font-weight:800; color:#0e7fac;">{{ $group['total_quantity'] ?? 0 }}</td>
                <td align="right" style="padding:12px; font-size:14px; font-weight:800; color:#0f172a;">{{ $fmt($group['total_cost'] ?? 0) }}</td>
            </tr>
        </tfoot>
    </table>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#94a3b8;">
        Доколку имате прашања за оваа нарачка, контактирајте нè на
        <a href="mailto:{{ config('app.shop_admin_email') }}" style="color:#1ca6e0;">{{ config('app.shop_admin_email') }}</a>.
    </p>
@endsection
