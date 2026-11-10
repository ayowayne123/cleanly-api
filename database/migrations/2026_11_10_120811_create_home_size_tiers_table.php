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
       Schema::create('home_size_tiers', function (Blueprint $table) {
    $table->id();
    $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete(); // null = all services
    $table->string('label');                // "Studio", "Standard Home"...
    $table->unsignedSmallInteger('min_score');
    $table->unsignedSmallInteger('max_score');
    $table->unsignedTinyInteger('recommended_cleaners');
    $table->unsignedSmallInteger('est_duration_minutes');
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('home_size_tiers');
    }
};
