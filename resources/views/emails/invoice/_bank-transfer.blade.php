@php $company = config('shop.invoice.company'); @endphp
<table role="presentation" cellpadding="0" cellspacing="0" width="100%"
       style="border-collapse:collapse; margin:0 0 22px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px;">
    <tr>
        <td style="padding:14px 18px;">
            <p style="margin:0 0 8px; font-size:13px; font-weight:700; color:#0f172a;">Уплата на жиро-сметка</p>
            <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse; font-size:13px;">
                <tr><td style="padding:3px 0; color:#64748b;">Примач</td><td align="right" style="padding:3px 0; color:#0f172a;">{{ $company['name'] }} {{ $company['legal_form'] }}</td></tr>
                <tr><td style="padding:3px 0; color:#64748b;">Жиро-сметка</td><td align="right" style="padding:3px 0; color:#0f172a; font-weight:700;">{{ $company['bank_account'] }}</td></tr>
                <tr><td style="padding:3px 0; color:#64748b;">Банка</td><td align="right" style="padding:3px 0; color:#0f172a;">{{ $company['bank'] }}</td></tr>
                <tr><td style="padding:3px 0; color:#64748b;">Цел на дознака</td><td align="right" style="padding:3px 0; color:#0f172a; font-weight:700;">Фактура {{ $reference }}</td></tr>
            </table>
        </td>
    </tr>
</table>
