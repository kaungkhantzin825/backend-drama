<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One message sent from the app's feedback screen.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $category
 * @property string $message
 * @property string|null $contact
 * @property string|null $platform
 * @property string|null $app_version
 * @property Carbon|null $emailed_at
 * @property Carbon|null $read_at
 * @property Carbon|null $created_at
 */
class Feedback extends Model
{
    public const CATEGORIES = [
        'bug' => 'Problem / bug',
        'payment' => 'Payment / withdrawal',
        'video' => 'Videos',
        'suggestion' => 'Suggestion',
        'other' => 'Other',
    ];

    protected $table = 'feedback';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'category',
        'message',
        'contact',
        'platform',
        'app_version',
        'emailed_at',
        'read_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'emailed_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? ucfirst($this->category);
    }
}
