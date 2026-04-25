<!DOCTYPE html>
<html lang="mk">
<head>
    <meta charset="UTF-8">
    <title>{{ $evaluation->title }} — Извештај</title>
    <style>
        @page { margin: 24mm 18mm; }
        * { font-family: DejaVu Sans, sans-serif; box-sizing: border-box; }
        body { font-size: 11pt; color: #0f172a; line-height: 1.45; margin: 0; }

        .header {
            border-bottom: 3px solid #7c3aed;
            padding-bottom: 14px;
            margin-bottom: 24px;
        }
        .header__top {
            width: 100%;
            margin-bottom: 8px;
        }
        .header__top td { vertical-align: top; }
        .header__brand {
            font-size: 9pt;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .header__date {
            font-size: 9pt;
            color: #94a3b8;
            text-align: right;
        }
        .header__title {
            font-size: 22pt;
            font-weight: bold;
            color: #6d28d9;
            margin: 6px 0 4px;
        }
        .header__meta {
            font-size: 10pt;
            color: #475569;
        }
        .header__meta strong { color: #0f172a; }

        .summary {
            background: #f5f3ff;
            border-radius: 6px;
            padding: 14px 18px;
            margin-bottom: 24px;
        }
        .summary table { width: 100%; border-collapse: collapse; }
        .summary td {
            text-align: center;
            padding: 4px;
        }
        .summary .num {
            font-size: 22pt;
            font-weight: bold;
            color: #7c3aed;
            line-height: 1;
        }
        .summary .lbl {
            font-size: 9pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-top: 4px;
        }

        .question {
            margin-bottom: 22px;
            page-break-inside: avoid;
        }
        .question__head {
            background: #f8fafc;
            border-left: 3px solid #7c3aed;
            padding: 8px 12px;
            margin-bottom: 10px;
        }
        .question__num {
            color: #7c3aed;
            font-weight: bold;
            margin-right: 6px;
        }
        .question__text {
            font-weight: bold;
            font-size: 11pt;
            color: #0f172a;
        }
        .question__meta {
            font-size: 9pt;
            color: #64748b;
            margin-top: 3px;
        }

        .bar-row {
            margin: 4px 0;
            page-break-inside: avoid;
        }
        .bar-row table { width: 100%; border-collapse: collapse; }
        .bar-row td { padding: 3px 0; vertical-align: middle; }
        .bar-row .label {
            width: 35%;
            font-size: 10pt;
            padding-right: 10px;
        }
        .bar-row .track {
            width: 50%;
            background: #e2e8f0;
            border-radius: 4px;
            height: 14px;
            position: relative;
        }
        .bar-row .fill {
            background: #7c3aed;
            height: 14px;
            border-radius: 4px;
        }
        .bar-row .count {
            width: 15%;
            font-size: 10pt;
            text-align: right;
            font-weight: bold;
            padding-left: 10px;
        }

        .rating-avg {
            display: inline-block;
            background: #fef3c7;
            border-radius: 6px;
            padding: 6px 14px;
            margin-bottom: 8px;
        }
        .rating-avg .num {
            font-size: 18pt;
            font-weight: bold;
            color: #d97706;
        }
        .rating-avg .scale {
            color: #b45309;
            font-size: 10pt;
        }

        .text-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .text-list li {
            background: #f8fafc;
            border-left: 3px solid #7c3aed;
            padding: 6px 10px;
            margin-bottom: 4px;
            font-size: 10pt;
            page-break-inside: avoid;
        }
        .empty {
            font-style: italic;
            color: #94a3b8;
            font-size: 9pt;
        }

        .footer {
            position: fixed;
            bottom: -16mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
        }
    </style>
</head>
<body>

<div class="header">
    <table class="header__top">
        <tr>
            <td class="header__brand">Global Net ADV — Извештај од евалуација</td>
            <td class="header__date">Генерирано: {{ now()->format('d.m.Y H:i') }}</td>
        </tr>
    </table>
    <div class="header__title">{{ $evaluation->title }}</div>
    <div class="header__meta">
        <strong>{{ $event->name }}</strong> · {{ $session->name }}
        @if($session->logo)
            <span style="color:#94a3b8;"> · логотип на штанд</span>
        @endif
    </div>
    @if($evaluation->description)
        <div style="margin-top: 8px; font-size: 10pt; color: #475569;">{{ $evaluation->description }}</div>
    @endif
</div>

<div class="summary">
    <table>
        <tr>
            <td>
                <div class="num">{{ $stats['total_responses'] }}</div>
                <div class="lbl">Вкупно одговори</div>
            </td>
            <td>
                <div class="num">{{ $stats['anonymous_count'] }}</div>
                <div class="lbl">Анонимни</div>
            </td>
            <td>
                <div class="num">{{ $stats['total_responses'] - $stats['anonymous_count'] }}</div>
                <div class="lbl">Со податоци</div>
            </td>
            <td>
                <div class="num">{{ count($stats['questions']) }}</div>
                <div class="lbl">Прашања</div>
            </td>
        </tr>
    </table>
</div>

@foreach($stats['questions'] as $idx => $q)
    <div class="question">
        <div class="question__head">
            <div class="question__text">
                <span class="question__num">{{ $idx + 1 }}.</span>{{ $q['question_text'] }}
            </div>
            <div class="question__meta">
                Тип: {{ $q['type'] }} · {{ $q['total_answers'] }} одговори
            </div>
        </div>

        @if(in_array($q['type'], ['radio', 'checkbox']) && !empty($q['option_counts']))
            @php $total = max(1, array_sum($q['option_counts'])); @endphp
            @foreach($q['option_counts'] as $opt => $count)
                @php $pct = round(($count / $total) * 100); @endphp
                <div class="bar-row">
                    <table>
                        <tr>
                            <td class="label">{{ $opt }}</td>
                            <td class="track"><div class="fill" style="width: {{ $pct }}%;"></div></td>
                            <td class="count">{{ $count }} ({{ $pct }}%)</td>
                        </tr>
                    </table>
                </div>
            @endforeach
        @elseif($q['type'] === 'rating')
            <div class="rating-avg">
                <span class="num">{{ $q['average'] ?? '—' }}</span>
                <span class="scale">/ {{ $q['scale'] }}</span>
            </div>
            @if(!empty($q['distribution']))
                @php $totalR = max(1, array_sum($q['distribution'])); @endphp
                @foreach($q['distribution'] as $n => $count)
                    @php $pct = round(($count / $totalR) * 100); @endphp
                    <div class="bar-row">
                        <table>
                            <tr>
                                <td class="label">{{ str_repeat('★', $n) }}{{ str_repeat('☆', $q['scale'] - $n) }}</td>
                                <td class="track"><div class="fill" style="width: {{ $pct }}%;"></div></td>
                                <td class="count">{{ $count }} ({{ $pct }}%)</td>
                            </tr>
                        </table>
                    </div>
                @endforeach
            @endif
        @else
            @if(!empty($q['answers']))
                <ul class="text-list">
                    @foreach($q['answers'] as $a)
                        <li>
                            @if(!$a['is_anonymous'] && $a['name'])
                                <strong style="color:#7c3aed;">{{ $a['name'] }}:</strong>
                            @else
                                <strong style="color:#94a3b8;">[Анонимно]:</strong>
                            @endif
                            {{ $a['value'] }}
                        </li>
                    @endforeach
                </ul>
            @else
                <div class="empty">Нема одговори.</div>
            @endif
        @endif
    </div>
@endforeach

@if($identified->count() > 0)
    <div style="page-break-before: always;"></div>
    <h2 style="font-size: 16pt; color: #6d28d9; border-bottom: 2px solid #7c3aed; padding-bottom: 6px; margin-bottom: 16px;">
        Идентификувани респонденти ({{ $identified->count() }})
    </h2>
    <p style="font-size: 9pt; color: #64748b; margin-bottom: 16px;">
        Список на не-анонимни одговори со целосна разбивка по прашање.
    </p>

    @foreach($identified as $i => $r)
        <div style="border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; margin-bottom: 10px; page-break-inside: avoid;">
            <div style="font-weight: bold; color: #0f172a; font-size: 11pt;">
                {{ $i + 1 }}. {{ $r['name'] }}
            </div>
            <div style="font-size: 9pt; color: #64748b; margin-bottom: 8px;">
                @if($r['email']) {{ $r['email'] }} @endif
                @if($r['phone']) · {{ $r['phone'] }} @endif
                · {{ \Carbon\Carbon::parse($r['submitted_at'])->format('d.m.Y H:i') }}
            </div>
            <table style="width: 100%; border-collapse: collapse; font-size: 9pt;">
                @foreach($r['answers'] as $ans)
                    <tr>
                        <td style="padding: 3px 6px 3px 0; color: #475569; vertical-align: top; width: 45%;">
                            {{ $ans['question'] }}
                        </td>
                        <td style="padding: 3px 0; color: #0f172a; vertical-align: top;">
                            {{ $ans['value'] ?? '—' }}
                        </td>
                    </tr>
                @endforeach
            </table>
        </div>
    @endforeach
@endif

<div class="footer">
    Global Net ADV · Скопје · globalnetadv.mk
</div>

</body>
</html>
