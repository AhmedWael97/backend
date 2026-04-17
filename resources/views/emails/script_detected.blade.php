@extends('emails.layout')

@section('content')
    <h2>Tracking is live on {{ $domain }}</h2>
    <p>Hi {{ $name }}, your EYE script has been detected and visitor data is now flowing in.</p>
    <a href="{{ $analyticsUrl }}" class="btn">View Analytics</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
