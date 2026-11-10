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
       Schema::create('clients', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('referral_code')->unique();
    $table->foreignId('referred_by_client_id')->nullable()->constrained('clients')->nullOnDelete();
    $table->enum('preferred_contact', ['whatsapp', 'call', 'email'])->default('whatsapp');
    $table->string('paystack_customer_code')->nullable();
    $table->text('admin_notes')->nullable();
    $table->boolean('marketing_opt_in')->default(false);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
