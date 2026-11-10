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
       Schema::create('work_days', function (Blueprint $table) {
    $table->id();
    $table->date('work_date')->unique();
    $table->boolean('is_open')->default(true);
    $table->time('opens_at');               // 10:00 normally, 14:00 on Sundays
    $table->string('note')->nullable();     // e.g. "Public holiday"
    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_days');
    }
};
