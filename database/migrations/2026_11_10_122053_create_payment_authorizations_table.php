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
        Schema::create('payment_authorizations', function (Blueprint $table) {
    $table->id();
    $table->foreignId('client_id')->constrained()->cascadeOnDelete();
    $table->string('provider')->default('paystack');
    $table->enum('channel', ['card', 'direct_debit']);
    $table->text('authorization_code');          // encrypted via model cast later
    $table->string('signature')->nullable()->index();   // Paystack's card fingerprint
    $table->string('last4', 4)->nullable();
    $table->string('brand')->nullable();
    $table->string('exp_month', 2)->nullable();
    $table->string('exp_year', 4)->nullable();
    $table->string('bank')->nullable();
    $table->boolean('reusable')->default(true);
    $table->boolean('is_default')->default(false);
    $table->enum('status', ['active', 'expired', 'revoked'])->default('active');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_authorizations');
    }
};
