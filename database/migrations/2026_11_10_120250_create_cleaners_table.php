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
       Schema::create('cleaners', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('photo_path')->nullable();
    $table->string('whatsapp_number')->nullable();
    $table->date('date_of_birth')->nullable();
    $table->text('home_address')->nullable();
    $table->string('emergency_contact_name')->nullable();
    $table->string('emergency_contact_phone')->nullable();
    $table->string('emergency_contact_relationship')->nullable();
    $table->enum('status', ['onboarding', 'active', 'inactive', 'suspended'])->default('onboarding');
    $table->unsignedTinyInteger('weekly_off_day')->nullable();   // 0 = Sunday ... 6 = Saturday
    $table->string('bank_name')->nullable();
    $table->text('account_number')->nullable();   // encrypted later via model cast
    $table->string('account_name')->nullable();
    $table->decimal('avg_rating', 3, 2)->default(0);
    $table->unsignedInteger('rating_count')->default(0);
    $table->unsignedInteger('jobs_completed')->default(0);
    $table->date('hired_at')->nullable();
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaners');
    }
};
