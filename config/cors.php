<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Dashboard/admin frontend origins. The tracker endpoints set their own
    // Access-Control-Allow-Origin: * header directly in the controller, so
    // arbitrary customer sites are handled there without a global wildcard.
    'allowed_origins' => [
        'http://localhost:3000',
        'http://localhost:3001',
        'http://localhost:8000',
        'http://127.0.0.1:3000',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:8000',
        'https://www.eye-analsyis.live',
        'https://eye-analsyis.live',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    // Must be true for the dashboard SPA (sends credentials/cookies with requests).
    'supports_credentials' => true,

];
