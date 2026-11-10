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
       Schema::create('cleaner_zones', function (Blueprint $table) {
    $table->foreignId('cleaner_id')->constrained()->cascadeOnDelete();
    $table->foreignId('zone_id')->constrained()->cascadeOnDelete();

    $table->primary(['cleaner_id', 'zone_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cleaner_zones');
    }
};
