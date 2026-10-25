<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The mobile app's view of a user.
 *
 * Deliberately excludes sensitive fields (role, ban reason, IPs, device
 * fingerprints). The mobile client never needs them.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            'auth_provider' => $this->auth_provider->value,
            'is_guest' => $this->isGuest(),
            'points_balance' => (int) $this->points_balance,
            'email_verified' => $this->email_verified_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
