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
      Schema::create('pricing_rules', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->constrained()->cascadeOnDelete();
    $table->enum('rule_type', ['base', 'per_bedroom', 'per_bathroom', 'modifier']);
    $table->string('rule_key')->nullable(); // e.g. "multi_floor"
    $table->enum('amount_type', ['fixed', 'percent']);
    $table->unsignedBigInteger('amount');   // fixed = kobo; percent = whole number (10 = 10%)
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
