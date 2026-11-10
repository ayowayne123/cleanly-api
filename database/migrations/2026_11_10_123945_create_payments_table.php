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
       Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('subscription_invoice_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('client_id')->constrained();
    $table->enum('purpose', ['booking', 'subscription']);
    $table->enum('provider', ['paystack', 'manual'])->default('paystack');   // manual = cash/POS on arrival
    $table->enum('method', ['card', 'bank_transfer', 'direct_debit', 'cash_on_arrival']);
    $table->string('reference')->unique();            // ours, sent to Paystack
    $table->string('provider_reference')->nullable()->index();
    $table->foreignId('authorization_id')->nullable()->constrained('payment_authorizations')->nullOnDelete();
    $table->unsignedBigInteger('amount_k');
    $table->unsignedBigInteger('gateway_fee_k')->nullable();   // filled if Paystack returns the fee
    $table->string('currency', 3)->default('NGN');
    $table->enum('status', ['pending', 'success', 'failed', 'abandoned'])->default('pending');
    $table->timestamp('paid_at')->nullable();
    $table->json('gateway_response')->nullable();
    $table->timestamps();

    $table->index(['status', 'created_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
