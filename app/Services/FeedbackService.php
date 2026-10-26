<?php

namespace App\Services;

use App\Mail\FeedbackReceived;
use App\Models\AppSetting;
use App\Models\Feedback;
use App\Models\User;
use App\Support\ClientContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * In-app feedback: stored, then emailed to the support address.
 *
 * The email goes out after the response has been sent, so a slow mail server
 * never makes the app wait, and no queue worker is needed. If sending fails
 * the message is still in the admin panel (`emailed_at` stays null).
 */
class FeedbackService
{
    public const EMAIL_SETTING = 'feedback_email';

    /**
     * @param  array{category: string, message: string, contact?: string|null}  $input
     */
    public function submit(User $user, array $input, ClientContext $client): Feedback
    {
        $feedback = Feedback::query()->create([
            'user_id' => $user->id,
            'category' => $input['category'],
            'message' => $input['message'],
            'contact' => filled($input['contact'] ?? null) ? $input['contact'] : null,
            'platform' => $client->platform !== '' ? $client->platform : null,
            'app_version' => $client->appVersion !== '' ? $client->appVersion : null,
        ]);

        dispatch(fn () => $this->email($feedback))->afterResponse();

        return $feedback;
    }

    public function email(Feedback $feedback): void
    {
        $to = $this->recipient();

        if ($to === null) {
            return;
        }

        try {
            Mail::to($to)->send(new FeedbackReceived($feedback->loadMissing('user')));
            $feedback->forceFill(['emailed_at' => now()])->save();
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * The address set on the admin Feedback page, else FEEDBACK_EMAIL /
     * ADMIN_EMAIL from the environment.
     */
    public function recipient(): ?string
    {
        $stored = AppSetting::query()->where('key', self::EMAIL_SETTING)->value('value');
        $address = is_string($stored) && $stored !== '' ? $stored : config('mail.feedback_to');

        return is_string($address) && filter_var($address, FILTER_VALIDATE_EMAIL) ? $address : null;
    }

    public function setRecipient(?string $email): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => self::EMAIL_SETTING],
            [
                'value' => $email ?? '',
                'group' => 'feedback',
                'description' => 'Where in-app feedback is emailed.',
            ],
        );
    }

    /**
     * @param  array{unread?: bool, category?: string|null}  $filters
     */
    public function adminSearch(array $filters = []): LengthAwarePaginator
    {
        return Feedback::query()
            ->with('user:id,name,email')
            ->when($filters['unread'] ?? false, fn ($query) => $query->whereNull('read_at'))
            ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
            ->latest('id')
            ->paginate(25);
    }

    public function unreadCount(): int
    {
        return Feedback::query()->whereNull('read_at')->count();
    }
}
