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
       Schema::create('expenses', function (Blueprint $table) {
    $table->id();
    $table->foreignId('category_id')->constrained('expense_categories');
    $table->unsignedBigInteger('amount_k');
    $table->date('expense_date');
    $table->foreignId('paid_to_user_id')->nullable()->constrained('users')->nullOnDelete(); // who was paid
    $table->string('payee_name')->nullable();                // suppliers, etc.
    $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('payment_method', ['cash', 'bank_transfer', 'other'])->default('bank_transfer');
    $table->string('reference')->nullable();
    $table->date('period_start')->nullable();                // e.g. salary for 1-30 Nov
    $table->date('period_end')->nullable();
    $table->text('notes')->nullable();
    $table->string('receipt_path')->nullable();
    $table->enum('status', ['recorded', 'void'])->default('recorded');
    $table->foreignId('recorded_by_user_id')->constrained('users');
    $table->timestamps();
    $table->softDeletes();

    $table->index('expense_date');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
