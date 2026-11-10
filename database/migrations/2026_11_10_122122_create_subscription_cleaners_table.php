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
       Schema::create('subscription_cleaners', function (Blueprint $table) {
    $table->id();
    $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
    $table->foreignId('cleaner_id')->constrained();
    $table->enum('role', ['lead', 'member'])->default('member');
    $table->enum('chosen_by', ['client', 'admin']);   // at most one 'client' per subscription, enforced in code
    $table->enum('status', ['active', 'removed'])->default('active');
    $table->timestamp('added_at')->useCurrent();
    $table->timestamp('removed_at')->nullable();
    $table->timestamps();

    $table->unique(['subscription_id', 'cleaner_id']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_cleaners');
    }
};
