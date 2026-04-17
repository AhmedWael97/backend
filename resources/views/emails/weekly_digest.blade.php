@extends('emails.layout')

@section('content')
    <h2>Your Weekly Analytics Summary</h2>
    <p>Hi {{ $name }}, here's what happened on your site this week:</p>
    <table style="width:100%;border-collapse:collapse;margin:16px 0;">
        <tr>
            <td style="padding:8px 0;color:#71717a;">Visitors</td>
            <td style="padding:8px 0;font-weight:600;">{{ number_format($visitors) }}</td>
        </tr>
        <tr>
            <td style="padding:8px 0;color:#71717a;">Sessions</td>
            <td style="padding:8px 0;font-weight:600;">{{ number_format($sessions) }}</td>
        </tr>
        <tr>
            <td style="padding:8px 0;color:#71717a;">Top Page</td>
            <td style="padding:8px 0;font-weight:600;">{{ $topPage }}</td>
        </tr>
        <tr>
            <td style="padding:8px 0;color:#71717a;">Top Country</td>
            <td style="padding:8px 0;font-weight:600;">{{ $topCountry }}</td>
        </tr>
        @if ($scoreDelta !== null)
            <tr>
                <td style="padding:8px 0;color:#71717a;">UX Score Change</td>
                <td style="padding:8px 0;font-weight:600;">{{ $scoreDelta > 0 ? '+' : '' }}{{ $scoreDelta }}</td>
            </tr>
        @endif
    </table>
    <a href="{{ $dashboardUrl }}" class="btn">View Full Dashboard</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
