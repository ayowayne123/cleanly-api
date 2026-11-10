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
       Schema::create('reviews', function (Blueprint $table) {
    $table->id();
    $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
    $table->foreignId('client_id')->constrained();
    $table->unsignedTinyInteger('overall_rating');       // 1-5, validated in code
    $table->text('comment')->nullable();
    $table->enum('status', ['visible', 'hidden'])->default('visible');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
