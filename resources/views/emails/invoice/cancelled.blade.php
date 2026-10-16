@extends('emails.layout')

@section('subject', 'Фактурата е сторнирана')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Фактурата е сторнирана</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Фактурата <strong>{{ $invoice->invoice_number }}</strong> е сторнирана и
        @if($wasPaid)
            повеќе не важи.
        @else
            не треба да се плаќа.
        @endif
        Доколку е потребно, ќе добиете нова фактура.
    </p>

    @if($wasPaid)
        <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
            Бидејќи фактурата веќе беше платена, ќе Ве контактираме за враќање или пренос на уплатениот износ.
        </p>
    @endif

    <p style="margin:0; font-size:13px; line-height:1.7; color:#64748b;">
        За прашања пишете ни на
        <a href="mailto:{{ \App\Support\ShopAdmin::contactEmail() }}" style="color:#1ca6e0;">{{ \App\Support\ShopAdmin::contactEmail() }}</a>.
    </p>
@endsection
