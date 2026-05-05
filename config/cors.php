<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    // Dashboard/admin frontend origins. The tracker endpoints set their own
    // Access-Control-Allow-Origin: * header directly in the controller, so
    // arbitrary customer sites are handled there without a global wildcard.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    // Must be true for the dashboard SPA (sends credentials/cookies with requests).
    'supports_credentials' => true,

];
