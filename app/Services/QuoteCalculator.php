<?php

namespace App\Services;

use App\Exceptions\QuoteException;
use App\Models\Addon;
use App\Models\Area;
use App\Models\Coupon;
use App\Models\HomeSizeTier;
use App\Models\RoomType;
use App\Models\Service;
use App\Models\Setting;

class QuoteCalculator
{
    /**
     * Price one visit.
     *
     * @param array{
     *   service_id:int,
     *   area_id:int,
     *   bedrooms?:int,
     *   bathrooms?:int,
     *   rooms?:array<int, array{room_type_id:int, quantity?:int}>,
     *   addons?:array<int, array{addon_id:int, quantity?:int}>,
     *   multi_floor?:bool,
     *   has_bq?:bool,
     *   cleaners_extra?:int,
     *   coupon_code?:?string,
     *   client_id?:?int
     * } $input
     *
     * @throws QuoteException
     */
    public function calculate(array $input): Quote
    {
        $service = Service::find($input['service_id'] ?? null);

        if (! $service || ! $service->is_active || $service->is_coming_soon) {
            throw new QuoteException('This service is not available for booking yet.');
        }

        $area = Area::with('zone')->find($input['area_id'] ?? null);

        if (! $area || ! $area->is_active || ! $area->zone->is_active) {
            throw new QuoteException('We do not serve that area yet.');
        }

        $zone = $area->zone;

        $bedrooms = $this->count($input['bedrooms'] ?? 0, 'bedrooms');
        $bathrooms = $this->count($input['bathrooms'] ?? 0, 'bathrooms');
        $extraCleaners = $this->count($input['cleaners_extra'] ?? 0, 'extra cleaners', 10);
        $multiFloor = (bool) ($input['multi_floor'] ?? false);
        $hasBq = (bool) ($input['has_bq'] ?? false);

        $rooms = $this->resolveRooms($input['rooms'] ?? []);
        $addons = $this->resolveAddons($input['addons'] ?? []);

        // ---- crew size and duration ----
        $weights = Setting::get('size_score_formula', []);
        $extraRoomCount = array_sum(array_column($rooms, 'quantity'));

        $score = $bedrooms * ($weights['bedroom'] ?? 1)
            + $bathrooms * ($weights['bathroom'] ?? 1)
            + $extraRoomCount * ($weights['extra_room'] ?? 1);

        $tier = HomeSizeTier::forService($service)->forScore($score)->first();

        if (! $tier) {
            throw new QuoteException('We could not work out a crew size for that home. Please contact us.');
        }

        $duration = $tier->est_duration_minutes
            + array_sum(array_map(fn ($r) => $r['extra_minutes'] * $r['quantity'], $rooms))
            + array_sum(array_map(fn ($a) => $a['extra_minutes'] * $a['quantity'], $addons));

        // ---- price ----
        $rules = $service->pricingRules()->active()->get()->keyBy('rule_key');
        $rate = fn (string $key): int => (int) ($rules->get($key)?->amount ?? 0);

        $lines = [];

        $baseK = $rate('base');
        if ($baseK > 0) {
            $lines[] = ['label' => 'Base price', 'amount_k' => $baseK];
        }

        $bedroomsK = $bedrooms * $rate('per_bedroom');
        if ($bedrooms > 0) {
            $lines[] = ['label' => $bedrooms.' '.($bedrooms === 1 ? 'bedroom' : 'bedrooms'), 'amount_k' => $bedroomsK];
        }

        $bathroomsK = $bathrooms * $rate('per_bathroom');
        if ($bathrooms > 0) {
            $lines[] = ['label' => $bathrooms.' '.($bathrooms === 1 ? 'bathroom' : 'bathrooms'), 'amount_k' => $bathroomsK];
        }

        $roomsK = 0;
        foreach ($rooms as $room) {
            $amount = $room['unit_price_k'] * $room['quantity'];
            $roomsK += $amount;
            $lines[] = [
                'label' => $room['name'].($room['quantity'] > 1 ? ' x'.$room['quantity'] : ''),
                'amount_k' => $amount,
            ];
        }

        $cleaningK = $baseK + $bedroomsK + $bathroomsK + $roomsK;

        // Percent modifiers are each a % of the cleaning price, and they add together
        // (they do not compound). Fixed modifiers are added after.
        $modifierLabels = [
            'multi_floor' => 'Multi-floor home',
            'has_bq' => "Boys' quarters",
            'service_uplift' => $service->name,
        ];
        $applies = ['multi_floor' => $multiFloor, 'has_bq' => $hasBq, 'service_uplift' => true];

        $modifiersK = 0;
        foreach ($rules->where('rule_type', 'modifier') as $rule) {
            if (! ($applies[$rule->rule_key] ?? false)) {
                continue;
            }

            if ($rule->amount_type === 'percent') {
                $amount = (int) round($cleaningK * $rule->amount / 100);
                $label = ($modifierLabels[$rule->rule_key] ?? $rule->rule_key).' (+'.$rule->amount.'%)';
            } else {
                $amount = (int) $rule->amount;
                $label = $modifierLabels[$rule->rule_key] ?? $rule->rule_key;
            }

            $modifiersK += $amount;
            $lines[] = ['label' => $label, 'amount_k' => $amount];
        }

        $subtotalK = $cleaningK + $modifiersK;

        $addonsTotalK = 0;
        foreach ($addons as $addon) {
            $amount = $addon['unit_price_k'] * $addon['quantity'];
            $addonsTotalK += $amount;
            $lines[] = [
                'label' => $addon['name'].($addon['quantity'] > 1 ? ' x'.$addon['quantity'] : ''),
                'amount_k' => $amount,
            ];
        }

        $extraCleanersTotalK = $extraCleaners * (int) Setting::get('extra_cleaner_fee_k', 0);
        if ($extraCleaners > 0) {
            $lines[] = [
                'label' => $extraCleaners.' extra '.($extraCleaners === 1 ? 'cleaner' : 'cleaners'),
                'amount_k' => $extraCleanersTotalK,
            ];
        }

        $transportK = (int) $zone->transport_fee_k;
        if ($transportK > 0) {
            $lines[] = ['label' => 'Transport ('.$zone->name.')', 'amount_k' => $transportK];
        }

        $serviceFeeK = (int) Setting::get('service_fee_k', 0);
        if ($serviceFeeK > 0) {
            $lines[] = ['label' => 'Service fee', 'amount_k' => $serviceFeeK];
        }

        // ---- coupon (applies to cleaning + add-ons + extra cleaners; not transport or fee) ----
        $eligibleK = $subtotalK + $addonsTotalK + $extraCleanersTotalK;

        [$discountK, $coupon, $couponError] = $this->applyCoupon(
            $input['coupon_code'] ?? null,
            $input['client_id'] ?? null,
            $subtotalK,
            $eligibleK,
        );

        if ($discountK > 0) {
            $lines[] = ['label' => 'Coupon '.$coupon->code, 'amount_k' => -$discountK];
        }

        $totalK = max(0, $subtotalK + $addonsTotalK + $extraCleanersTotalK + $transportK + $serviceFeeK - $discountK);

        return new Quote(
            serviceId: $service->id,
            zoneId: $zone->id,
            homeSizeTierId: $tier->id,
            tierLabel: $tier->label,
            sizeScore: $score,
            cleanersRecommended: $tier->recommended_cleaners,
            cleanersExtra: $extraCleaners,
            cleanersRequired: $tier->recommended_cleaners + $extraCleaners,
            estDurationMinutes: $duration,
            subtotalK: $subtotalK,
            addonsTotalK: $addonsTotalK,
            extraCleanersTotalK: $extraCleanersTotalK,
            transportFeeK: $transportK,
            serviceFeeK: $serviceFeeK,
            discountK: $discountK,
            totalK: $totalK,
            couponId: $coupon?->id,
            couponError: $couponError,
            rooms: $rooms,
            addons: $addons,
            lines: $lines,
        );
    }

    // A bad coupon never fails the quote: it just comes back with no discount and a message.
    // Quoting never uses a coupon up. uses_count goes up when the booking is created.
    private function applyCoupon(?string $code, ?int $clientId, int $subtotalK, int $eligibleK): array
    {
        $code = trim((string) $code);

        if ($code === '') {
            return [0, null, null];
        }

        $invalid = [0, null, 'That coupon code is not valid for this order.'];

        $coupon = Coupon::where('code', $code)->first();

        if (! $coupon || ! $coupon->isRedeemable($eligibleK)) {
            return $invalid;
        }

        // a personal coupon only works for the client it was made for
        if ($coupon->client_id && (int) $coupon->client_id !== (int) $clientId) {
            return $invalid;
        }

        $discount = match ($coupon->type) {
            'percent' => (int) round($eligibleK * $coupon->value / 100),
            'fixed' => (int) $coupon->value,
            'free_service' => $subtotalK,   // the cleaning itself is free
        };

        return [min($discount, $eligibleK), $coupon, null];
    }

    /** @return array<int, array{room_type_id:int, name:string, quantity:int, unit_price_k:int, extra_minutes:int}> */
    private function resolveRooms(array $requested): array
    {
        $quantities = [];

        foreach ($requested as $row) {
            $id = (int) ($row['room_type_id'] ?? 0);
            $quantity = $this->count($row['quantity'] ?? 1, 'rooms');

            if ($quantity > 0) {
                $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;   // same room picked twice = add up
            }
        }

        if (! $quantities) {
            return [];
        }

        $types = RoomType::whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $resolved = [];

        foreach ($quantities as $id => $quantity) {
            $type = $types->get($id);

            if (! $type || ! $type->is_active) {
                throw new QuoteException('One of the selected rooms is not available.');
            }

            $resolved[] = [
                'room_type_id' => $id,
                'name' => $type->name,
                'quantity' => $quantity,
                'unit_price_k' => (int) $type->price_k,
                'extra_minutes' => (int) $type->extra_minutes,
            ];
        }

        return $resolved;
    }

    /** @return array<int, array{addon_id:int, name:string, quantity:int, unit_price_k:int, extra_minutes:int}> */
    private function resolveAddons(array $requested): array
    {
        $quantities = [];

        foreach ($requested as $row) {
            $id = (int) ($row['addon_id'] ?? 0);
            $quantity = $this->count($row['quantity'] ?? 1, 'add-ons');

            if ($quantity > 0) {
                $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
            }
        }

        if (! $quantities) {
            return [];
        }

        $found = Addon::whereIn('id', array_keys($quantities))->get()->keyBy('id');
        $resolved = [];

        foreach ($quantities as $id => $quantity) {
            $addon = $found->get($id);

            if (! $addon || ! $addon->is_active) {
                throw new QuoteException('One of the selected add-ons is not available.');
            }

            $resolved[] = [
                'addon_id' => $id,
                'name' => $addon->name,
                'quantity' => $quantity,
                'unit_price_k' => (int) $addon->price_k,
                'extra_minutes' => (int) $addon->extra_minutes,
            ];
        }

        return $resolved;
    }

    // a whole number from 0 to $max, or a friendly error
    private function count(mixed $value, string $label, int $max = 50): int
    {
        if (! is_numeric($value) || (int) $value != $value || $value < 0 || $value > $max) {
            throw new QuoteException("Please check the number of {$label}.");
        }

        return (int) $value;
    }
}