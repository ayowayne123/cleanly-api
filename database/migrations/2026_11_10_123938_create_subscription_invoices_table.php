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
       Schema::create('subscription_invoices', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->date('period_start');
    $table->date('period_end');
    $table->unsignedSmallInteger('visits_count');
    $table->unsignedBigInteger('subtotal_k');
    $table->unsignedBigInteger('discount_k')->default(0);
    $table->unsignedBigInteger('total_k');
    $table->enum('status', ['pending', 'paid', 'failed', 'void'])->default('pending');
    $table->unsignedTinyInteger('attempts')->default(0);
    $table->timestamp('last_attempt_at')->nullable();
    $table->timestamp('next_retry_at')->nullable();
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'next_retry_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
