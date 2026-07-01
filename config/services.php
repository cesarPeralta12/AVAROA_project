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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS'),
    ],

    'whatchimp' => [
        'api_key' => env('WHATCHIMP_API_KEY'),
        'phone_number_id' => env('WHATCHIMP_PHONE_NUMBER_ID'),
        'base_url' => rtrim(env('WHATCHIMP_BASE_URL', 'https://app.whatchimp.com/api/v1'), '/'),
    ],
    'meta' => [
        'whatsapp' => [
            'access_token' => env('META_WHATSAPP_ACCESS_TOKEN'),
            'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
            'business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'),
            'api_version' => env('META_WHATSAPP_API_VERSION', 'v22.0'),
            'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
            'webhook_secret' => env('META_WHATSAPP_WEBHOOK_SECRET'),
        ],
    ],
    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'google' => [
        'maps_key' => env('GOOGLE_MAPS_API_KEY', ''),
    ],

    'firebase' => [
        'credentials' => env('FIREBASE_CREDENTIALS', ''),
    ],

    // WhatsApp Bot Admin Panel
    // Set WHATSAPP_PANEL_ENABLED=false in .env (or Coolify env vars) to hide the panel
    'whatsapp_panel' => [
        'enabled' => env('WHATSAPP_PANEL_ENABLED', true),
    ],
];
