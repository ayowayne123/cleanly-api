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
       Schema::create('coupons', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->enum('type', ['percent', 'fixed', 'free_service']);
    $table->unsignedBigInteger('value')->default(0);     // percent = whole number; fixed = kobo
    $table->unsignedBigInteger('min_total_k')->default(0);
    $table->unsignedInteger('max_uses')->nullable();
    $table->unsignedInteger('uses_count')->default(0);
    $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // personal coupon
    $table->timestamp('starts_at')->nullable();
    $table->timestamp('expires_at')->nullable();
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
