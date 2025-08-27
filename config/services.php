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
    'facebook' => [
    'page_id' => env('FACEBOOK_PAGE_ID'),
    'access_token' => env('FACEBOOK_ACCESS_TOKEN'),
    'app_url' => env('FACEBOOK_APP_URL', env('APP_URL', 'http://localhost:8000')),
],

'instagram' => [
    'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
],

'linkedin' => [
    'access_token' => env('LINKEDIN_ACCESS_TOKEN'),
    'user_id' => env('LINKEDIN_USER_ID'),
],
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

];
