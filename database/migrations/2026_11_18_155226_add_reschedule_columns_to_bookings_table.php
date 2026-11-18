<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // the day a booking was first on, if it has been moved
            $table->foreignId('rescheduled_from_work_day_id')->nullable()->after('work_day_id')
                ->constrained('work_days')->nullOnDelete();
            $table->timestamp('rescheduled_at')->nullable()->after('rescheduled_from_work_day_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rescheduled_from_work_day_id');
            $table->dropColumn('rescheduled_at');
        });
    }
};