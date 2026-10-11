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

    'fonnte' => [
        'token' => env('FONNTE_TOKEN'),
        'endpoint' => env('FONNTE_ENDPOINT', 'https://api.fonnte.com/send'),
        'resolve_ip' => env('FONNTE_RESOLVE_IP'),
        'media_mode' => env('FONNTE_MEDIA_MODE', 'url'),
        'fake' => env('FONNTE_FAKE', false),
        /** Invoice WhatsApp: media (default) = attach image/PDF; link = text + signed page URL only (WhatsApp link preview / thumbnail). */
        'invoice_send_mode' => env('FONNTE_INVOICE_SEND_MODE', 'media'),
    ],

];
