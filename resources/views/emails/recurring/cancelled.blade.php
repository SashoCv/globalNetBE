@extends('emails.layout')

@section('subject', 'Повеќемесечната нарачка е откажана')

@section('content')
    <h1 style="margin:0 0 16px; font-size:22px; color:#0f172a;">Повеќемесечната нарачка е откажана</h1>

    <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
        Вашата повеќемесечна нарачка <strong>#{{ $recurring->id }}</strong> е откажана.
        Од следниот месец нема автоматски да се нарачуваат производите од неа.
        Веќе направените месечни нарачки ({{ $recurring->monthsOrdered() }} од {{ $recurring->months_total }}) остануваат како што се.
    </p>

    @if($recurring->cancel_reason)
        <p style="margin:0 0 16px; font-size:15px; line-height:1.7; color:#334155;">
            Напомена: <em>{{ $recurring->cancel_reason }}</em>
        </p>
    @endif

    <p style="margin:0; font-size:13px; line-height:1.7; color:#64748b;">
        За прашања пишете ни на
        <a href="mailto:{{ \App\Support\ShopAdmin::contactEmail() }}" style="color:#1ca6e0;">{{ \App\Support\ShopAdmin::contactEmail() }}</a>.
    </p>
@endsection
