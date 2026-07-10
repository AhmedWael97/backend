@extends('emails.layout')

@section('content')
    <h2>Your trial ends in {{ $daysLeft }} days</h2>
    <p>Hi {{ $name }}, your 30-day free trial of EYE wraps up soon. After that, your dashboard, analytics, and tools will pause until you pick a plan.</p>
    @if ($visitors > 0)
        <p>So far you've tracked <strong>{{ number_format($visitors) }} visitors</strong> — don't lose access to that data.</p>
    @endif
    <a href="{{ $billingUrl }}" class="btn">Choose a plan</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
