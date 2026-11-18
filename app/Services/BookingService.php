<?php

namespace App\Services;

use App\Exceptions\BookingException;
use App\Exceptions\QuoteException;
use App\Models\Address;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Setting;
use App\Models\User;
use App\Models\WorkDay;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class BookingService
{
    private const CANCELLABLE = ['pending_payment', 'awaiting_assignment', 'assigned', 'confirmed'];

    public function __construct(
        private QuoteCalculator $quotes,
        private AvailabilityService $availability,
    ) {
    }

    /**
     * Create a one-off booking.
     *
     * @param array{
     *   client_id:int,
     *   service_id:int,
     *   address_id:int,
     *   work_date:string,
     *   pay?:string,
     *   bedrooms?:int,
     *   bathrooms?:int,
     *   rooms?:array<int, array{room_type_id:int, quantity?:int}>,
     *   addons?:array<int, array{addon_id:int, quantity?:int}>,
     *   is_flat?:bool,
     *   multi_floor?:bool,
     *   has_bq?:bool,
     *   cleaners_extra?:int,
     *   off_limit_areas?:?string,
     *   special_instructions?:?string,
     *   coupon_code?:?string,
     *   source?:string
     * } $input
     *
     * pay = 'online' (default): starts as pending_payment.
     * pay = 'on_arrival' (also how admin creates one): starts as awaiting_assignment.
     *
     * @throws BookingException
     */
    public function create(array $input, ?User $by = null): Booking
    {
        $client = Client::find($input['client_id'] ?? null);

        if (! $client) {
            throw new BookingException('We could not find that client.');
        }

        $address = Address::with('area.zone')
            ->where('client_id', $client->id)
            ->find($input['address_id'] ?? null);

        if (! $address) {
            throw new BookingException('Please choose one of your saved addresses.');
        }

        $pay = $input['pay'] ?? 'online';

        if (! in_array($pay, ['online', 'on_arrival'], true)) {
            throw new BookingException('Please choose how you will pay.');
        }

        $date = $this->parseDate($input['work_date'] ?? null);
        $earliest = today()->addDays(max(0, (int) Setting::get('min_lead_days', 1)));

        if ($date->lt($earliest)) {
            throw new BookingException('That date is too soon. Please choose a later date.');
        }

        return DB::transaction(function () use ($input, $client, $address, $pay, $date) {
            // Lock this day, so two clients can never take the last place at the same moment
            $workDay = WorkDay::whereDate('work_date', $date->toDateString())->lockForUpdate()->first();

            if (! $workDay || ! $workDay->is_open) {
                throw new BookingException('We are not taking bookings on that date.');
            }

            // Lock the coupon too, so its last use can't be taken twice
            $code = trim((string) ($input['coupon_code'] ?? ''));

            if ($code !== '') {
                Coupon::where('code', $code)->lockForUpdate()->first();
            }

            try {
                $quote = $this->quotes->calculate([
                    'service_id' => $input['service_id'] ?? null,
                    'area_id' => $address->area_id,
                    'bedrooms' => $input['bedrooms'] ?? 0,
                    'bathrooms' => $input['bathrooms'] ?? 0,
                    'rooms' => $input['rooms'] ?? [],
                    'addons' => $input['addons'] ?? [],
                    'multi_floor' => $input['multi_floor'] ?? false,
                    'has_bq' => $input['has_bq'] ?? false,
                    'cleaners_extra' => $input['cleaners_extra'] ?? 0,
                    'coupon_code' => $code,
                    'client_id' => $client->id,
                ]);
            } catch (QuoteException $e) {
                throw new BookingException($e->getMessage(), 0, $e);
            }

            // a bad coupon stops the booking: the client expects the discount they typed in
            if ($quote->couponError) {
                throw new BookingException($quote->couponError);
            }

            $slot = $this->availability->bookableDates($date, $date, $quote->cleanersRequired)->first();

            if (! $slot || ! $slot['bookable']) {
                throw new BookingException('Sorry, that date is fully booked. Please choose another.');
            }

            $start = $workDay->work_date->copy()->setTimeFromTimeString($workDay->opens_at);

            $booking = Booking::create($quote->toBookingAttributes() + [
                'client_id' => $client->id,
                'address_id' => $address->id,
                'address_snapshot' => [
                    'label' => $address->label,
                    'street' => $address->street,
                    'landmark' => $address->landmark,
                    'access_notes' => $address->access_notes,
                    'area' => $address->area->name,
                    'zone' => $address->area->zone->name,
                    'lat' => $address->lat,
                    'lng' => $address->lng,
                ],
                'work_day_id' => $workDay->id,
                'start_at' => $start,
                'end_at' => $start->copy()->addMinutes($quote->estDurationMinutes),
                'bedrooms' => (int) ($input['bedrooms'] ?? 0),
                'bathrooms' => (int) ($input['bathrooms'] ?? 0),
                'is_flat' => (bool) ($input['is_flat'] ?? false),
                'multi_floor' => (bool) ($input['multi_floor'] ?? false),
                'has_bq' => (bool) ($input['has_bq'] ?? false),
                'off_limit_areas' => $input['off_limit_areas'] ?? null,
                'special_instructions' => $input['special_instructions'] ?? null,
                'assignment_mode' => 'admin',
                'status' => $pay === 'online' ? 'pending_payment' : 'awaiting_assignment',
                'payment_status' => 'unpaid',
                'source' => $input['source'] ?? 'web',
            ]);

            // snapshot each room and add-on with the price it had today
            foreach ($quote->rooms as $room) {
                $booking->rooms()->create(Arr::only($room, ['room_type_id', 'quantity', 'unit_price_k']));
            }

            foreach ($quote->addons as $addon) {
                $booking->addons()->create(Arr::only($addon, ['addon_id', 'quantity', 'unit_price_k']));
            }

            if ($quote->couponId) {
                Coupon::whereKey($quote->couponId)->increment('uses_count');
            }

            return $booking->load(['rooms', 'addons', 'service', 'zone', 'workDay']);
        });
    }

    /**
     * Cancel a booking: its cleaners are taken off (their days are freed) and an unused coupon is given back.
     * Cancelling a PAID booking does not move any money: refunds come with the Paystack step.
     *
     * @throws BookingException
     */
    public function cancel(Booking|int $booking, ?User $by = null, ?string $reason = null): Booking
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;

        return DB::transaction(function () use ($id, $by, $reason) {
            $booking = Booking::whereKey($id)->lockForUpdate()->firstOrFail();

            if (! in_array($booking->status, self::CANCELLABLE, true)) {
                throw new BookingException('This booking can no longer be cancelled.');
            }

            // update() on the model (not a bulk query), so active_work_date is cleared and the day is freed
            foreach ($booking->assignments()->whereIn('status', ['offered', 'accepted', 'assigned'])->get() as $assignment) {
                $assignment->update(['status' => 'removed']);
            }

            $booking->update(['cancel_reason' => $reason]);
            $booking->changeStatus('cancelled', $by, $reason);

            $this->releaseCoupon($booking);

            return $booking->fresh();
        });
    }

    /**
     * Expire an online booking that was never paid. Returns false if it is no longer waiting for payment
     * (for example, the payment arrived a moment ago).
     */
    public function expire(Booking|int $booking): bool
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;

        return DB::transaction(function () use ($id) {
            $booking = Booking::whereKey($id)->lockForUpdate()->first();

            if (! $booking || $booking->status !== 'pending_payment') {
                return false;
            }

            $booking->changeStatus('expired', null, 'Payment was not completed in time');

            $this->releaseCoupon($booking);

            return true;
        });
    }

    // A coupon use is only given back if the client never paid
    private function releaseCoupon(Booking $booking): void
    {
        if ($booking->coupon_id && $booking->payment_status === 'unpaid') {
            Coupon::whereKey($booking->coupon_id)->where('uses_count', '>', 0)->decrement('uses_count');
        }
    }

    private function parseDate(mixed $value): Carbon
    {
        if (blank($value)) {
            throw new BookingException('Please choose a date for the cleaning.');
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw new BookingException('Please choose a date for the cleaning.');
        }
    }

        // =====================================================================
    // Rescheduling
    // =====================================================================

    private const RESCHEDULABLE = ['pending_payment', 'awaiting_assignment', 'assigned', 'confirmed'];

    /**
     * Move a booking to another open work day (the same week or another week).
     * Cleaners who are free on the new day stay on the job (an accepted job must be accepted again);
     * the others are taken off it. Nothing is charged or refunded: the payment status is unchanged.
     *
     * @throws BookingException
     */
    public function reschedule(Booking|int $booking, string $newDate, ?User $by = null, ?string $reason = null): Booking
    {
        $id = $booking instanceof Booking ? $booking->id : $booking;
        $date = $this->parseDate($newDate);

        try {
            return DB::transaction(function () use ($id, $date, $by, $reason) {
                $booking = Booking::with('workDay')->whereKey($id)->lockForUpdate()->firstOrFail();

                if (! in_array($booking->status, self::RESCHEDULABLE, true) || $booking->start_at->isPast()) {
                    throw new BookingException('This booking can no longer be moved.');
                }

                $this->assertNotice($booking, $by);

                if ($booking->workDay->work_date->isSameDay($date)) {
                    throw new BookingException('The booking is already on that date.');
                }

                $newDay = $this->lockBookableDay($date, (int) $booking->cleaners_required, $booking->subscription_id);

                $oldDayId = $booking->work_day_id;
                $oldDate = $booking->workDay->work_date->toDateString();
                $start = $newDay->work_date->copy()->setTimeFromTimeString($newDay->opens_at);
                $end = $start->copy()->addMinutes($booking->est_duration_minutes);

                // keep the cleaners who are free on the new day, take the others off
                $assignments = $booking->assignments()
                    ->whereIn('status', ['offered', 'accepted', 'assigned'])
                    ->with('cleaner')
                    ->get();

                foreach ($assignments as $assignment) {
                    if ($this->availability->unavailabilityReason($assignment->cleaner, $newDay->work_date) === null) {
                        $reaccept = $assignment->status === 'accepted';

                        // the model keeps active_work_date in step, so the cleaner's old day is freed
                        $assignment->update([
                            'work_date' => $newDay->work_date->toDateString(),
                            'start_at' => $start,
                            'end_at' => $end,
                            'status' => $reaccept ? 'offered' : $assignment->status,
                            'responded_at' => $reaccept ? null : $assignment->responded_at,
                        ]);
                    } else {
                        $assignment->update(['status' => 'removed', 'replacement_reason' => 'admin_change']);
                    }
                }

                $booking->update([
                    'work_day_id' => $newDay->id,
                    // remember the FIRST original day, so a subscription visit is never recreated there
                    'rescheduled_from_work_day_id' => $booking->rescheduled_from_work_day_id ?? $oldDayId,
                    'rescheduled_at' => now(),
                    'start_at' => $start,
                    'end_at' => $end,
                ]);

                $note = 'Moved from '.$oldDate.' to '.$date->toDateString().($reason ? ': '.$reason : '');
                $booking->refresh();
                $target = $booking->status;

                if (in_array($booking->status, ['awaiting_assignment', 'assigned', 'confirmed'], true)) {
                    $target = $booking->isFullyStaffed() ? 'assigned' : 'awaiting_assignment';
                }

                if ($target !== $booking->status) {
                    $booking->changeStatus($target, $by, $note);
                } else {
                    $booking->statusHistory()->create([
                        'from_status' => $booking->status,
                        'to_status' => $booking->status,
                        'changed_by_user_id' => $by?->id,
                        'note' => $note,
                    ]);
                }

                return $booking->fresh(['workDay']);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // another admin put a cleaner on that day at the same moment: the database refused the double booking
            throw new BookingException('Someone was assigned to that day at the same moment. Please try again.');
        }
    }

    /**
     * Lock a work day and make sure a booking with this crew size can go on it.
     * Call it inside a database transaction. Used when moving a booking and when scheduling a make-up visit.
     *
     * @throws BookingException
     */
    public function lockBookableDay(Carbon $date, int $cleanersRequired, ?int $subscriptionId = null): WorkDay
    {
        $earliest = today()->addDays(max(0, (int) Setting::get('min_lead_days', 1)));

        if ($date->lt($earliest)) {
            throw new BookingException('That date is too soon. Please choose a later date.');
        }

        $day = WorkDay::whereDate('work_date', $date->toDateString())->lockForUpdate()->first();

        if (! $day || ! $day->is_open) {
            throw new BookingException('We are not taking bookings on that date.');
        }

        // a subscriber cannot have two visits on one day
        if ($subscriptionId && Booking::where('subscription_id', $subscriptionId)
            ->where('work_day_id', $day->id)
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->exists()) {
            throw new BookingException('This subscription already has a visit on that date.');
        }

        $slot = $this->availability->bookableDates($date, $date, $cleanersRequired)->first();

        if (! $slot || ! $slot['bookable']) {
            throw new BookingException('Sorry, that date is fully booked. Please choose another.');
        }

        return $day;
    }

    // clients must give `reschedule_notice_hours` of notice (default 0 = none); admin can always move a booking
    private function assertNotice(Booking $booking, ?User $by): void
    {
        $hours = (int) Setting::get('reschedule_notice_hours', 0);

        if ($hours > 0 && ! ($by && $by->isAdmin()) && now()->diffInHours($booking->start_at, false) < $hours) {
            throw new BookingException("Changes need at least {$hours} hours' notice. Please contact us.");
        }
    }
}