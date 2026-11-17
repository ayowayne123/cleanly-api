<?php

namespace App\Services;

use App\Contracts\SubscriptionCharger;
use App\Exceptions\BookingException;
use App\Exceptions\ChargeFailedException;
use App\Exceptions\QuoteException;
use App\Exceptions\SubscriptionException;
use App\Models\Address;
use App\Models\Booking;
use App\Models\BookingCleaner;
use App\Models\Cleaner;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ReplacementRequest;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionCleaner;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionService
{
    // Visits cancelled because renewal failed carry this reason. They are recreated if the client pays up later.
    public const PAYMENT_FAILED_REASON = 'Subscription payment failed';

    private const WEEKDAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    // why a crew member cannot work a date (AvailabilityService) => booking_cleaners.replacement_reason
    private const REASON_MAP = [
        'time_off' => 'leave',
        'off_day' => 'off_day_change',
        'inactive' => 'quit',
        'booked' => 'admin_change',
        'closed_day' => 'admin_change',
    ];

    private const CANCELLABLE_VISIT_STATUSES = ['pending_payment', 'awaiting_assignment', 'assigned', 'confirmed'];

    public function __construct(
        private QuoteCalculator $quotes,
        private AvailabilityService $availability,
        private BookingService $bookings,
        private AssignmentService $assignments,
        private SubscriptionCharger $charger,
    ) {
    }

    // =====================================================================
    // Sign-up and activation
    // =====================================================================

    /**
     * Sign a client up. Nothing is charged and no visits exist until activate() runs.
     *
     * @param array{
     *   client_id:int, plan_id:int, service_id:int, address_id:int,
     *   start_date:string, weekdays:array<int,int>,
     *   bedrooms?:int, bathrooms?:int,
     *   rooms?:array<int, array{room_type_id:int, quantity?:int}>,
     *   addons?:array<int, array{addon_id:int, quantity?:int}>,
     *   is_flat?:bool, multi_floor?:bool, has_bq?:bool, cleaners_extra?:int,
     *   off_limit_areas?:?string, special_instructions?:?string,
     *   cleaner_selection?:string, preferred_cleaner_id?:?int
     * } $input    weekdays: 0 = Sunday ... 6 = Saturday
     *
     * @throws SubscriptionException
     */
    public function create(array $input, ?User $by = null): Subscription
    {
        $client = Client::find($input['client_id'] ?? null)
            ?? throw new SubscriptionException('We could not find that client.');

        $address = Address::with('area.zone')
            ->where('client_id', $client->id)
            ->find($input['address_id'] ?? null)
            ?? throw new SubscriptionException('Please choose one of your saved addresses.');

        $plan = SubscriptionPlan::active()->find($input['plan_id'] ?? null)
            ?? throw new SubscriptionException('Please choose a plan.');

        $weekdays = $this->validateWeekdays($input['weekdays'] ?? [], $plan);
        $start = $this->parseStartDate($input['start_date'] ?? null);

        // the price of ONE visit: the normal quote, with no coupon
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
            ]);
        } catch (QuoteException $e) {
            throw new SubscriptionException($e->getMessage(), 0, $e);
        }

        $periodDays = $plan->weeks_per_period * 7;
        $visitsPerPeriod = $plan->visits_per_week * $plan->weeks_per_period;
        $perVisitK = $quote->totalK;
        $periodSubtotalK = $perVisitK * $visitsPerPeriod;
        $periodDiscountK = (int) round($periodSubtotalK * $plan->discount_percent / 100);

        // optional preferred cleaner: must be green (free for every visit in the first period)
        $selection = $input['cleaner_selection'] ?? 'we_assign';
        $preferredId = null;

        if ($selection === 'preferred_cleaner') {
            $firstPeriodDates = $this->visitDates($start, $start->copy()->addDays($periodDays - 1), $weekdays)
                ->map(fn (CarbonInterface $d) => $d->toDateString())
                ->all();

            $chosen = $this->availability
                ->preferredCleanerOptions($address->area->zone_id, $firstPeriodDates)
                ->firstWhere('id', (int) ($input['preferred_cleaner_id'] ?? 0));

            if (! $chosen || ! $chosen['selectable']) {
                throw new SubscriptionException(
                    'That cleaner is not available for your visits. Please choose another or let us assign one.'
                );
            }

            $preferredId = $chosen['id'];
        } elseif ($selection !== 'we_assign') {
            throw new SubscriptionException('Please choose how your cleaner should be picked.');
        }

        return DB::transaction(function () use (
            $client, $address, $plan, $quote, $input, $weekdays, $start, $selection, $preferredId,
            $perVisitK, $periodSubtotalK, $periodDiscountK
        ) {
            $subscription = Subscription::create([
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'service_id' => $quote->serviceId,
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
                // everything needed to recreate a visit later, priced as it was today
                'visit_snapshot' => [
                    'zone_id' => $quote->zoneId,
                    'bedrooms' => (int) ($input['bedrooms'] ?? 0),
                    'bathrooms' => (int) ($input['bathrooms'] ?? 0),
                    'is_flat' => (bool) ($input['is_flat'] ?? false),
                    'multi_floor' => (bool) ($input['multi_floor'] ?? false),
                    'has_bq' => (bool) ($input['has_bq'] ?? false),
                    'cleaners_recommended' => $quote->cleanersRecommended,
                    'cleaners_extra' => $quote->cleanersExtra,
                    'off_limit_areas' => $input['off_limit_areas'] ?? null,
                    'special_instructions' => $input['special_instructions'] ?? null,
                    'rooms' => $quote->rooms,
                    'addons' => $quote->addons,
                    'subtotal_k' => $quote->subtotalK,
                    'addons_total_k' => $quote->addonsTotalK,
                    'extra_cleaners_total_k' => $quote->extraCleanersTotalK,
                    'transport_fee_k' => $quote->transportFeeK,
                    'service_fee_k' => $quote->serviceFeeK,
                ],
                'home_size_tier_id' => $quote->homeSizeTierId,
                'cleaners_required' => $quote->cleanersRequired,
                'cleaner_selection' => $selection,
                'est_duration_minutes' => $quote->estDurationMinutes,
                'start_date' => $start->toDateString(),
                // snapshots from the plan: later plan changes never alter this contract
                'visits_per_week' => $plan->visits_per_week,
                'weeks_per_period' => $plan->weeks_per_period,
                'discount_percent' => $plan->discount_percent,
                'price_per_visit_k' => $perVisitK,
                'period_subtotal_k' => $periodSubtotalK,
                'period_discount_k' => $periodDiscountK,
                'period_total_k' => $periodSubtotalK - $periodDiscountK,
                'status' => 'pending_first_payment',
            ]);

            foreach ($weekdays as $weekday) {
                $subscription->slots()->create(['weekday' => $weekday]);
            }

            if ($preferredId) {
                $subscription->crew()->create([
                    'cleaner_id' => $preferredId,
                    'role' => 'member',
                    'chosen_by' => 'client',
                    'status' => 'active',
                ]);
            }

            return $subscription->load(['slots', 'crew']);
        });
    }

    /**
     * The first payment is confirmed: start the subscription and create its visits.
     * Pass the successful Payment. Activating with no payment is an admin override.
     *
     * @return array{subscription: Subscription, visits: array}
     *
     * @throws SubscriptionException
     */
    public function activate(Subscription|int $subscription, ?Payment $payment = null, ?User $by = null): array
    {
        $id = $subscription instanceof Subscription ? $subscription->id : $subscription;

        DB::transaction(function () use ($id, $payment) {
            $sub = Subscription::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($sub->status !== 'pending_first_payment') {
                throw new SubscriptionException('This subscription is not waiting for its first payment.');
            }

            if ($payment) {
                $this->assertPaymentCovers($payment, (int) $sub->period_total_k, (int) $sub->client_id);
            }

            $start = $sub->start_date->copy();
            $end = $start->copy()->addDays($this->periodDays($sub) - 1);

            $invoice = SubscriptionInvoice::create([
                'subscription_id' => $sub->id,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'visits_count' => $sub->visits_per_week * $sub->weeks_per_period,
                'subtotal_k' => $sub->period_subtotal_k,
                'discount_k' => $sub->period_discount_k,
                'total_k' => $sub->period_total_k,
                'status' => 'paid',
                'attempts' => 1,
                'last_attempt_at' => now(),
                'paid_at' => now(),
            ]);

            $payment?->update(['subscription_invoice_id' => $invoice->id]);

            $sub->update([
                'status' => 'active',
                'authorization_id' => $payment?->authorization_id ?? $sub->authorization_id,
                'current_period_start' => $start->toDateString(),
                'current_period_end' => $end->toDateString(),
                'next_billing_date' => $end->copy()->addDay()->subDays($this->billingLeadDays())->toDateString(),
            ]);
        });

        $sub = Subscription::findOrFail($id);

        return ['subscription' => $sub, 'visits' => $this->generateVisits($sub)];
    }

    // =====================================================================
    // Visits
    // =====================================================================

    /**
     * Create any visits that are missing, from the start of the paid period up to
     * `visit_generation_weeks_ahead` weeks ahead. Safe to run again and again.
     *
     * @return array{created:int, skipped_dates:array<int,string>, needs_admin:int}
     */
    public function generateVisits(Subscription $subscription): array
    {
        $sub = $subscription->fresh(['slots']);
        $summary = ['created' => 0, 'skipped_dates' => [], 'needs_admin' => 0];

        if (! in_array($sub->status, ['active', 'past_due'], true) || ! $sub->current_period_end) {
            return $summary;
        }

        $earliest = today()->addDays(max(0, (int) Setting::get('min_lead_days', 1)));
        $from = $sub->current_period_start->copy()->max($earliest);
        $to = today()->addWeeks((int) Setting::get('visit_generation_weeks_ahead', 8));

        if ($sub->ends_at) {
            $to = $to->min($sub->ends_at);
        }

        // while payment is overdue, only the visits that are already paid for
        if ($sub->status === 'past_due') {
            $to = $to->min($sub->current_period_end);
        }

        $weekdays = $sub->slots->pluck('weekday')->map(fn ($w) => (int) $w)->all();
        $dates = $this->visitDates($from, $to, $weekdays);

        if ($dates->isEmpty()) {
            return $summary;
        }

        $days = WorkDay::whereBetween('work_date', [$dates->first()->toDateString(), $dates->last()->toDateString()])
            ->get()
            ->keyBy(fn (WorkDay $d) => $d->work_date->toDateString());

        foreach ($dates as $date) {
            $key = $date->toDateString();
            $day = $days->get($key);

            // a closed day (or no work day yet) cannot be cleaned: report it for admin
            if (! $day || ! $day->is_open) {
                $summary['skipped_dates'][] = $key;
                continue;
            }

            if ($this->visitExists($sub, $day)) {
                continue;
            }

            $covered = $date->lte($sub->current_period_end);

            $visit = DB::transaction(function () use ($sub, $day, $covered) {
                $visit = $this->createVisit($sub, $day, $covered);
                $this->staffVisit($visit, $sub);

                return $visit;
            });

            $summary['created']++;

            if (! $visit->isFullyStaffed()) {
                $summary['needs_admin']++;
            }
        }

        return $summary;
    }

    /** Top up the visits of every running subscription. */
    public function generateAllVisits(): array
    {
        $total = ['created' => 0, 'needs_admin' => 0, 'skipped_dates' => 0];

        foreach (Subscription::whereIn('status', ['active', 'past_due'])->pluck('id') as $id) {
            $result = $this->generateVisits(Subscription::findOrFail($id));

            $total['created'] += $result['created'];
            $total['needs_admin'] += $result['needs_admin'];
            $total['skipped_dates'] += count($result['skipped_dates']);
        }

        return $total;
    }

    private function createVisit(Subscription $sub, WorkDay $day, bool $covered): Booking
    {
        $snap = $sub->visit_snapshot;
        $perVisitK = (int) $sub->price_per_visit_k;
        $visitsPerPeriod = max(1, $sub->visits_per_week * $sub->weeks_per_period);
        $discountK = intdiv((int) $sub->period_discount_k, $visitsPerPeriod);   // this visit's share of the discount
        $start = $day->work_date->copy()->setTimeFromTimeString($day->opens_at);

        $visit = Booking::create([
            'client_id' => $sub->client_id,
            'service_id' => $sub->service_id,
            'subscription_id' => $sub->id,
            'address_id' => $sub->address_id,
            'address_snapshot' => $sub->address_snapshot,
            'zone_id' => $snap['zone_id'],
            'work_day_id' => $day->id,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($sub->est_duration_minutes),
            'est_duration_minutes' => $sub->est_duration_minutes,
            'bedrooms' => $snap['bedrooms'],
            'bathrooms' => $snap['bathrooms'],
            'is_flat' => $snap['is_flat'],
            'multi_floor' => $snap['multi_floor'],
            'has_bq' => $snap['has_bq'],
            'home_size_tier_id' => $sub->home_size_tier_id,
            'cleaners_recommended' => $snap['cleaners_recommended'],
            'cleaners_extra' => $snap['cleaners_extra'],
            'cleaners_required' => $sub->cleaners_required,
            'off_limit_areas' => $snap['off_limit_areas'],
            'special_instructions' => $snap['special_instructions'],
            'assignment_mode' => 'roster',
            'status' => 'awaiting_assignment',
            'payment_status' => $covered ? 'covered_by_subscription' : 'unpaid',
            'subtotal_k' => $snap['subtotal_k'],
            'addons_total_k' => $snap['addons_total_k'],
            'extra_cleaners_total_k' => $snap['extra_cleaners_total_k'],
            'transport_fee_k' => $snap['transport_fee_k'],
            'service_fee_k' => $snap['service_fee_k'],
            'discount_k' => $discountK,
            'total_k' => max(0, $perVisitK - $discountK),
            'source' => 'subscription',
        ]);

        foreach ($snap['rooms'] as $room) {
            $visit->rooms()->create(Arr::only($room, ['room_type_id', 'quantity', 'unit_price_k']));
        }

        foreach ($snap['addons'] as $addon) {
            $visit->addons()->create(Arr::only($addon, ['addon_id', 'quantity', 'unit_price_k']));
        }

        return $visit;
    }

    private function visitExists(Subscription $sub, WorkDay $day): bool
    {
        return Booking::where('subscription_id', $sub->id)
            ->where('work_day_id', $day->id)
            ->where('status', '!=', 'expired')
            // a visit cancelled for non-payment may be recreated; one the client cancelled may not
            ->where(fn ($q) => $q->whereNull('cancel_reason')->orWhere('cancel_reason', '!=', self::PAYMENT_FAILED_REASON))
            ->exists();
    }

    // =====================================================================
    // Staffing a visit (section 3.8)
    // =====================================================================

    /**
     * Put the usual crew on a visit. A crew member who cannot do the date is recorded
     * as needs_replacement and a stand-in is found. If the crew is smaller than the crew
     * required, the remaining places are left for admin.
     */
    private function staffVisit(Booking $visit, Subscription $sub): void
    {
        $visit->loadMissing('workDay');
        $date = $visit->workDay->work_date;
        $needed = $visit->cleaners_required - $visit->activeAssignments()->count();

        $crew = $sub->activeCrew()
            ->with('cleaner')
            ->orderByRaw("chosen_by = 'client' desc")
            ->orderBy('id')
            ->get();

        foreach ($crew as $member) {
            if ($needed <= 0) {
                break;
            }

            $cleaner = $member->cleaner;

            if ($this->isOnVisit($visit, $cleaner)) {
                continue;
            }

            $reason = $this->availability->unavailabilityReason($cleaner, $date);

            if ($reason === null && $this->place($visit, $cleaner, $member->role, 'assigned')) {
                $needed--;
                continue;
            }

            // This crew member cannot do this date (or lost a race for it): record it, look for a stand-in
            $replacementReason = self::REASON_MAP[$reason ?? 'booked'] ?? 'admin_change';
            $original = $this->place($visit, $cleaner, $member->role, 'needs_replacement', null, $replacementReason);

            $request = ReplacementRequest::firstOrCreate(
                ['booking_cleaner_id' => $original->id],
                [
                    'booking_id' => $visit->id,
                    'subscription_id' => $sub->id,
                    'reason' => $replacementReason,
                    'status' => 'open',
                ],
            );

            $standIn = $this->findStandIn($visit, $sub);
            $standInRow = $standIn
                ? $this->place($visit, $standIn, 'member', 'assigned', $original->id, $replacementReason)
                : null;

            if ($standInRow) {
                $request->update([
                    'status' => 'replaced',
                    'replacement_booking_cleaner_id' => $standInRow->id,
                    'resolved_at' => now(),
                ]);

                $needed--;
            }
        }

        $visit->refresh();
        $fullyStaffed = $visit->isFullyStaffed();

        if ($fullyStaffed && $visit->status === 'awaiting_assignment') {
            $visit->changeStatus('assigned', null, 'Usual crew assigned');
        } elseif (! $fullyStaffed && in_array($visit->status, ['assigned', 'confirmed'], true)) {
            $visit->changeStatus('awaiting_assignment', null, 'Crew incomplete');
        }
    }

    // Free that day, same zone first, then anyone who has worked for this client before, then best rated
    private function findStandIn(Booking $visit, Subscription $sub): ?Cleaner
    {
        $already = $visit->assignments()->pluck('cleaner_id')->all();   // anyone with any row on this visit is out

        $workedWith = BookingCleaner::where('status', 'completed')
            ->whereIn('booking_id', Booking::where('client_id', $sub->client_id)->select('id'))
            ->pluck('cleaner_id')
            ->unique()
            ->all();

        return $this->availability->availableCleaners($visit->workDay->work_date, $visit->zone_id)
            ->reject(fn (Cleaner $c) => in_array($c->id, $already, true))
            ->sort(fn (Cleaner $a, Cleaner $b) => [! $a->in_zone, ! in_array($a->id, $workedWith, true), -(float) $a->avg_rating, $a->id]
                <=> [! $b->in_zone, ! in_array($b->id, $workedWith, true), -(float) $b->avg_rating, $b->id])
            ->first();
    }

    private function isOnVisit(Booking $visit, Cleaner $cleaner): bool
    {
        return BookingCleaner::where('booking_id', $visit->id)
            ->where('cleaner_id', $cleaner->id)
            ->whereIn('status', BookingCleaner::ACTIVE_STATUSES)
            ->exists();
    }

    // One row per cleaner per booking, so an existing row is reused. Null means the database
    // refused the double booking (the unique index), so the caller treats it as a conflict.
    private function place(
        Booking $visit,
        Cleaner $cleaner,
        string $role,
        string $status,
        ?int $replacesId = null,
        ?string $reason = null,
    ): ?BookingCleaner {
        $attributes = [
            'role' => $role,
            'status' => $status,
            'replaces_id' => $replacesId,
            'replacement_reason' => $reason,
            'responded_at' => null,
        ];

        $existing = BookingCleaner::where('booking_id', $visit->id)->where('cleaner_id', $cleaner->id)->first();

        try {
            return DB::transaction(function () use ($visit, $cleaner, $attributes, $existing, $status) {
                $row = $existing
                    ? tap($existing)->update($attributes)
                    : $visit->assignments()->create(['cleaner_id' => $cleaner->id] + $attributes);

                // the crew member is back on the visit, so the replacement request is no longer needed
                if ($status === 'assigned') {
                    ReplacementRequest::where('booking_cleaner_id', $row->id)
                        ->where('status', 'open')
                        ->update(['status' => 'cancelled']);
                }

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Try to staff every future visit that is still short of its crew. Returns how many are now fully staffed. */
    public function staffFutureVisits(Subscription $subscription): int
    {
        $staffed = 0;

        $visits = Booking::where('subscription_id', $subscription->id)
            ->where('status', 'awaiting_assignment')
            ->where('start_at', '>', now())
            ->with('workDay')
            ->orderBy('start_at')
            ->get();

        foreach ($visits as $visit) {
            DB::transaction(fn () => $this->staffVisit($visit, $subscription));

            if ($visit->refresh()->status === 'assigned') {
                $staffed++;
            }
        }

        return $staffed;
    }

    // =====================================================================
    // The usual crew (admin)
    // =====================================================================

    /** Add a cleaner to the usual crew and put them on future visits that still have room. */
    public function addToCrew(Subscription|int $subscription, Cleaner|int $cleaner, ?User $by = null): SubscriptionCleaner
    {
        $subId = $subscription instanceof Subscription ? $subscription->id : $subscription;
        $cleanerId = $cleaner instanceof Cleaner ? $cleaner->id : $cleaner;

        $row = DB::transaction(function () use ($subId, $cleanerId) {
            $sub = Subscription::whereKey($subId)->lockForUpdate()->firstOrFail();
            $cleaner = Cleaner::findOrFail($cleanerId);

            if (in_array($sub->status, ['cancelled', 'expired'], true)) {
                throw new SubscriptionException('This subscription has ended.');
            }

            if ($cleaner->status !== 'active') {
                throw new SubscriptionException('That cleaner is not active.');
            }

            $existing = $sub->crew()->where('cleaner_id', $cleaner->id)->first();

            if ($existing && $existing->status === 'active') {
                throw new SubscriptionException('That cleaner is already in the usual crew.');
            }

            if ($sub->crew()->where('status', 'active')->count() >= $sub->cleaners_required) {
                throw new SubscriptionException('The usual crew is already full.');
            }

            if ($existing) {
                $existing->update(['status' => 'active', 'removed_at' => null, 'added_at' => now()]);

                return $existing;
            }

            return $sub->crew()->create([
                'cleaner_id' => $cleaner->id,
                'role' => 'member',
                'chosen_by' => 'admin',
                'status' => 'active',
            ]);
        });

        $this->staffFutureVisits(Subscription::findOrFail($subId));

        return $row;
    }

    /** Take a cleaner out of the usual crew, off every future visit, and refill those places (section 3.8, case 3). */
    public function removeFromCrew(Subscription|int $subscription, Cleaner|int $cleaner, ?User $by = null): void
    {
        $subId = $subscription instanceof Subscription ? $subscription->id : $subscription;
        $cleanerId = $cleaner instanceof Cleaner ? $cleaner->id : $cleaner;

        DB::transaction(function () use ($subId, $cleanerId) {
            $sub = Subscription::whereKey($subId)->lockForUpdate()->firstOrFail();

            $row = $sub->crew()->where('cleaner_id', $cleanerId)->where('status', 'active')->first();

            if (! $row) {
                throw new SubscriptionException('That cleaner is not in the usual crew.');
            }

            $row->update(['status' => 'removed', 'removed_at' => now()]);
        });

        $assignments = BookingCleaner::where('cleaner_id', $cleanerId)
            ->whereIn('status', ['offered', 'accepted', 'assigned'])
            ->whereHas('booking', fn ($q) => $q->where('subscription_id', $subId)->where('start_at', '>', now()))
            ->get();

        foreach ($assignments as $assignment) {
            $this->assignments->unassign($assignment, $by);
        }

        $this->staffFutureVisits(Subscription::findOrFail($subId));
    }

    // =====================================================================
    // Renewals, retries and failure handling
    // =====================================================================

    /**
     * Bill every subscription that is due. With no card charger connected, due renewals simply wait.
     *
     * @return array{billed:int, failed:int, waiting_for_charger:int, expired:int}
     */
    public function processRenewals(): array
    {
        $result = ['billed' => 0, 'failed' => 0, 'waiting_for_charger' => 0, 'expired' => 0];

        foreach (Subscription::due()->pluck('id') as $id) {
            $sub = Subscription::findOrFail($id);
            [$start, $end] = $this->nextPeriod($sub);

            // a subscription with an end date does not renew past it
            if ($sub->ends_at && $start->gt($sub->ends_at)) {
                $sub->update(['status' => 'expired']);
                $result['expired']++;
                continue;
            }

            if (! $this->charger->isConfigured()) {
                $result['waiting_for_charger']++;
                continue;
            }

            $invoice = SubscriptionInvoice::firstOrCreate(
                ['subscription_id' => $sub->id, 'period_start' => $start->toDateString()],
                [
                    'period_end' => $end->toDateString(),
                    'visits_count' => $sub->visits_per_week * $sub->weeks_per_period,
                    'subtotal_k' => $sub->period_subtotal_k,
                    'discount_k' => $sub->period_discount_k,
                    'total_k' => $sub->period_total_k,
                    'status' => 'pending',
                ],
            );

            $this->attempt($invoice->id) ? $result['billed']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * Try again for failed invoices whose retry time has come.
     *
     * @return array{paid:int, failed:int, waiting_for_charger:int}
     */
    public function processRetries(): array
    {
        $result = ['paid' => 0, 'failed' => 0, 'waiting_for_charger' => 0];

        if (! $this->charger->isConfigured()) {
            $result['waiting_for_charger'] = SubscriptionInvoice::retryDue()->count();

            return $result;
        }

        foreach (SubscriptionInvoice::retryDue()->pluck('id') as $id) {
            $this->attempt((int) $id) ? $result['paid']++ : $result['failed']++;
        }

        return $result;
    }

    /**
     * One charge attempt. Returns true if the invoice was paid.
     * The charge itself happens OUTSIDE any database transaction (it is a slow call to the bank),
     * so the scheduled job runs with withoutOverlapping() to avoid charging an invoice twice.
     */
    public function attempt(SubscriptionInvoice|int $invoice): bool
    {
        if (! $this->charger->isConfigured()) {
            throw new SubscriptionException('Card charging is not connected yet.');
        }

        $id = $invoice instanceof SubscriptionInvoice ? $invoice->id : $invoice;

        // 1) note the attempt (a short transaction)
        $claimed = DB::transaction(function () use ($id) {
            $invoice = SubscriptionInvoice::whereKey($id)->lockForUpdate()->firstOrFail();
            $sub = Subscription::whereKey($invoice->subscription_id)->lockForUpdate()->firstOrFail();

            if ($invoice->status === 'paid' || ! in_array($sub->status, ['active', 'past_due'], true)) {
                return null;
            }

            $invoice->update([
                'attempts' => $invoice->attempts + 1,
                'last_attempt_at' => now(),
                'next_retry_at' => null,
            ]);

            return [$invoice->fresh(), $sub];
        });

        if (! $claimed) {
            return false;
        }

        [$invoice, $sub] = $claimed;

        // 2) charge the saved card
        try {
            $payment = $this->charger->charge($sub, $invoice);
        } catch (ChargeFailedException $e) {
            $this->recordFailure($sub->id, $invoice->id, $e->getMessage());

            return false;
        }

        // 3) the money arrived
        $this->settle($sub->id, $invoice->id, $payment);

        return true;
    }

    private function recordFailure(int $subscriptionId, int $invoiceId, string $message): void
    {
        $exhausted = DB::transaction(function () use ($subscriptionId, $invoiceId) {
            $invoice = SubscriptionInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $sub = Subscription::whereKey($subscriptionId)->lockForUpdate()->firstOrFail();

            // attempt 1 fails -> retry after schedule[0] days, attempt 2 -> schedule[1], and so on
            $schedule = array_values((array) Setting::get('payment_retry_schedule', [1, 3, 5]));
            $retryInDays = $schedule[$invoice->attempts - 1] ?? null;

            $invoice->update([
                'status' => 'failed',
                'next_retry_at' => $retryInDays === null ? null : now()->addDays((int) $retryInDays),
            ]);

            $sub->update(['status' => 'past_due']);

            return $retryInDays === null;
        });

        Log::warning("Subscription {$subscriptionId}: renewal charge failed ({$message}).");

        // out of retries: the visits of the unpaid period are cancelled and their cleaners freed
        if ($exhausted) {
            $this->cancelFutureVisits(Subscription::findOrFail($subscriptionId), self::PAYMENT_FAILED_REASON, unpaidOnly: true);
        }
    }

    private function settle(int $subscriptionId, int $invoiceId, Payment $payment): void
    {
        DB::transaction(function () use ($subscriptionId, $invoiceId, $payment) {
            $invoice = SubscriptionInvoice::whereKey($invoiceId)->lockForUpdate()->firstOrFail();
            $sub = Subscription::whereKey($subscriptionId)->lockForUpdate()->firstOrFail();

            $invoice->update(['status' => 'paid', 'paid_at' => now(), 'next_retry_at' => null]);

            if (! $payment->subscription_invoice_id) {
                $payment->update(['subscription_invoice_id' => $invoice->id]);
            }

            // the coverage window moves forward to the period that was just paid
            $sub->update([
                'status' => 'active',
                'current_period_start' => $invoice->period_start->toDateString(),
                'current_period_end' => $invoice->period_end->toDateString(),
                'next_billing_date' => $invoice->period_end->copy()->addDay()->subDays($this->billingLeadDays())->toDateString(),
            ]);

            // that period's visits are now paid for
            $dayIds = WorkDay::whereBetween('work_date', [
                $invoice->period_start->toDateString(),
                $invoice->period_end->toDateString(),
            ])->pluck('id');

            Booking::where('subscription_id', $sub->id)
                ->where('payment_status', 'unpaid')
                ->whereNotIn('status', ['cancelled', 'expired'])
                ->whereIn('work_day_id', $dayIds)
                ->update(['payment_status' => 'covered_by_subscription']);
        });

        // more visits for the weeks that are now in range (and any that were cancelled for non-payment)
        $this->generateVisits(Subscription::findOrFail($subscriptionId));
    }

    // =====================================================================
    // Ending a subscription
    // =====================================================================

    /**
     * Cancel a subscription: it ends, all future visits are cancelled and their cleaners freed.
     * Refunds or credits for paid, unused visits are not handled yet.
     */
    public function cancel(Subscription|int $subscription, ?User $by = null, ?string $reason = null): Subscription
    {
        $id = $subscription instanceof Subscription ? $subscription->id : $subscription;

        $sub = DB::transaction(function () use ($id, $reason) {
            $sub = Subscription::whereKey($id)->lockForUpdate()->firstOrFail();

            if (in_array($sub->status, ['cancelled', 'expired'], true)) {
                throw new SubscriptionException('This subscription has already ended.');
            }

            $sub->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);

            return $sub;
        });

        $this->cancelFutureVisits($sub, 'Subscription cancelled', by: $by);

        return $sub->fresh();
    }

    /** Sign-ups that were never paid within `unpaid_expiry_minutes` expire. Returns how many. */
    public function expirePending(): int
    {
        $minutes = (int) Setting::get('unpaid_expiry_minutes', 30);

        return Subscription::where('status', 'pending_first_payment')
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->update(['status' => 'expired']);
    }

    private function cancelFutureVisits(Subscription $sub, string $reason, bool $unpaidOnly = false, ?User $by = null): int
    {
        $query = Booking::where('subscription_id', $sub->id)
            ->whereIn('status', self::CANCELLABLE_VISIT_STATUSES)
            ->where('start_at', '>', now());

        if ($unpaidOnly) {
            $query->where('payment_status', 'unpaid');
        }

        $cancelled = 0;

        foreach ($query->pluck('id') as $id) {
            try {
                $this->bookings->cancel((int) $id, $by, $reason);
                $cancelled++;
            } catch (BookingException) {
                // it moved on in the meantime (for example it just started)
            }
        }

        return $cancelled;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function periodDays(Subscription $sub): int
    {
        return $sub->weeks_per_period * 7;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function nextPeriod(Subscription $sub): array
    {
        $start = $sub->current_period_end->copy()->addDay();

        return [$start, $start->copy()->addDays($this->periodDays($sub) - 1)];
    }

    private function billingLeadDays(): int
    {
        return max(0, (int) Setting::get('subscription_billing_lead_days', 5));
    }

    /** @return Collection<int, Carbon> every date from $from to $to (both included) that falls on one of the weekdays */
    private function visitDates(CarbonInterface $from, CarbonInterface $to, array $weekdays): Collection
    {
        $dates = collect();
        $last = $to->copy()->startOfDay();

        for ($d = $from->copy()->startOfDay(); $d->lte($last); $d->addDay()) {
            if (in_array($d->dayOfWeek, $weekdays, true)) {
                $dates->push($d->copy());
            }
        }

        return $dates;
    }

    /** @return array<int, int> */
    private function validateWeekdays(mixed $weekdays, SubscriptionPlan $plan): array
    {
        $list = [];

        foreach ((array) $weekdays as $w) {
            if (! is_numeric($w) || $w < 0 || $w > 6 || (int) $w != $w) {
                throw new SubscriptionException('Please choose valid days of the week.');
            }

            $list[] = (int) $w;
        }

        $list = array_values(array_unique($list));

        if (count($list) !== $plan->visits_per_week) {
            throw new SubscriptionException("This plan needs exactly {$plan->visits_per_week} day(s) a week.");
        }

        $hours = (array) Setting::get('work_hours', []);

        foreach ($list as $w) {
            if (! isset($hours[self::WEEKDAY_KEYS[$w]])) {
                throw new SubscriptionException('We do not take bookings on one of those days.');
            }
        }

        sort($list);

        return $list;
    }

    private function parseStartDate(mixed $value): Carbon
    {
        if (blank($value)) {
            throw new SubscriptionException('Please choose a start date.');
        }

        try {
            $date = Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw new SubscriptionException('Please choose a start date.');
        }

        $earliest = today()->addDays(max(0, (int) Setting::get('min_lead_days', 1)));

        if ($date->lt($earliest)) {
            throw new SubscriptionException('That start date is too soon. Please choose a later date.');
        }

        if ($date->gt(today()->addDays(60))) {
            throw new SubscriptionException('Please choose a start date within the next 60 days.');
        }

        return $date;
    }

    private function assertPaymentCovers(Payment $payment, int $amountK, int $clientId): void
    {
        if (
            ! $payment->isSuccessful()
            || (int) $payment->client_id !== $clientId
            || $payment->subscription_invoice_id
            || (int) $payment->amount_k < $amountK
        ) {
            throw new SubscriptionException('That payment does not cover this subscription.');
        }
    }
}