@extends('emails.layout')

@section('content')
    <h2>⚠ Alert: {{ $title }}</h2>
    <p>Hi {{ $name }},</p>
    <p>{{ $body }}</p>
    <a href="{{ $actionUrl }}" class="btn">View Dashboard</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
