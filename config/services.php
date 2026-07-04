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

    'google_routes' => [
        'api_key' => env('GOOGLE_ROUTES_API_KEY'),
        'base_url' => env('GOOGLE_ROUTES_BASE_URL', 'https://routes.googleapis.com/directions/v2'),
        'timeout' => (int) env('GOOGLE_ROUTES_TIMEOUT', 15),
    ],

    'google_geocoding' => [
        'api_key' => env('GOOGLE_GEOCODING_API_KEY', env('GOOGLE_ROUTES_API_KEY')),
        'base_url' => env('GOOGLE_GEOCODING_BASE_URL', 'https://maps.googleapis.com/maps/api/geocode/json'),
        'timeout' => (int) env('GOOGLE_GEOCODING_TIMEOUT', 15),
    ],

    'firebase' => [
        'enabled' => (bool) env('FIREBASE_ENABLED', false),
        'queue' => (bool) env('FIREBASE_QUEUE', false),
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials' => env('FIREBASE_CREDENTIALS'),
        'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),
        'credentials_base64' => env('FIREBASE_CREDENTIALS_BASE64'),
        'timeout' => (int) env('FIREBASE_TIMEOUT', 15),
    ],

];
