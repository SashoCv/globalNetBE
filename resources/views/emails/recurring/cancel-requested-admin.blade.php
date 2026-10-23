@extends('emails.layout')

@section('subject', 'Барање за откажување')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Барање за откажување на повеќемесечна нарачка</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Ординацијата <strong>{{ $recurring->clinic?->name }}</strong> бара да ја откаже повеќемесечната нарачка
        <strong>#{{ $recurring->id }}</strong> ({{ $recurring->monthsOrdered() }} од {{ $recurring->months_total }} месеци се нарачани).
    </p>

    @if($recurring->cancel_request_note)
        <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
            Причина: <em>{{ $recurring->cancel_request_note }}</em>
        </p>
    @endif

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.admin_url'), '/') }}/admin/shop-recurring-orders" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Отвори во админ панелот
                </a>
            </td>
        </tr>
    </table>
@endsection
