@extends('emails.layout')

@section('subject', 'Нова регистрација на ординација')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Нова регистрација на ординација</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Нова ординација се регистрираше на GNA E-Shop и чека одобрување.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px; font-size:14px; color:#334155;">
        <tr><td style="padding:5px 0; color:#94a3b8; width:140px;">Ординација</td><td style="padding:5px 0;"><strong>{{ $clinic->name }}</strong></td></tr>
        @if($clinic->edb)<tr><td style="padding:5px 0; color:#94a3b8;">ЕДБ</td><td style="padding:5px 0;">{{ $clinic->edb }}</td></tr>@endif
        @if($clinic->contact_person)<tr><td style="padding:5px 0; color:#94a3b8;">Контакт лице</td><td style="padding:5px 0;">{{ $clinic->contact_person }}</td></tr>@endif
        <tr><td style="padding:5px 0; color:#94a3b8;">Е-пошта</td><td style="padding:5px 0;">{{ $clinic->email }}</td></tr>
        @if($clinic->phone)<tr><td style="padding:5px 0; color:#94a3b8;">Телефон</td><td style="padding:5px 0;">{{ $clinic->phone }}</td></tr>@endif
        @if($clinic->city)<tr><td style="padding:5px 0; color:#94a3b8;">Град</td><td style="padding:5px 0;">{{ $clinic->city }}</td></tr>@endif
        @if($clinic->address)<tr><td style="padding:5px 0; color:#94a3b8;">Адреса</td><td style="padding:5px 0;">{{ $clinic->address }}</td></tr>@endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#0f172a;">
                <a href="{{ rtrim(config('app.url'), '/') }}" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Отвори во админ панел
                </a>
            </td>
        </tr>
    </table>
@endsection
