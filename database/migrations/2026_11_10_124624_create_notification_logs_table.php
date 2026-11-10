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
       Schema::create('notification_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('channel', ['email', 'whatsapp', 'sms']);
    $table->string('template');                              // e.g. booking_confirmation
    $table->string('recipient');                             // email address or phone number
    $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
    $table->string('provider_message_id')->nullable();
    $table->timestamp('sent_at')->nullable();
    $table->text('error')->nullable();
    $table->timestamps();

    $table->index(['user_id', 'created_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_logs');
    }
};
