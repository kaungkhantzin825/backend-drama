<?php

namespace App\Http\Resources;

use App\Models\Drama;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full video detail: the list shape plus the full synopsis, the trailer and
 * the viewer's "my list" state.
 *
 * @mixin Drama
 */
class DramaDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge((new DramaResource($this->resource))->toArray($request), [
            'synopsis' => $this->synopsis,
            'trailer_url' => $this->trailer_url,
            // Only set for signed-in callers; anonymous viewers have no list.
            'is_favorite' => (bool) ($this->is_favorite ?? false),
        ]);
    }
}
