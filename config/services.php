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

    // v2 wallet: one-time codes before a customer can spend their balance
    'vonage' => [
        'key' => env('VONAGE_KEY'),
        'secret' => env('VONAGE_SECRET'),
        'brand' => env('VONAGE_BRAND', 'QRide'),
    ],

    'otp' => [
        'driver' => env('OTP_DRIVER', 'vonage'),  // 'log' = no SMS, accepts test_code (refused in production)
        'test_code' => env('OTP_TEST_CODE', '1234'),
        // false (for now): a phone counts as verified as soon as it's entered, no SMS. Turn on before launch.
        'required' => (bool) env('OTP_REQUIRED', false),
    ],

    // v2 receipts: testing only. The same receipt can be claimed again and its printed time is ignored.
    // Never active in production (ReceiptController checks the environment too).
    'receipts' => [
        'test_mode' => (bool) env('RECEIPT_TEST_MODE', false),
    ],

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

];
