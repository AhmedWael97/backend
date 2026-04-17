@extends('emails.layout')

@section('content')
    <h2>Subscription Updated</h2>
    <p>Hi {{ $name }}, your subscription has changed from <strong>{{ $oldPlan }}</strong> to
        <strong>{{ $newPlan }}</strong>, effective {{ $effectiveDate }}.</p>
    <a href="{{ $billingUrl }}" class="btn">View Billing</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
