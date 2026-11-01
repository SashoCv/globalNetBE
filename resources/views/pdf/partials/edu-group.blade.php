{{--
    One group of an Обуки program; included by pdf/edu-group and pdf/edu-groups.
    Expects $group, a plain array (the keys are listed in pdf/edu-group.blade.php).
    Labels come from $group['labels'] (GroupDocumentLabels::make(), bilingual
    "мк / sq" for Albanian groups); without them, the Macedonian ones.
--}}
@php
    $L = $group['labels'] ?? \App\Support\Edu\GroupDocumentLabels::make(false);
    $participants = array_values($group['participants'] ?? []);
    $schools = array_values($group['schools'] ?? []);
    $mode = $group['mode'] ?? ($participants !== [] ? 'persons' : 'counts');
    $signatureDays = array_values($group['signature_days'] ?? []);
    $blankRows = max(0, (int) ($group['blank_rows'] ?? 0));
    $total = $group['total'] ?? ($mode === 'persons'
        ? count($participants)
        : array_sum(array_map(fn ($s) => (int) ($s['count'] ?? 0), $schools)));
    $place = implode(', ', array_filter([$group['location'] ?? null, $group['address'] ?? null], fn ($v) => filled($v)));
    $when = implode(', ', array_filter([$group['dates'] ?? null, $group['time'] ?? null], fn ($v) => filled($v)));
    $meta = array_filter([
        $L['place'] => $place,
        $L['city'] => $group['city'] ?? null,
        $L['language'] => $group['language'] ?? null,
        $L['trainer'] => $group['trainer'] ?? null,
        $L['when'] => $when,
    ], fn ($v) => filled($v));

@endphp
<div class="head">
    <div class="head__program">{{ $group['program_title'] ?? '' }}</div>
    @if(filled($group['topic'] ?? null))
        <div class="head__topic">{{ $L['topic'] }}: {{ $group['topic'] }}</div>
    @endif
    <div class="head__group">{{ \App\Support\Edu\GroupDocumentLabels::number($L['group'], $group['number'] ?? '') }}</div>
    @if(filled($group['staff_label'] ?? null))
        <div class="head__staff">{{ $group['staff_label'] }}</div>
    @endif
</div>

@if($meta)
    <table class="meta">
        @foreach($meta as $label => $value)
            <tr>
                <td class="label">{{ $label }}:</td>
                <td class="value">{{ $value }}</td>
            </tr>
        @endforeach
    </table>
@endif

@if($mode === 'persons')
    <table class="list{{ empty($group['bilingual']) ? '' : ' list--bi' }}">
        <thead>
            <tr>
                <th class="no">{{ $L['no'] }}</th>
                <th class="name">{{ $L['name'] }}</th>
                <th>{{ $L['school'] }}</th>
                <th>{{ $L['municipality'] }}</th>
                <th class="contact">{{ $L['contact'] }}</th>
                @foreach($signatureDays as $day)
                    <th class="sign">{{ $L['signature'] }} {{ $day }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($participants as $i => $p)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $p['name'] ?? '' }}</td>
                    <td>{{ $p['school'] ?? '' }}</td>
                    <td>{{ $p['municipality'] ?? '' }}</td>
                    <td>
                        {{ $p['email'] ?? '' }}
                        @if(filled($p['phone'] ?? null))
                            <br><span class="muted">{{ $p['phone'] }}</span>
                        @endif
                    </td>
                    @foreach($signatureDays as $day)
                        <td class="sign"></td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 5 + count($signatureDays) }}" class="empty">{{ $L['no_participants'] }}</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="{{ 5 + count($signatureDays) }}">{{ $L['total_participants'] }}: {{ $total }}</td>
            </tr>
        </tbody>
    </table>
@else
    <table class="list{{ empty($group['bilingual']) ? '' : ' list--bi' }}">
        <thead>
            <tr>
                <th class="no">{{ $L['no'] }}</th>
                <th>{{ $L['school'] }}</th>
                <th>{{ $L['municipality'] }}</th>
                <th class="count">{{ $L['count'] }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse($schools as $i => $s)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="no">{{ $i + 1 }}</td>
                    <td>{{ $s['school'] ?? '' }}</td>
                    <td>{{ $s['municipality'] ?? '' }}</td>
                    <td class="count">{{ $s['count_label'] ?? ($s['count'] ?? 0) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">{{ $L['no_schools'] }}</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="3">{{ $L['total'] }}</td>
                <td class="count">{{ $total }}</td>
            </tr>
        </tbody>
    </table>

    @if($blankRows > 0)
        <div class="section">{{ $L['participants_list'] }}</div>
        <table class="list{{ empty($group['bilingual']) ? '' : ' list--bi' }}">
            <thead>
                <tr>
                    <th class="no">{{ $L['no'] }}</th>
                    <th class="name">{{ $L['name'] }}</th>
                    <th>{{ $L['school'] }}</th>
                    <th>{{ $L['municipality'] }}</th>
                    <th class="contact">{{ $L['contact'] }}</th>
                    @foreach($signatureDays as $day)
                        <th class="sign">{{ $L['signature'] }} {{ $day }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @for($i = 1; $i <= $blankRows; $i++)
                    <tr class="blank">
                        <td class="no">{{ $i }}</td>
                        <td></td>
                        <td></td>
                        <td></td>
                        <td></td>
                        @foreach($signatureDays as $day)
                            <td class="sign"></td>
                        @endforeach
                    </tr>
                @endfor
            </tbody>
        </table>
    @endif
@endif
