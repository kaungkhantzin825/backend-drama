<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A drama saved to a viewer's list.
 *
 * @property int $id
 * @property int $user_id
 * @property int $drama_id
 */
class Favorite extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = ['user_id', 'drama_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drama(): BelongsTo
    {
        return $this->belongsTo(Drama::class);
    }
}
