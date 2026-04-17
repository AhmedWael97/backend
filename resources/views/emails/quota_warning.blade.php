@extends('emails.layout')

@section('content')
    <h2>Quota Warning for {{ $domain }}</h2>
    <p>Hi {{ $name }}, you've used <strong>{{ $percent }}%</strong> of your daily event quota.</p>
    <p>Upgrade your plan to avoid data being dropped.</p>
    <a href="{{ $upgradeUrl }}" class="btn">Upgrade Plan</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
