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
       Schema::create('subscriptions', function (Blueprint $table) {
    $table->id();
    $table->string('reference')->unique();        // SUB-000123
    $table->foreignId('client_id')->constrained();
    $table->foreignId('plan_id')->constrained('subscription_plans');
    $table->foreignId('service_id')->constrained();
    $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
    $table->json('address_snapshot');
    $table->json('visit_snapshot');               // bedrooms, bathrooms, toggles, rooms, add-ons, notes
    $table->foreignId('home_size_tier_id')->constrained();
    $table->unsignedTinyInteger('cleaners_required');
    $table->enum('cleaner_selection', ['we_assign', 'preferred_cleaner'])->default('we_assign');
    $table->unsignedSmallInteger('est_duration_minutes');
    $table->date('start_date');
    $table->date('ends_at')->nullable();
    $table->date('current_period_start')->nullable();
    $table->date('current_period_end')->nullable();
    $table->date('next_billing_date')->nullable()->index();
    // snapshots from the plan, so later price changes don't alter existing contracts
    $table->unsignedTinyInteger('visits_per_week');
    $table->unsignedTinyInteger('weeks_per_period');
    $table->unsignedTinyInteger('discount_percent');
    $table->unsignedBigInteger('price_per_visit_k');
    $table->unsignedBigInteger('period_subtotal_k');
    $table->unsignedBigInteger('period_discount_k');
    $table->unsignedBigInteger('period_total_k');
    $table->foreignId('authorization_id')->nullable()->constrained('payment_authorizations')->nullOnDelete();
    $table->enum('status', [
        'pending_first_payment', 'active', 'past_due', 'paused', 'cancelled', 'expired',
    ])->default('pending_first_payment');
    $table->timestamp('paused_at')->nullable();
    $table->timestamp('cancelled_at')->nullable();
    $table->string('cancel_reason')->nullable();
    $table->timestamps();

    $table->index('status');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
