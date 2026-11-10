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
       Schema::create('cleaner_time_off', function (Blueprint $table) {
    $table->id();
    $table->foreignId('cleaner_id')->constrained()->cascadeOnDelete();
    $table->date('start_date');
    $table->date('end_date');                     // inclusive; whole days only
    $table->enum('type', ['leave', 'sick', 'other'])->default('leave');
    $table->text('reason')->nullable();
    $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
    $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();

    $table->index(['cleaner_id', 'start_date', 'end_date']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaner_time_offs');
    }
};
