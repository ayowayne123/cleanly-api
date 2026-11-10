<?php

namespace Database\Seeders;

use App\Models\PricingRule;
use App\Models\Service;
use App\Models\ServiceTask;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    // All money is in kobo (₦1 = 100 kobo). Every number here is a placeholder.
    public function run(): void
    {
        $services = [
            ['slug' => 'basic-home', 'name' => 'Basic Home Cleaning', 'description' => 'Regular upkeep cleaning for your home.', 'coming_soon' => false, 'uplift' => 0],
            ['slug' => 'premium-deep', 'name' => 'Premium Deep Cleaning', 'description' => 'A thorough top-to-bottom clean, including the hard-to-reach places.', 'coming_soon' => false, 'uplift' => 60],
            ['slug' => 'move-in-out', 'name' => 'Move-In / Move-Out Cleaning', 'description' => 'Get an empty home spotless for the next occupant.', 'coming_soon' => false, 'uplift' => 80],
            ['slug' => 'office-commercial', 'name' => 'Office & Commercial Cleaning', 'description' => 'Cleaning for offices and commercial spaces.', 'coming_soon' => true, 'uplift' => 0],
            ['slug' => 'upholstery-fabric', 'name' => 'Upholstery & Fabric Cleaning', 'description' => 'Sofas, rugs, curtains and mattresses.', 'coming_soon' => true, 'uplift' => 0],
            ['slug' => 'post-construction', 'name' => 'Post Construction Cleaning', 'description' => 'Dust and debris removal after building or renovation.', 'coming_soon' => true, 'uplift' => 0],
        ];

        foreach ($services as $i => $data) {
            $service = Service::updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'description' => $data['description'],
                'is_active' => true,
                'is_coming_soon' => $data['coming_soon'],
                'sort_order' => $i + 1,
            ]);

            if ($data['coming_soon']) {
                continue;   // no pricing or checklist until the client defines them
            }

            $this->seedPricing($service, $data['uplift']);
            $this->seedTasks($service, $data['slug']);
        }
    }

    private function seedPricing(Service $service, int $uplift): void
    {
        $rules = [
            // [rule_type, rule_key, amount_type, amount]
            ['base', 'base', 'fixed', 0],
            ['per_bedroom', 'per_bedroom', 'fixed', 500000],      // ₦5,000
            ['per_bathroom', 'per_bathroom', 'fixed', 100000],    // ₦1,000
            ['modifier', 'multi_floor', 'percent', 10],           // +10%
            ['modifier', 'has_bq', 'fixed', 500000],              // +₦5,000
        ];

        if ($uplift > 0) {
            $rules[] = ['modifier', 'service_uplift', 'percent', $uplift];
        }

        foreach ($rules as [$type, $key, $amountType, $amount]) {
            PricingRule::updateOrCreate(
                ['service_id' => $service->id, 'rule_key' => $key],
                ['rule_type' => $type, 'amount_type' => $amountType, 'amount' => $amount, 'is_active' => true],
            );
        }
    }

    private function seedTasks(Service $service, string $slug): void
    {
        $tasks = $this->basicTasks();

        if (in_array($slug, ['premium-deep', 'move-in-out'])) {
            $tasks = array_merge($tasks, $this->deepTasks());
        }

        if ($slug === 'move-in-out') {
            $tasks = array_merge($tasks, $this->moveTasks());
        }

        ServiceTask::where('service_id', $service->id)->delete();

        foreach ($tasks as $i => [$area, $description]) {
            $service->tasks()->create([
                'area' => $area,
                'description' => $description,
                'sort_order' => $i + 1,
            ]);
        }
    }

    private function basicTasks(): array
    {
        return [
            ['Kitchen', 'Wipe counters, hob and sink'],
            ['Kitchen', 'Clean the outside of cabinets and appliances'],
            ['Kitchen', 'Sweep and mop the floor'],
            ['Bathrooms', 'Scrub and disinfect toilets, sinks and showers'],
            ['Bathrooms', 'Clean mirrors and wipe surfaces'],
            ['Bathrooms', 'Mop the floor'],
            ['Bedrooms', 'Dust surfaces and furniture'],
            ['Bedrooms', 'Make beds and tidy'],
            ['Bedrooms', 'Sweep or vacuum and mop floors'],
            ['Living areas', 'Dust surfaces and furniture'],
            ['Living areas', 'Sweep or vacuum and mop floors'],
            ['General', 'Empty bins and take out the trash'],
        ];
    }

    private function deepTasks(): array
    {
        return [
            ['Kitchen', 'Degrease hob, extractor and wall tiles'],
            ['Bathrooms', 'Descale taps, showerheads and tiles'],
            ['Bedrooms', 'Wipe skirting boards, doors and light switches'],
            ['General', 'Clean window frames and tracks'],
            ['General', 'Dust ceiling corners, fans and vents'],
        ];
    }

    private function moveTasks(): array
    {
        return [
            ['Kitchen', 'Clean inside cabinets and drawers'],
            ['Bedrooms', 'Clean inside wardrobes'],
            ['General', 'Remove marks from walls and doors'],
        ];
    }
}