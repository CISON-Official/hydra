<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute.
    |
    */

    'paths' => ['api/*', 'verify/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],

    'allowed_origins' => [
        'https://verify.yourdomain.com',
        'https://admin.yourdomain.com',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-API-Key',
        'X-Session-Token',
        'X-Request-ID',
    ],

    'exposed_headers' => [
        'X-Request-ID',
        'X-RateLimit-Remaining'
    ],

    'max_age' => 600,

    'supports_credentials' => true,

];