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

    @if (count($findings))
        <h2 style="margin-top:24px;">What to do this week</h2>
        @foreach ($findings as $f)
            <div style="margin:12px 0;padding:12px 14px;border-left:3px solid {{ $f['severity'] === 'critical' ? '#ef4444' : ($f['severity'] === 'warning' ? '#f59e0b' : '#10b981') }};background:#fafafa;">
                <p style="margin:0 0 4px;font-weight:600;">{{ $f['title'] }} @if(!empty($f['domain']))<span style="color:#71717a;font-weight:400;">— {{ $f['domain'] }}</span>@endif</p>
                <p style="margin:0 0 6px;color:#52525b;">{{ $f['detail'] }}</p>
                <p style="margin:0;color:#4f46e5;font-weight:600;">→ {{ $f['action'] }}</p>
            </div>
        @endforeach
    @endif

    <a href="{{ $dashboardUrl }}" class="btn">View Full Dashboard</a>
@endsection

@section('unsubscribe', $unsubscribeUrl)
