<?php

namespace App\Http\Resources;

use App\Models\Device;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A registered device, as returned by `GET /api/v1/me`.
 *
 * @mixin Device
 */
class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'device_id' => $this->device_id,
            'platform' => $this->platform->value,
            'app_version' => $this->app_version,
            'model' => $this->model,
            'os_version' => $this->os_version,
            'last_active_at' => $this->last_active_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
