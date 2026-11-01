{{-- Fixed footer on every page; must come first in <body> for dompdf. $labels: GroupDocumentLabels::make(). --}}
@php($FL = $labels ?? \App\Support\Edu\GroupDocumentLabels::make(false))
<div class="footer">
    <table>
        <tr>
            <td>{{ $FL['printed'] }}: {{ $printed_at ?? now()->format('d.m.Y') }}</td>
            <td style="text-align: right;">{{ $FL['page'] }} <span class="page"></span></td>
        </tr>
    </table>
</div>
