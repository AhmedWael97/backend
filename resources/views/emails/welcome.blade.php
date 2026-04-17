@extends('emails.layout')

@section('content')
    <h2>Welcome, {{ $name }}! 🎉</h2>
    <p>Your EYE Analytics account is ready. Start tracking your visitors in minutes.</p>
    <a href="{{ $dashboardUrl }}" class="btn">Go to Dashboard</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
