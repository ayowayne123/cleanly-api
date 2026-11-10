<?php

namespace Database\Seeders;

use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingCleaner;
use App\Models\Cleaner;
use App\Models\Client;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command->error('Never seed demo data in production.');

            return;
        }

        $zones = Zone::all();

        // 10 active cleaners, each covering 2 zones, each with a random weekly off day
        $cleaners = Cleaner::factory()->count(10)->create();
        foreach ($cleaners as $cleaner) {
            $cleaner->zones()->attach($zones->random(2)->pluck('id'));
        }

        // a few cleaners still being onboarded
        Cleaner::factory()->count(2)->onboarding()->create();

        // 15 clients, each with a default address
        $clients = Client::factory()->count(15)->create();
        foreach ($clients as $client) {
            Address::factory()->for($client)->create(['is_default' => true]);
        }

        // 20 paid bookings; assign a crew only from cleaners who are actually free that day
        for ($i = 0; $i < 20; $i++) {
            $client = $clients->random();
            $address = $client->addresses()->with('area')->first();

            $booking = Booking::factory()->paid()->for($client)->create([
                'address_id' => $address->id,
                'zone_id' => $address->area->zone_id,
                'address_snapshot' => ['street' => $address->street, 'area' => $address->area->name],
            ]);

            $date = $booking->workDay->work_date;
            $busy = BookingCleaner::active()->whereDate('work_date', $date)->pluck('cleaner_id');

            $free = $cleaners->reject(fn (Cleaner $c) => $busy->contains($c->id) || $c->isOffOn($date));

            foreach ($free->shuffle()->take($booking->cleaners_required) as $cleaner) {
                $booking->assignments()->create(['cleaner_id' => $cleaner->id, 'status' => 'assigned']);
            }

            // fully staffed jobs move on; short-staffed ones stay in the admin queue
            if ($booking->isFullyStaffed()) {
                $booking->changeStatus('assigned');
            }
        }

        $this->command->info('Demo data created.');
    }
}