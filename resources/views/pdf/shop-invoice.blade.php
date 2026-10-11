@php
    $money = fn ($v) => number_format((float) $v, 2, ',', '.');
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') . '%';
@endphp
<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>Фактура {{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 10mm 11mm 9mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 8pt; color: #1F2937; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        td, th { padding: 0; vertical-align: middle; }
        .right { text-align: right; }
        .center { text-align: center; }
        .bold { font-weight: bold; }

        /* Seller */
        .seller td { vertical-align: top; }
        .logo { width: 62mm; }
        .web { color: #0066CC; font-weight: bold; font-size: 7.5pt; padding-top: 2px; white-space: nowrap; }
        .brand { font-size: 17pt; font-weight: bold; color: #0A98D6; }
        .brand small { font-size: 9pt; color: #1F2937; }
        .seller__line { font-size: 8pt; color: #374151; padding-top: 4px; }
        .seller__line b { color: #1F2937; }

        /* Buyer / invoice meta */
        .parties { margin-top: 14px; }
        .parties > tbody > tr > td { vertical-align: top; }
        .bar { background: #0A98D6; color: #fff; font-weight: bold; text-align: center; padding: 4px 0; font-size: 8pt; }
        .title { color: #E2231A; font-size: 16pt; font-weight: bold; text-align: center; padding-left: 26mm; line-height: 1; padding-bottom: 3px; }
        .fields td { padding: 3px 5px; }
        .fields .label { font-weight: bold; text-align: right; white-space: nowrap; }
        .fields .value { background: #F3F4F6; border-bottom: 1.4px solid #1F2937; }
        .meta .label { border-bottom: 1.4px solid #1F2937; width: 26mm; }
        .meta .value { font-weight: bold; text-align: center; }

        /* Items */
        .items { margin-top: 16px; border: 1.4px solid #1F2937; }
        .items th { background: #0B3F6B; color: #fff; font-weight: bold; font-size: 7.5pt; padding: 6px 4px; border-left: 1px solid #C9D3DD; }
        .items th:first-child { border-left: 0; }
        .items td { padding: 3.2px 4px; border-left: 1px solid #C9D3DD; border-bottom: 1px solid #C9D3DD; height: 11px; }
        .items td:first-child { border-left: 0; font-weight: bold; }
        .items tr.alt td { background: #EEF6FC; }
        .items td.amount { font-weight: bold; }
        .items tr.minus td.name { font-style: italic; }

        /* Totals */
        .totals { margin-top: 12px; }
        .totals > tbody > tr > td { vertical-align: top; }
        .recap th { background: #0B3F6B; color: #fff; font-weight: bold; font-size: 7.5pt; padding: 5px 4px; border: 1px solid #1F2937; }
        .recap td { padding: 5px 4px; border: 1px solid #1F2937; }
        .sum td { padding: 5px 6px; border: 1px solid #1F2937; font-weight: bold; }
        .sum .label { text-align: center; width: 28mm; }
        .sum .value { background: #F3F4F6; text-align: right; }
        .sum tr.grand td { background: #0A98D6; color: #fff; font-size: 11pt; border: 1.6px solid #1F2937; padding: 6px; }

        .words { margin-top: 12px; border: 1px solid #1F2937; }
        .words td { padding: 3px 6px; }

        .state { margin-top: 6px; font-size: 8pt; font-weight: bold; }
        .state--paid { color: #15803D; }
        .state--cancelled { color: #E2231A; }

        .note { margin-top: 14px; text-align: center; color: #6B7280; font-style: italic; font-weight: bold; font-size: 8pt; line-height: 1.35; }

        .signs { margin-top: 30px; }
        .signs td { vertical-align: bottom; color: #6B7280; font-weight: bold; font-size: 7.5pt; }
        .signs .line { border-bottom: 1.4px solid #1F2937; height: 30px; }
        .signs .name { color: #1F2937; font-weight: normal; font-size: 8pt; text-align: center; padding-top: 3px; vertical-align: top; }

        .thanks { position: fixed; bottom: 0; left: 0; right: 0; border-top: 1px solid #E2231A; padding-top: 5px; text-align: center; color: #0A98D6; font-weight: bold; font-size: 9pt; }
    </style>
</head>
<body>
    <table class="seller">
        <tr>
            <td style="width: 50%;">
                <img class="logo" src="{{ $logo }}" alt="GNA e-shop">
                <div class="web">{{ $company['website'] }} &nbsp;•&nbsp; {{ $company['email'] }}</div>
            </td>
            <td class="right">
                <div class="brand">{{ $company['name'] }} <small>{{ $company['legal_form'] }}</small></div>
                <div class="seller__line">{{ $company['address'] }}</div>
                <div class="seller__line"><b>ЕДБ: {{ $company['edb'] }}</b></div>
                <div class="seller__line"><b>Жиро с/ка: {{ $company['bank_account'] }}</b></div>
                <div class="seller__line"><b>{{ $company['bank'] }}</b></div>
                <div class="seller__line"><b>тел: {{ $company['phone'] }}</b></div>
            </td>
        </tr>
    </table>

    <table class="parties">
        <tr>
            <td style="width: 54%;">
                <div class="bar">КУПУВАЧ</div>
                <table class="fields">
                    <tr><td class="label" style="width: 23%;">Назив:</td><td class="value bold">{{ $clinic->name }}</td></tr>
                    <tr><td class="label">Адреса:</td><td class="value">{{ collect([$clinic->address, $clinic->city])->filter()->implode(', ') }}</td></tr>
                    <tr><td class="label">ЕДБ:</td><td class="value">{{ $clinic->edb }}</td></tr>
                    <tr><td class="label">Контакт:</td><td class="value">{{ collect([$clinic->contact_person, $clinic->phone])->filter()->implode(' • ') }}</td></tr>
                </table>
            </td>
            <td style="width: 6%;"></td>
            <td>
                <div class="title">ФАКТУРА</div>
                <table class="fields meta">
                    <tr><td class="label">Фактура бр.:</td><td class="value">{{ $invoice->invoice_number }}</td></tr>
                    <tr><td class="label">Датум:</td><td class="value">{{ $issuedOn }}</td></tr>
                    <tr><td class="label">Валута:</td><td class="value">{{ $dueOn }}</td></tr>
                    <tr><td class="label">Нарачка бр.:</td><td class="value" style="font-size: {{ strlen($orderNumbers) > 30 ? '6.5pt' : '8pt' }};">{{ $orderNumbers }}</td></tr>
                    <tr><td class="label">Вид на нарачка</td><td class="value">{{ $orderKind }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 7%;">Р.бр.</th>
                <th>ОПИС НА ПРОИЗВОД / УСЛУГА</th>
                <th style="width: 7%;">Кол.</th>
                <th style="width: 15%;">Един. цена</th>
                <th style="width: 10%;">ДДВ %</th>
                <th style="width: 17%;">ИЗНОС</th>
            </tr>
        </thead>
        <tbody>
            @foreach($lines as $i => $line)
                <tr class="{{ $i % 2 ? 'alt' : '' }} {{ $line['qty'] === null ? 'minus' : '' }}">
                    <td>{{ $i + 1 }}</td>
                    <td class="name">{{ $line['name'] }}</td>
                    <td class="center">{{ $line['qty'] }}</td>
                    <td class="right">{{ $line['unit'] !== null ? $money($line['unit']) : '' }}</td>
                    <td class="center">{{ $line['rate'] !== null ? $pct($line['rate']) : '' }}</td>
                    <td class="right amount">{{ $money($line['amount']) }}</td>
                </tr>
            @endforeach
            @for($i = count($lines); $i < $minRows; $i++)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td>{{ $i + 1 }}</td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td style="width: 47%;">
                <table class="recap">
                    <tr><th>Стапка</th><th>Основица</th><th>Вкупно ДДВ</th></tr>
                    @foreach($vat as $row)
                        <tr>
                            <td class="center bold">{{ $pct($row['rate']) }}</td>
                            <td class="right bold">{{ $money($row['base']) }}</td>
                            <td class="right bold">{{ $money($row['vat']) }}</td>
                        </tr>
                    @endforeach
                </table>
            </td>
            <td style="width: 7%;"></td>
            <td>
                <table class="sum">
                    <tr><td class="label">ВКУПНО</td><td class="value">{{ $money($base) }} ден.</td></tr>
                    <tr><td class="label">ДДВ</td><td class="value">{{ $money($tax) }} ден.</td></tr>
                    <tr class="grand"><td class="label">ТОТАЛ</td><td class="right">{{ $money($total) }} ден.</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="words">
        <tr>
            <td class="bold" style="width: 17%; text-align: center;">Со зборови:</td>
            <td>{{ $inWords }}</td>
        </tr>
    </table>

    @if($invoice->status === 'paid')
        <div class="state state--paid">
            ПЛАТЕНО{{ $invoice->paid_at ? ' на ' . $invoice->paid_at->setTimezone(config('shop.weekly.timezone'))->format('d.m.Y') : '' }}{{ $invoice->payment_method ? ' · ' . $invoice->payment_method : '' }}
        </div>
    @elseif($invoice->status === 'cancelled')
        <div class="state state--cancelled">ФАКТУРАТА Е СТОРНИРАНА</div>
    @endif

    <div class="note">
        При доцнење во плаќањето се пресметува законска дневна казнена камата.<br>
        Рекламации се примаат во рок од {{ $complaintDays }} дена од приемот на фактурата.
    </div>

    <table class="signs">
        <tr>
            <td class="center" style="width: 37%;">Фактурирал</td>
            <td></td>
            <td class="center" style="width: 41%;">Овластен потписник</td>
        </tr>
        <tr>
            <td class="line"></td>
            <td class="center" style="font-weight: normal;">М.П.</td>
            <td class="line"></td>
        </tr>
        <tr>
            <td></td>
            <td></td>
            <td class="name">{{ $company['signatory'] }}</td>
        </tr>
    </table>

    <div class="thanks">Ви благодариме за довербата! &nbsp;•&nbsp; {{ $company['website'] }}</div>
</body>
</html>
