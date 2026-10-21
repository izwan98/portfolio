<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'rone_arena' => [
        'url' => env('RONE_ARENA_URL', 'https://arena.rone.dev'),
        // Optional — the hero endpoints used by /mlbb-draft are public.
        'token' => env('RONE_ARENA_TOKEN'),
    ],

    'api_detective' => [
        // Let the request tester and discovery reach localhost / private networks.
        // Keep false anywhere the app is publicly reachable (SSRF).
        'allow_private_hosts' => env('API_DETECTIVE_ALLOW_PRIVATE_HOSTS', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
