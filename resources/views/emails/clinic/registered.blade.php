@extends('emails.layout')

@section('subject', 'Вашата регистрација е примена')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Регистрацијата е примена</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Почитувани <strong>{{ $clinic->contact_person ?: $clinic->name }}</strong>,
    </p>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Ви благодариме за регистрацијата на <strong>{{ $clinic->name }}</strong> на GNA E-Shop.
        Вашето барање е успешно примено.
    </p>

    <div style="margin:0 0 24px; padding:16px 18px; background:#f0f9ff; border:1px solid #bae6fd; border-radius:10px;">
        <p style="margin:0; font-size:14px; line-height:1.7; color:#0c4a6e;">
            🔍 Нашиот тим ќе ја прегледа вашата ординација. По <strong>одобрување</strong>
            ќе добиете можност да се најавите и да нарачувате преку платформата.
        </p>
    </div>

    <p style="margin:0 0 8px; font-size:14px; color:#64748b;">Внесени податоци:</p>
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px; font-size:14px; color:#334155;">
        <tr><td style="padding:4px 0; color:#94a3b8; width:130px;">Ординација</td><td style="padding:4px 0;">{{ $clinic->name }}</td></tr>
        <tr><td style="padding:4px 0; color:#94a3b8;">Е-пошта</td><td style="padding:4px 0;">{{ $clinic->email }}</td></tr>
        @if($clinic->city)<tr><td style="padding:4px 0; color:#94a3b8;">Град</td><td style="padding:4px 0;">{{ $clinic->city }}</td></tr>@endif
        @if($clinic->phone)<tr><td style="padding:4px 0; color:#94a3b8;">Телефон</td><td style="padding:4px 0;">{{ $clinic->phone }}</td></tr>@endif
    </table>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#94a3b8;">
        Доколку имате прашања, контактирајте нè на
        <a href="mailto:{{ config('app.shop_admin_email') }}" style="color:#1ca6e0;">{{ config('app.shop_admin_email') }}</a>.
    </p>
@endsection
