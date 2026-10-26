<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app feedback. Each message is also emailed to the support address;
     * the table keeps a copy so nothing is lost if the mail fails.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // 'bug' | 'payment' | 'video' | 'suggestion' | 'other'
            $table->string('category', 16)->default('other');
            $table->text('message');

            // Optional reply address or phone number the user typed in.
            $table->string('contact', 190)->nullable();

            // Device context, for reproducing bugs.
            $table->string('platform', 16)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestamp('emailed_at')->nullable();
            $table->timestamp('read_at')->nullable();

            $table->timestamps();

            $table->index(['read_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
