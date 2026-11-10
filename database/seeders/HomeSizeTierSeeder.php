<?php

namespace Database\Seeders;

use App\Models\HomeSizeTier;
use App\Models\Service;
use Illuminate\Database\Seeder;

class HomeSizeTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            // [label, min_score, max_score, cleaners, minutes]
            ['Small', 0, 4, 1, 150],
            ['Standard', 5, 8, 2, 180],
            ['Large', 9, 12, 3, 240],
            ['Estate', 13, 99, 4, 300],
        ];

        // service_id = null means the tier applies to every service
        $this->seed(null, $tiers, 1.0);

        // Deep and move-in jobs take longer, so they get their own tiers (specific tiers win)
        foreach (['premium-deep' => 1.5, 'move-in-out' => 1.5] as $slug => $multiplier) {
            $service = Service::where('slug', $slug)->first();

            if ($service) {
                $this->seed($service->id, $tiers, $multiplier);
            }
        }
    }

    private function seed(?int $serviceId, array $tiers, float $multiplier): void
    {
        foreach ($tiers as [$label, $min, $max, $cleaners, $minutes]) {
            HomeSizeTier::updateOrCreate(
                ['service_id' => $serviceId, 'label' => $label],
                [
                    'min_score' => $min,
                    'max_score' => $max,
                    'recommended_cleaners' => $cleaners,
                    'est_duration_minutes' => (int) (round($minutes * $multiplier / 15) * 15),
                ],
            );
        }
    }
}