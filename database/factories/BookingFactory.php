<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Cleaner;
use App\Models\Client;
use App\Models\HomeSizeTier;
use App\Models\Service;
use App\Models\WorkDay;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    public function definition(): array
    {
        $workDay = WorkDay::open()->whereDate('work_date', '>', today())->inRandomOrder()->first();
        $service = Service::bookable()->inRandomOrder()->first();
        $tier = $service ? HomeSizeTier::forService($service)->inRandomOrder()->first() : null;
        $zoneId = Zone::query()->inRandomOrder()->value('id');

        if (! $workDay || ! $service || ! $tier || ! $zoneId) {
            throw new RuntimeException(
                'The booking factory needs seeded data. Run: php artisan migrate:fresh --seed'
            );
        }

        // start time comes from the work day (10:00, or 14:00 on Sunday)
        $start = $workDay->work_date->copy()->setTimeFromTimeString($workDay->opens_at);

        $subtotal = fake()->numberBetween(15, 45) * 100000;   // ₦15,000 - ₦45,000 in kobo
        $transport = Zone::find($zoneId)->transport_fee_k;

        return [
            'client_id' => Client::factory(),
            'service_id' => $service->id,
            'address_snapshot' => ['street' => fake()->streetAddress(), 'landmark' => null],
            'zone_id' => $zoneId,
            'work_day_id' => $workDay->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($tier->est_duration_minutes),
            'est_duration_minutes' => $tier->est_duration_minutes,
            'bedrooms' => fake()->numberBetween(1, 5),
            'bathrooms' => fake()->numberBetween(1, 4),
            'home_size_tier_id' => $tier->id,
            'cleaners_recommended' => $tier->recommended_cleaners,
            'cleaners_required' => $tier->recommended_cleaners,
            'subtotal_k' => $subtotal,
            'transport_fee_k' => $transport,
            'total_k' => $subtotal + $transport,
        ];
    }

    // Paid, and waiting in the admin queue
    public function paid(): static
    {
        return $this->state([
            'status' => 'awaiting_assignment',
            'payment_status' => 'paid',
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'payment_status' => 'paid',
            'completed_at' => now(),
        ]);
    }

    // Put one specific cleaner on the job
    public function withCleaner(Cleaner $cleaner): static
    {
        return $this->afterCreating(function (Booking $booking) use ($cleaner) {
            $booking->assignments()->create(['cleaner_id' => $cleaner->id, 'status' => 'assigned']);
        });
    }
}