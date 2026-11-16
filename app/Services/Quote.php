<?php

namespace App\Services;

final readonly class Quote
{
    /**
     * Money is always whole kobo (N1 = 100 kobo).
     *
     * @param array<int, array{room_type_id:int, name:string, quantity:int, unit_price_k:int, extra_minutes:int}> $rooms
     * @param array<int, array{addon_id:int, name:string, quantity:int, unit_price_k:int, extra_minutes:int}> $addons
     * @param array<int, array{label:string, amount_k:int}> $lines  price breakdown for display
     */
    public function __construct(
        public int $serviceId,
        public int $zoneId,
        public int $homeSizeTierId,
        public string $tierLabel,
        public int $sizeScore,
        public int $cleanersRecommended,
        public int $cleanersExtra,
        public int $cleanersRequired,
        public int $estDurationMinutes,
        public int $subtotalK,
        public int $addonsTotalK,
        public int $extraCleanersTotalK,
        public int $transportFeeK,
        public int $serviceFeeK,
        public int $discountK,
        public int $totalK,
        public ?int $couponId,
        public ?string $couponError,
        public array $rooms,
        public array $addons,
        public array $lines,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    // The columns of a booking that come straight from the quote.
    // Rooms and add-ons are saved separately (booking_rooms, booking_addons).
    public function toBookingAttributes(): array
    {
        return [
            'service_id' => $this->serviceId,
            'zone_id' => $this->zoneId,
            'home_size_tier_id' => $this->homeSizeTierId,
            'cleaners_recommended' => $this->cleanersRecommended,
            'cleaners_extra' => $this->cleanersExtra,
            'cleaners_required' => $this->cleanersRequired,
            'est_duration_minutes' => $this->estDurationMinutes,
            'subtotal_k' => $this->subtotalK,
            'addons_total_k' => $this->addonsTotalK,
            'extra_cleaners_total_k' => $this->extraCleanersTotalK,
            'transport_fee_k' => $this->transportFeeK,
            'service_fee_k' => $this->serviceFeeK,
            'discount_k' => $this->discountK,
            'total_k' => $this->totalK,
            'coupon_id' => $this->couponId,
        ];
    }
}