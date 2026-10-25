<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging
    |--------------------------------------------------------------------------
    |
    | Push delivery is optional. When `enabled` is false, or no service account
    | file is present, notifications are still written to the in-app inbox and
    | simply not pushed. That keeps local development and the test suite free
    | of Google credentials.
    |
    */

    'enabled' => (bool) env('FCM_ENABLED', false),

    'project_id' => env('FIREBASE_PROJECT_ID'),

    // Absolute path to the service-account JSON downloaded from the Firebase
    // console. Never commit this file.
    'credentials' => env('FIREBASE_CREDENTIALS'),

    'endpoint' => 'https://fcm.googleapis.com/v1/projects/%s/messages:send',

    'scope' => 'https://www.googleapis.com/auth/firebase.messaging',

    // Seconds to wait on Google before giving up. Push must never stall a
    // request, so the call is short and always runs on the queue.
    'timeout' => (int) env('FCM_TIMEOUT', 10),

    // Android notification channel created by the Flutter client.
    'android_channel' => env('FCM_ANDROID_CHANNEL', 'dramaloop_default'),

];
