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
       Schema::create('bookings', function (Blueprint $table) {
    $table->id();
    $table->string('reference')->unique();               // CLN-208471
    $table->foreignId('client_id')->constrained();
    $table->foreignId('service_id')->constrained();
    $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('address_id')->nullable()->constrained()->nullOnDelete();
    $table->json('address_snapshot');
    $table->foreignId('zone_id')->constrained();         // snapshot of the zone at booking time
    $table->foreignId('work_day_id')->constrained('work_days');
    $table->dateTime('start_at');
    $table->dateTime('end_at');
    $table->unsignedSmallInteger('est_duration_minutes');
    $table->unsignedTinyInteger('bedrooms')->default(0);
    $table->unsignedTinyInteger('bathrooms')->default(0);
    $table->boolean('is_flat')->default(false);
    $table->boolean('multi_floor')->default(false);
    $table->boolean('has_bq')->default(false);
    $table->foreignId('home_size_tier_id')->constrained();
    $table->unsignedTinyInteger('cleaners_recommended');
    $table->unsignedTinyInteger('cleaners_extra')->default(0);
    $table->unsignedTinyInteger('cleaners_required');
    $table->text('off_limit_areas')->nullable();
    $table->text('special_instructions')->nullable();
    $table->enum('assignment_mode', ['admin', 'roster'])->default('admin');
    $table->enum('status', [
        'pending_payment', 'awaiting_assignment', 'assigned', 'confirmed',
        'in_progress', 'completed', 'cancelled', 'expired', 'no_show',
    ])->default('pending_payment');
    $table->enum('payment_status', [
        'unpaid', 'paid', 'covered_by_subscription',
        'partially_refunded', 'refunded', 'failed',
    ])->default('unpaid');
    $table->unsignedBigInteger('subtotal_k')->default(0);
    $table->unsignedBigInteger('addons_total_k')->default(0);
    $table->unsignedBigInteger('extra_cleaners_total_k')->default(0);
    $table->unsignedBigInteger('transport_fee_k')->default(0);
    $table->unsignedBigInteger('service_fee_k')->default(0);
    $table->unsignedBigInteger('discount_k')->default(0);
    $table->unsignedBigInteger('total_k')->default(0);
    $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
    $table->enum('source', ['web', 'whatsapp', 'admin', 'subscription'])->default('web');
    $table->timestamp('cancelled_at')->nullable();
    $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('cancel_reason')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    $table->softDeletes();

    $table->index(['status', 'start_at']);
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
