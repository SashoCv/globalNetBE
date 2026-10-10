@extends('emails.layout')

@section('subject', 'Нарачката е откажана')

@section('content')
    @php $weekly = app(\App\Services\Shop\WeeklyCycleService::class); @endphp

    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Нарачката е откажана</h1>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Нарачката <strong>{{ $order->order_number }}</strong> од неделната збирна нарачка {{ $cycle->code }}
        не беше платена до {{ $weekly->formatLocal($cycle->payment_due_at) }} и автоматски е откажана.
        Не влегува во доставата од {{ $weekly->formatDate($cycle->delivery_from) }}.
        @if((float) $order->wallet_applied > 0)
            Искористениот кредит е вратен во Вашиот паричник.
        @endif
    </p>

    <p style="margin:0 0 20px; font-size:15px; line-height:1.7; color:#334155;">
        Производите можете повторно да ги нарачате за следната неделна збирна нарачка,
        секој ден до понеделник до 12:00 часот.
    </p>

    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0;">
        <tr>
            <td align="center" style="border-radius:10px; background:#1ca6e0;">
                <a href="{{ rtrim(config('app.shop_url'), '/') }}/orders" target="_blank"
                   style="display:inline-block; padding:12px 28px; font-size:14px; font-weight:700; color:#ffffff; text-decoration:none; border-radius:10px;">
                    Погледни ги моите нарачки
                </a>
            </td>
        </tr>
    </table>
@endsection
