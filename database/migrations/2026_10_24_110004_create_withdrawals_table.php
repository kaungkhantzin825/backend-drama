<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cash-out requests.
     *
     * Points are debited from the ledger the moment the request is created, so
     * a user cannot spend the same points twice while waiting for review. If
     * the request is rejected, `WithdrawalService` credits them back with a
     * `withdrawal_refund` ledger entry — the original debit is never edited.
     */
    public function up(): void
    {
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('points_amount');

            // Payout currency and the converted amount, in minor units (cents).
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('amount_cents');

            // 'paypal' | 'bank' | 'giftcard'
            $table->string('method', 16);

            // Where to send the money (paypal email, IBAN, ...). Sensitive.
            $table->json('account_details')->nullable();

            // 'pending' | 'approved' | 'rejected' | 'paid'
            $table->string('status', 16)->default('pending');

            $table->text('admin_note')->nullable();

            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
    }
};
