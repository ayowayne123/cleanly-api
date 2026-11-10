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
       Schema::create('booking_cleaners', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
    $table->foreignId('cleaner_id')->constrained();
    $table->enum('role', ['lead', 'member'])->default('member');
    $table->enum('status', [
        'offered', 'accepted', 'declined', 'assigned',
        'needs_replacement', 'replaced', 'removed', 'completed',
    ])->default('offered');
    $table->date('work_date');
    // = work_date while the assignment is active (offered/accepted/assigned/completed),
    // NULL when declined/needs_replacement/replaced/removed. Kept in sync in code.
    $table->date('active_work_date')->nullable();
    $table->dateTime('start_at');
    $table->dateTime('end_at');
    $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->foreignId('replaces_id')->nullable()->constrained('booking_cleaners')->nullOnDelete();
    $table->enum('replacement_reason', [
        'leave', 'sick', 'declined', 'quit', 'admin_change', 'off_day_change',
    ])->nullable();
    $table->timestamp('responded_at')->nullable();
    $table->timestamps();

    $table->unique(['booking_id', 'cleaner_id']);
    // One active cleaning per cleaner per day. NULLs don't collide in MySQL.
    $table->unique(['cleaner_id', 'active_work_date']);
    $table->index('work_date');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_cleaners');
    }
};
