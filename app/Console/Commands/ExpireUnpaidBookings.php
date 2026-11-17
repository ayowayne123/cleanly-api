<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Setting;
use App\Services\BookingService;
use Illuminate\Console\Command;

class ExpireUnpaidBookings extends Command
{
    protected $signature = 'bookings:expire-unpaid';

    protected $description = 'Expire online bookings that were never paid, freeing their place and coupon';

    public function handle(BookingService $bookings): int
    {
        $minutes = (int) Setting::get('unpaid_expiry_minutes', 30);
        $expired = 0;

        Booking::where('status', 'pending_payment')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->pluck('id')
            ->each(function ($id) use ($bookings, &$expired) {
                if ($bookings->expire((int) $id)) {
                    $expired++;
                }
            });

        $this->info("Expired {$expired} unpaid booking(s).");

        return self::SUCCESS;
    }
}