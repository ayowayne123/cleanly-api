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
       Schema::create('reclean_requests', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
    $table->foreignId('client_id')->constrained();
    $table->timestamp('reported_at');
    $table->text('areas_missed');
    $table->json('photo_paths')->nullable();
    $table->enum('status', ['open', 'approved', 'rejected', 'completed'])->default('open');
    $table->foreignId('resolution_booking_id')->nullable()->constrained('bookings')->nullOnDelete();
    $table->foreignId('handled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reclean_requests');
    }
};
