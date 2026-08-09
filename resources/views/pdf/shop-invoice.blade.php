<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>Фактура {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 22mm 18mm; }
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; }
        body { font-size: 10.5pt; color: #0f172a; line-height: 1.45; margin: 0; }

        .header { border-bottom: 3px solid #1ca6e0; padding-bottom: 14px; margin-bottom: 24px; }
        .header__top { width: 100%; margin-bottom: 8px; }
        .header__top td { vertical-align: top; }
        .header__brand { font-size: 16pt; font-weight: bold; color: #0f172a; }
        .header__brand span { color: #1ca6e0; }
        .header__title { font-size: 20pt; font-weight: bold; color: #0e7fac; text-align: right; margin: 0; }
        .header__number { font-size: 10pt; color: #64748b; text-align: right; }

        .meta { width: 100%; margin-bottom: 24px; }
        .meta td { vertical-align: top; width: 50%; }
        .meta__label { font-size: 8.5pt; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 4px; }
        .meta__value { font-size: 10.5pt; color: #0f172a; }
        .meta__value strong { display: block; font-size: 12pt; margin-bottom: 2px; }

        .status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 9pt; font-weight: bold; }
        .status--pending { background: #fef3c7; color: #b45309; }
        .status--paid { background: #dcfce7; color: #16a34a; }
        .status--overdue { background: #fee2e2; color: #b91c1c; }
        .status--cancelled { background: #f1f5f9; color: #475569; }

        table.items { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        table.items th { background: #0f172a; color: #fff; font-size: 9pt; text-align: left; padding: 8px 10px; }
        table.items th.num, table.items td.num { text-align: right; }
        table.items td { padding: 8px 10px; font-size: 9.5pt; border-bottom: 1px solid #e2e8f0; }
        table.items tr:nth-child(even) td { background: #f8fafc; }

        .totals { width: 260px; margin-left: auto; }
        .totals table { width: 100%; border-collapse: collapse; }
        .totals td { padding: 6px 0; font-size: 10pt; }
        .totals td.num { text-align: right; }
        .totals tr.grand td { border-top: 2px solid #0f172a; font-size: 12pt; font-weight: bold; padding-top: 10px; }

        .footer { margin-top: 40px; font-size: 8.5pt; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header__top">
            <tr>
                <td>
                    <div class="header__brand">GNA <span>E-Shop</span></div>
                </td>
                <td>
                    <p class="header__title">ФАКТУРА</p>
                    <p class="header__number">{{ $invoice->invoice_number }}</p>
                </td>
            </tr>
        </table>
    </div>

    <table class="meta">
        <tr>
            <td>
                <div class="meta__label">Издадена на</div>
                <div class="meta__value">{{ $invoice->clinic->name }}</div>
                @if($invoice->clinic->address)
                    <div class="meta__value">{{ $invoice->clinic->address }}@if($invoice->clinic->city), {{ $invoice->clinic->city }}@endif</div>
                @endif
                @if($invoice->clinic->email)
                    <div class="meta__value">{{ $invoice->clinic->email }}</div>
                @endif
                @if($invoice->clinic->edb)
                    <div class="meta__value">ЕДБ: {{ $invoice->clinic->edb }}</div>
                @endif
            </td>
            <td style="text-align: right;">
                <div class="meta__label">Период на фактурирање</div>
                <div class="meta__value">
                    {{ \Illuminate\Support\Carbon::parse($invoice->period_from)->format('d.m.Y') }}
                    —
                    {{ \Illuminate\Support\Carbon::parse($invoice->period_to)->format('d.m.Y') }}
                </div>
                <br>
                <div class="meta__label">Датум на издавање</div>
                <div class="meta__value">{{ $invoice->issued_at?->format('d.m.Y') }}</div>
                <br>
                @if($invoice->due_at)
                    <div class="meta__label">Краен рок за плаќање</div>
                    <div class="meta__value">{{ \Illuminate\Support\Carbon::parse($invoice->due_at)->format('d.m.Y') }}</div>
                    <br>
                @endif
                <span class="status status--{{ $invoice->status }}">
                    @switch($invoice->status)
                        @case('paid') ПЛАТЕНО @break
                        @case('overdue') ДОЦНИ @break
                        @case('cancelled') ОТКАЖАНА @break
                        @default НЕПЛАТЕНО
                    @endswitch
                </span>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th>Нарачка</th>
                <th>Датум</th>
                <th class="num">Меѓузбир</th>
                <th class="num">Доплата</th>
                <th class="num">Вкупно</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->orders as $order)
                <tr>
                    <td>{{ $order->order_number }}</td>
                    <td>{{ $order->placed_at?->format('d.m.Y') }}</td>
                    <td class="num">{{ number_format($order->subtotal, 2, ',', '.') }} {{ $invoice->currency }}</td>
                    <td class="num">{{ number_format($order->surcharge_amount, 2, ',', '.') }} {{ $invoice->currency }}</td>
                    <td class="num">{{ number_format($order->total, 2, ',', '.') }} {{ $invoice->currency }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totals">
        <table>
            <tr>
                <td>Меѓузбир</td>
                <td class="num">{{ number_format($invoice->subtotal, 2, ',', '.') }} {{ $invoice->currency }}</td>
            </tr>
            @if($invoice->surcharge_amount > 0)
                <tr>
                    <td>Доплата</td>
                    <td class="num">{{ number_format($invoice->surcharge_amount, 2, ',', '.') }} {{ $invoice->currency }}</td>
                </tr>
            @endif
            <tr class="grand">
                <td>Вкупно за плаќање</td>
                <td class="num">{{ number_format($invoice->total, 2, ',', '.') }} {{ $invoice->currency }}</td>
            </tr>
        </table>
    </div>

    <div class="footer">
        © {{ date('Y') }} Global Net Advertising · GNA E-Shop. Оваа фактура е генерирана автоматски врз основа на нарачките во наведениот период.
    </div>
</body>
</html>
