<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('replacement_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_cleaner_id')->constrained('booking_cleaners')->cascadeOnDelete(); // the affected assignment
    $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
    $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('reason', ['leave', 'sick', 'declined', 'quit', 'admin_change', 'off_day_change']);
    $table->enum('status', [
        'open', 'replaced', 'moved', 'skipped_credited', 'unresolved', 'cancelled',
    ])->default('open');
    $table->foreignId('replacement_booking_cleaner_id')->nullable()
        ->constrained('booking_cleaners')->nullOnDelete();
    $table->timestamp('client_notified_at')->nullable();
    $table->timestamp('replacement_notified_at')->nullable();
    $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();

    $table->index('status');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replacement_requests');
    }
};
