@extends('emails.layout')

@section('subject', 'Ресетирање на лозинка')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Ресетирање на лозинка</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Почитувани <strong>{{ $clinic->contact_person ?: $clinic->name }}</strong>,
    </p>

    <p style="margin:0 0 24px; font-size:15px; line-height:1.7; color:#334155;">
        Примивме барање за ресетирање на лозинката за вашиот профил на GNA E-Shop.
        Кликнете на копчето подолу за да поставите нова лозинка.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ $resetUrl }}" target="_blank"
                   style="display:inline-block; padding:14px 32px; font-size:15px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Ресетирај лозинка
                </a>
            </td>
        </tr>
    </table>

    <p style="margin:0 0 8px; font-size:13px; line-height:1.6; color:#64748b;">
        Доколку копчето не работи, копирајте го следниот линк во вашиот прелистувач:
    </p>
    <p style="margin:0 0 24px; font-size:13px; line-height:1.6; word-break:break-all;">
        <a href="{{ $resetUrl }}" style="color:#1ca6e0;">{{ $resetUrl }}</a>
    </p>

    <p style="margin:0; font-size:13px; line-height:1.6; color:#94a3b8;">
        Линкот важи <strong>1 час</strong>. Доколку вие не го побаравте ова ресетирање,
        слободно игнорирајте ја оваа порака — вашата лозинка останува непроменета.
    </p>
@endsection
