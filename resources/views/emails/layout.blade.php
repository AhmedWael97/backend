<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('subject', 'EYE Notification')</title>
    <style>
        body {
            font-family: Inter, sans-serif;
            background: #f4f4f5;
            margin: 0;
            padding: 24px;
            color: #18181b;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            max-width: 560px;
            margin: 0 auto;
            padding: 40px 32px;
        }

        .logo {
            font-size: 22px;
            font-weight: 700;
            color: #6366f1;
            margin-bottom: 32px;
            display: block;
        }

        .btn {
            display: inline-block;
            background: #6366f1;
            color: #fff;
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 24px;
        }

        .footer {
            font-size: 12px;
            color: #71717a;
            margin-top: 32px;
            text-align: center;
        }

        .footer a {
            color: #71717a;
        }
    </style>
</head>

<body>
    <div class="card">
        <span class="logo">EYE Analytics</span>
        @yield('content')
        <div class="footer">
            &copy; {{ date('Y') }} EYE Analytics.
            @hasSection('unsubscribe')
                &nbsp;|&nbsp; <a href="@yield('unsubscribe')">Unsubscribe</a>
            @endif
        </div>
    </div>
</body>

</html>
