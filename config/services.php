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

    /*
    |--------------------------------------------------------------------------
    | Google Sign-In (mobile)
    |--------------------------------------------------------------------------
    |
    | Client IDs issued in Google Cloud Console → APIs & Services → Credentials.
    | The token received by the Flutter app is signed for one of these, and we
    | reject any token minted for a different application.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_id_ios' => env('GOOGLE_CLIENT_ID_IOS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (push notifications)
    |--------------------------------------------------------------------------
    |
    | Credentials are loaded from storage/app/firebase/firebase_credentials.json
    | in Phase 4 when withdrawal notifications are wired up.
    |
    */

    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials_path' => env('FCM_CREDENTIALS_PATH', 'firebase_credentials.json'),
    ],

];
