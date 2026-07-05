<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{ $heading ?? 'EYE Analytics' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;-webkit-font-smoothing:antialiased;font-family:Arial,Helvetica,sans-serif;">
  {{-- Preheader (hidden preview text) --}}
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader ?? '' }}</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e6e8eb;">

        {{-- Header --}}
        <tr><td style="padding:22px 32px;border-bottom:1px solid #eef0f2;">
          <span style="font-size:20px;font-weight:800;letter-spacing:-0.5px;color:#4f46e5;">EYE</span>
          <span style="font-size:11px;color:#9aa0a6;letter-spacing:2px;text-transform:uppercase;"> Analytics</span>
        </td></tr>

        {{-- Body --}}
        <tr><td style="padding:32px;color:#20242b;font-size:15px;line-height:1.6;">
          @isset($heading)
            <h1 style="margin:0 0 16px;font-size:22px;font-weight:800;color:#111318;">{{ $heading }}</h1>
          @endisset

          @isset($rawHtml)
            {!! $rawHtml !!}
          @else
            @foreach(($lines ?? []) as $line)
              <p style="margin:0 0 14px;color:#3c4149;">{!! $line !!}</p>
            @endforeach
          @endisset

          @isset($ctaUrl)
            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;">
              <tr><td style="border-radius:8px;background:#4f46e5;">
                <a href="{{ $ctaUrl }}" style="display:inline-block;padding:12px 26px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;border-radius:8px;">{{ $ctaText ?? 'Open EYE' }}</a>
              </td></tr>
            </table>
          @endisset

          @isset($replyNote)
            <p style="margin:20px 0 0;color:#6b7280;font-size:14px;">{!! $replyNote !!}</p>
          @endisset
        </td></tr>

        {{-- Footer --}}
        <tr><td style="padding:20px 32px;border-top:1px solid #eef0f2;color:#9aa0a6;font-size:12px;line-height:1.6;">
          EYE Analytics — privacy-first website analytics.<br>
          <a href="mailto:info@eye-analysis.online" style="color:#6b7280;">info@eye-analysis.online</a>
          @isset($unsubUrl)
            &nbsp;·&nbsp; <a href="{{ $unsubUrl }}" style="color:#9aa0a6;text-decoration:underline;">Unsubscribe</a>
          @endisset
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
