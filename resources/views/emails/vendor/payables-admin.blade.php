@extends('emails.layout')

@section('subject', 'За плаќање кон добавувачи')

@section('content')
    @php $fmt = fn ($n) => number_format((float) $n, 2, ',', '.') . ' MKD'; @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">За плаќање кон добавувачи</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        {{ $title }}. Платете до <strong>{{ $deadline }}</strong>. Влегуваат само нарачките што ординациите ги платиле.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; margin:0 0 22px;">
        <thead>
            <tr>
                <th align="left" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:8px 0 0 0;">Добавувач</th>
                <th align="center" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px;">Нарачки</th>
                <th align="center" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px;">Количина</th>
                <th align="right" style="padding:10px 12px; background:#0f172a; color:#fff; font-size:13px; border-radius:0 8px 0 0;">Износ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($payables as $p)
                <tr style="background:{{ $loop->even ? '#f8fafc' : '#ffffff' }};">
                    <td style="padding:10px 12px; font-size:14px; color:#0f172a; border-bottom:1px solid #e2e8f0;">{{ $p['vendor'] }}</td>
                    <td align="center" style="padding:10px 12px; font-size:14px; color:#334155; border-bottom:1px solid #e2e8f0;">{{ $p['orders'] }}</td>
                    <td align="center" style="padding:10px 12px; font-size:14px; color:#334155; border-bottom:1px solid #e2e8f0;">{{ $p['quantity'] }}</td>
                    <td align="right" style="padding:10px 12px; font-size:14px; font-weight:700; color:#0f172a; border-bottom:1px solid #e2e8f0;">{{ $fmt($p['total']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" style="padding:12px; font-size:14px; font-weight:700; color:#0f172a;">Вкупно</td>
                <td align="right" style="padding:12px; font-size:14px; font-weight:800; color:#0f172a;">{{ $fmt(array_sum(array_column($payables, 'total'))) }}</td>
            </tr>
        </tfoot>
    </table>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#94a3b8;">
        Износите се по набавни цени од системот. Проверете ги со про-фактурите од добавувачите.
    </p>
@endsection
