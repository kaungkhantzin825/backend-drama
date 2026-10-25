<?php

namespace App\Services\Push;

/**
 * Supplies an OAuth2 access token for the FCM HTTP v1 API.
 *
 * Kept behind an interface so the push sender can be tested without Google
 * credentials or network access.
 */
interface AccessTokenProvider
{
    /**
     * A bearer token valid for the `firebase.messaging` scope, or null when the
     * service account is missing or unreadable.
     */
    public function token(): ?string;
}
