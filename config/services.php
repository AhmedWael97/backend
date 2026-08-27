<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'clickhouse' => [
        'host' => env('CLICKHOUSE_HOST', 'clickhouse'),
        'port' => env('CLICKHOUSE_PORT', 8123),
        'database' => env('CLICKHOUSE_DB', 'eye_analytics'),
        'user' => env('CLICKHOUSE_USER', 'eye'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
    ],

    'maxmind' => [
        'db_path' => env('MAXMIND_DB_PATH', storage_path('app/geoip/GeoLite2-City.mmdb')),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-3-5-sonnet-20241022'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o'),
        'base_url' => 'https://api.openai.com/v1',
    ],

    'ipinfo' => [
        'token' => env('IPINFO_TOKEN'),
    ],

    // Self-hosted GPU box (docker/comfyui/) for EYE's own social-post image
    // (SDXL-Turbo) and short video (SVD) generation. Not a per-user key —
    // one box we own, used for our own marketing content.
    'comfyui' => [
        'host' => env('COMFYUI_HOST'),
    ],

    // Failover order for AiTextService. Providers without a key are skipped.
    'ai' => [
        'order' => env('AI_PROVIDER_ORDER', 'anthropic,gemini,openai'),
    ],

    'support' => [
        'notify_email' => env('SUPPORT_NOTIFY_EMAIL', 'ahmed.wael010166@gmail.com'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        // 2.0-flash has a zeroed free tier on some projects; 2.5-flash works.
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL') . '/api/v1/auth/google/callback'),
    ],

    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URI', env('APP_URL') . '/api/v1/auth/facebook/callback'),
    ],

    'paymob' => [
        'api_key' => env('PAYMOB_API_KEY'),
        'integration_id' => env('PAYMOB_INTEGRATION_ID'),
        'iframe_id' => env('PAYMOB_IFRAME_ID'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        // Optional base URL override (region/mode). Default: accept.paymob.com.
        'base_url' => env('PAYMOB_BASE_URL', 'https://accept.paymob.com/api'),
    ],

    // Paddle (merchant of record — handles global tax/VAT). Per-mode keys are
    // normally managed in the super-admin Payment Methods page (like Paymob);
    // these env vars are just the fallback. See PaddleController::resolvePaddleConfig().
    'paddle' => [
        'client_token' => env('PADDLE_CLIENT_TOKEN'),
        'api_key' => env('PADDLE_API_KEY'),
        'webhook_secret' => env('PADDLE_WEBHOOK_SECRET'),
        // 'sandbox' or 'production' — must match which client_token/api_key pair is set.
        'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
    ],

    'currency' => [
        // Base plan prices are stored in USD. Egyptian visitors are shown — and
        // (via Paymob, which only accepts EGP) charged — in EGP at this rate.
        // 1 USD = CURRENCY_EGP_RATE EGP.
        'egp_rate' => (float) env('CURRENCY_EGP_RATE', 60),
    ],

    // GrowthBook (self-hosted or cloud) — experiment engine + rigorous stats.
    // EYE pulls experiments/results via the REST API and overlays its own revenue.
    'growthbook' => [
        'api_host' => env('GROWTHBOOK_API_HOST'),   // e.g. https://growthbook.your-domain.com
        'api_key' => env('GROWTHBOOK_API_KEY'),     // a GrowthBook REST API secret key
    ],

    // Convert.com (Convert Experiences) — A/B testing engine. EYE pulls
    // experiences/reports via the REST API and overlays its own revenue.
    'convert' => [
        'api_host' => env('CONVERT_API_HOST', 'https://api.convert.com/api/v2'),
        'application_id' => env('CONVERT_APPLICATION_ID'), // Convert account/application id
        'api_key' => env('CONVERT_API_KEY'),               // Convert REST API key/secret
        'account_id' => env('CONVERT_ACCOUNT_ID'),
    ],

    // TikTok Events API — server-side conversion send (complements the client
    // pixel; survives ad-blockers / in-app-browser quirks). No-ops entirely
    // if the access token isn't set. Pixel code must match tracker/[locale]/layout.tsx.
    'tiktok' => [
        'pixel_code' => env('TIKTOK_PIXEL_ID', 'D8VGP9BC77U550SVNMH0'),
        'access_token' => env('TIKTOK_EVENTS_ACCESS_TOKEN'), // from TikTok Ads Manager → Events → Web Events → Generate Access Token
    ],

    // Serper.dev — real Google SERP results API, powers automated keyword
    // rank tracking (SeoRankController / eye:fetch-seo-rankings). No-ops
    // (skips, doesn't error) when the key isn't set.
    'serper' => [
        'key' => env('SERPER_API_KEY'),
    ],

    // DataForSEO — richer than Serper's plain SERP results: search volume,
    // CPC, competition, keyword difficulty. Not used for rank-checking
    // (Serper already does that) — reserved for keyword-research enrichment.
    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_API_PASSWORD'),
        'basic_auth' => env('DATAFORSEO_API_BASE64_PASSWORD'), // base64(login:password), used directly as the Authorization header value
    ],

    // Google Places (Text Search) — optional lead-sourcing provider for
    // eye:source-leads. Billed per request; without a key the command falls
    // back to the free OpenStreetMap/Overpass provider.
    'google_places' => [
        'key' => env('GOOGLE_PLACES_API_KEY'),
    ],

];
