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
        // Set to 'api.eu.mailgun.net' for EU-region accounts. Overridable
        // at runtime from Admin → Settings → Email (system_settings table).
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => env('MAILGUN_SCHEME', 'https'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'google_maps' => [
        // Server-side key — used by GeocodingService / RouteCalculationService /
        // TripCostEstimator for backend calls (Geocoding, Directions). Safe to
        // lock down by IP address (the server's IP) in Google Cloud Console.
        'api_key' => env('GOOGLE_MAPS_API_KEY'),

        // Browser key — injected into the Maps JavaScript API <script> tag that
        // runs in the end-user's browser (map display + Places autocomplete).
        // Google authenticates browser keys by HTTP referrer, NOT IP, so this
        // key must be restricted by HTTP referrers (your domain) — an IP
        // restriction can't work for in-browser traffic. Falls back to the
        // server key when unset so existing single-key setups keep working.
        'browser_api_key' => env('GOOGLE_MAPS_BROWSER_API_KEY'),
    ],

    // Base URL used for public verification links (QR codes on collection notes).
    // Leave blank to fall back to APP_URL. Useful during domain cutovers when
    // the app is reachable on multiple hostnames.
    'collection_note_public_url' => env('COLLECTION_NOTE_PUBLIC_URL'),

];
