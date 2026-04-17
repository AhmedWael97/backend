@extends('emails.layout')

@section('content')
    <h2>Your export is ready!</h2>
    <p>Hi {{ $name }}, your <strong>{{ strtoupper($format) }}</strong> export of
        <strong>{{ $exportType }}</strong> data is ready to download.</p>
    <p>This link expires in {{ $expiresIn }}.</p>
    <a href="{{ $downloadUrl }}" class="btn">Download Export</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
