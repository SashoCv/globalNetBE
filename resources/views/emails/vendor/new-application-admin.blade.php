@extends('emails.layout')

@section('subject', 'Нова апликација за добавувач')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Нова апликација за добавувач</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Нова компанија аплицираше да стане добавувач на GNA E-Shop и чека одобрување.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:0 0 24px; font-size:14px; color:#334155;">
        <tr><td style="padding:5px 0; color:#94a3b8; width:140px;">Компанија</td><td style="padding:5px 0;"><strong>{{ $vendor->name }}</strong></td></tr>
        @if($vendor->edb)<tr><td style="padding:5px 0; color:#94a3b8;">ЕДБ</td><td style="padding:5px 0;">{{ $vendor->edb }}</td></tr>@endif
        @if($vendor->contact_person)<tr><td style="padding:5px 0; color:#94a3b8;">Контакт лице</td><td style="padding:5px 0;">{{ $vendor->contact_person }}</td></tr>@endif
        <tr><td style="padding:5px 0; color:#94a3b8;">Е-пошта</td><td style="padding:5px 0;">{{ $vendor->email }}</td></tr>
        @if($vendor->phone)<tr><td style="padding:5px 0; color:#94a3b8;">Телефон</td><td style="padding:5px 0;">{{ $vendor->phone }}</td></tr>@endif
        @if($vendor->city)<tr><td style="padding:5px 0; color:#94a3b8;">Град</td><td style="padding:5px 0;">{{ $vendor->city }}</td></tr>@endif
        @if($vendor->website)<tr><td style="padding:5px 0; color:#94a3b8;">Веб страна</td><td style="padding:5px 0;">{{ $vendor->website }}</td></tr>@endif
        @if($vendor->description)<tr><td style="padding:5px 0; color:#94a3b8; vertical-align:top;">Опис</td><td style="padding:5px 0;">{{ $vendor->description }}</td></tr>@endif
    </table>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#0f172a;">
                <a href="{{ rtrim(config('app.url'), '/') }}/admin/shop-vendors" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Отвори во админ панел
                </a>
            </td>
        </tr>
    </table>
@endsection
