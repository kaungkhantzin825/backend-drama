<?php

namespace App\Services;

use App\Enums\LedgerType;
use App\Enums\NotificationType;
use App\Enums\WithdrawalStatus;
use App\Exceptions\RewardLimitException;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Cash-out workflow.
 *
 * Points leave the balance the moment a request is created (so they cannot be
 * spent twice while it is queued), and come back as a `withdrawal_refund` entry
 * if an admin rejects it. The original debit row is never touched.
 *
 * Every state change also writes an inbox notification, because the user has no
 * other way to learn the outcome of a payout.
 */
class WithdrawalService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Create a payout request.
     *
     * @param  array<string, mixed>  $accountDetails
     */
    public function request(User $user, string $method, array $accountDetails): Withdrawal
    {
        $minimum = (int) config('rewards.min_withdrawal_points');

        if ($user->points_balance < $minimum) {
            throw RewardLimitException::belowMinimum($minimum);
        }

        $dailyLimit = (int) config('rewards.daily_withdrawal_limit');
        $today = Withdrawal::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', now()->startOfDay())
            ->count();

        if ($today >= $dailyLimit) {
            throw RewardLimitException::dailyWithdrawals($dailyLimit);
        }

        $perUnit = max(1, (int) config('rewards.points_per_currency_unit'));
        $points = (int) $user->points_balance;

        return DB::transaction(function () use ($user, $method, $accountDetails, $points, $perUnit): Withdrawal {
            $this->wallet->debit(
                $user,
                LedgerType::Withdrawal,
                $points,
                'Withdrawal request',
                sprintf('withdrawal:%d:%s', $user->id, now()->timestamp),
                'withdrawal',
                null,
                ['method' => $method],
            );

            return Withdrawal::query()->create([
                'user_id' => $user->id,
                'points_amount' => $points,
                'currency' => 'USD',
                'amount_cents' => (int) round($points / $perUnit * 100),
                'method' => $method,
                'account_details' => $accountDetails,
                'status' => WithdrawalStatus::Pending->value,
            ]);
        });
    }

    /**
     * Admin action: approve a pending request (money not sent yet).
     */
    public function approve(Withdrawal $withdrawal, User $admin, ?string $note = null): Withdrawal
    {
        $this->assertOpen($withdrawal);

        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Approved->value,
            'admin_note' => $note,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ])->save();

        $withdrawal->refresh();

        $this->notifyOwner(
            $withdrawal,
            NotificationType::WithdrawalApproved,
            'Withdrawal approved',
            sprintf('Your %s payout was approved and is being prepared.', $withdrawal->displayAmount()),
        );

        return $withdrawal;
    }

    /**
     * Admin action: the payout was sent.
     */
    public function markPaid(Withdrawal $withdrawal, User $admin, ?string $note = null): Withdrawal
    {
        if (! in_array($withdrawal->status, [WithdrawalStatus::Approved, WithdrawalStatus::Pending], true)) {
            throw new RewardLimitException('Only a pending or approved withdrawal can be marked paid.');
        }

        $withdrawal->forceFill([
            'status' => WithdrawalStatus::Paid->value,
            'admin_note' => $note ?? $withdrawal->admin_note,
            'processed_by' => $admin->id,
            'processed_at' => now(),
        ])->save();

        $withdrawal->refresh();

        $this->notifyOwner(
            $withdrawal,
            NotificationType::WithdrawalPaid,
            'Withdrawal paid',
            sprintf('%s has been sent to your %s account.', $withdrawal->displayAmount(), $withdrawal->method),
        );

        return $withdrawal;
    }

    /**
     * Admin action: reject and refund the points.
     */
    public function reject(Withdrawal $withdrawal, User $admin, string $note): Withdrawal
    {
        $this->assertOpen($withdrawal);

        $withdrawal = DB::transaction(function () use ($withdrawal, $admin, $note): Withdrawal {
            $withdrawal->forceFill([
                'status' => WithdrawalStatus::Rejected->value,
                'admin_note' => $note,
                'processed_by' => $admin->id,
                'processed_at' => now(),
            ])->save();

            $user = User::query()->find($withdrawal->user_id);

            if ($user !== null) {
                $this->wallet->credit(
                    $user,
                    LedgerType::WithdrawalRefund,
                    (int) $withdrawal->points_amount,
                    'Withdrawal rejected — points refunded',
                    'refund:withdrawal:'.$withdrawal->id,
                    'withdrawal',
                    $withdrawal->id,
                );
            }

            return $withdrawal->refresh();
        });

        // Sent after the refund commits, so the user never sees "refunded"
        // before the points are actually back on their balance.
        $this->notifyOwner(
            $withdrawal,
            NotificationType::WithdrawalRejected,
            'Withdrawal rejected',
            sprintf(
                'Your %s request was rejected. %s points have been refunded to your balance. %s',
                $withdrawal->displayAmount(),
                number_format((int) $withdrawal->points_amount),
                $note,
            ),
        );

        return $withdrawal;
    }

    /**
     * Tell the requester what happened to their payout.
     *
     * Best-effort: a missing user must not break an admin action.
     */
    private function notifyOwner(
        Withdrawal $withdrawal,
        NotificationType $type,
        string $title,
        string $body,
    ): void {
        $owner = User::query()->find($withdrawal->user_id);

        if ($owner === null) {
            return;
        }

        $this->notifications->send($owner, $type, $title, trim($body), [
            'withdrawal_id' => $withdrawal->id,
            'status' => $withdrawal->status->value,
        ]);
    }

    /**
     * A user's own request history.
     */
    public function forUser(User $user): LengthAwarePaginator
    {
        return $user->withdrawals()->paginate(20);
    }

    /**
     * Admin queue.
     *
     * @param  array{status?: string|null}  $filters
     */
    public function adminSearch(array $filters = []): LengthAwarePaginator
    {
        return Withdrawal::query()
            ->with('user:id,name,email,points_balance')
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25);
    }

    private function assertOpen(Withdrawal $withdrawal): void
    {
        if (! $withdrawal->status->isOpen()) {
            throw new RewardLimitException('This withdrawal has already been processed.');
        }
    }
}
