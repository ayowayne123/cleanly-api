<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Exceptions\BookingException;
use App\Exceptions\ReplacementException;
use App\Exceptions\SubscriptionException;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingCleaner;
use App\Models\Cleaner;
use App\Models\CleanerTimeOff;
use App\Models\ReplacementRequest;
use App\Models\SubscriptionCleaner;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReplacementService
{
    // a job still ahead of us, in one of these booking statuses, can still be rescued
    private const LIVE_BOOKING_STATUSES = ['awaiting_assignment', 'assigned', 'confirmed'];

    // AvailabilityService::standingConflict() => booking_cleaners.replacement_reason
    private const REASONS = ['time_off' => 'leave', 'off_day' => 'off_day_change', 'inactive' => 'quit'];

    public function __construct(
        private AvailabilityService $availability,
        private AssignmentService $assignments,
        private BookingService $bookings,
        private SubscriptionService $subscriptions,
    ) {
    }

    // =====================================================================
    // Triggers
    // =====================================================================

    /**
     * Record a leave request. Pending until approved; with $approve = true it is approved straight away.
     *
     * @return array{time_off: CleanerTimeOff, summary: ?array}
     *
     * @throws ReplacementException
     */
    public function recordTimeOff(
        Cleaner|int $cleaner,
        string $startDate,
        string $endDate,
        string $type = 'leave',
        ?string $reason = null,
        ?User $by = null,
        bool $approve = false,
    ): array {
        $cleanerId = $cleaner instanceof Cleaner ? $cleaner->id : $cleaner;
        $start = $this->parseDate($startDate);
        $end = $this->parseDate($endDate);

        if ($end->lt($start)) {
            throw new ReplacementException('The end date cannot be before the start date.');
        }

        if ($end->lt(today())) {
            throw new ReplacementException('That period is already over.');
        }

        if (! in_array($type, ['leave', 'sick', 'other'], true)) {
            throw new ReplacementException('Please choose a valid type of leave.');
        }

        $timeOff = CleanerTimeOff::create([
            'cleaner_id' => Cleaner::findOrFail($cleanerId)->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'type' => $type,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        return $approve
            ? $this->approveTimeOff($timeOff, $by)
            : ['time_off' => $timeOff, 'summary' => null];
    }

    /**
     * Approve leave: every future job in those dates is flagged and a stand-in is looked for.
     *
     * @return array{time_off: CleanerTimeOff, summary: array{affected:int, replaced:int, open:int}}
     *
     * @throws ReplacementException
     */
    public function approveTimeOff(CleanerTimeOff|int $timeOff, ?User $by = null): array
    {
        $id = $timeOff instanceof CleanerTimeOff ? $timeOff->id : $timeOff;

        $timeOff = DB::transaction(function () use ($id, $by) {
            $leave = CleanerTimeOff::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($leave->status !== 'pending') {
                throw new ReplacementException('That leave request has already been dealt with.');
            }

            $leave->update(['status' => 'approved', 'approved_by_user_id' => $by?->id]);

            return $leave;
        });

        $this->audit('cleaner.leave_approved', $timeOff, null, [
            'cleaner_id' => $timeOff->cleaner_id,
            'from' => $timeOff->start_date->toDateString(),
            'to' => $timeOff->end_date->toDateString(),
        ], $by);

        $summary = $this->scanCleaner($timeOff->cleaner_id, $timeOff->type === 'sick' ? 'sick' : 'leave', $by);

        return ['time_off' => $timeOff->fresh(), 'summary' => $summary];
    }

    /** @throws ReplacementException */
    public function rejectTimeOff(CleanerTimeOff|int $timeOff, ?User $by = null): CleanerTimeOff
    {
        $id = $timeOff instanceof CleanerTimeOff ? $timeOff->id : $timeOff;

        return DB::transaction(function () use ($id, $by) {
            $leave = CleanerTimeOff::whereKey($id)->lockForUpdate()->firstOrFail();

            if ($leave->status !== 'pending') {
                throw new ReplacementException('That leave request has already been dealt with.');
            }

            $leave->update(['status' => 'rejected', 'approved_by_user_id' => $by?->id]);

            return $leave;
        });
    }

    /**
     * Change a cleaner's weekly off day. Jobs already on the new off weekday are flagged and re-staffed.
     * Use previewOffDayChange() first to see how many jobs that will touch.
     *
     * @return array{affected:int, replaced:int, open:int}
     *
     * @throws ReplacementException
     */
    public function changeWeeklyOffDay(Cleaner|int $cleaner, int $newDay, ?User $by = null): array
    {
        if ($newDay < 0 || $newDay > 6) {
            throw new ReplacementException('Please choose a day from 0 (Sunday) to 6 (Saturday).');
        }

        $cleaner = $cleaner instanceof Cleaner ? $cleaner : Cleaner::findOrFail($cleaner);
        $old = $cleaner->weekly_off_day;

        if ($old !== null && (int) $old === $newDay) {
            throw new ReplacementException('That is already their off day.');
        }

        $cleaner->update(['weekly_off_day' => $newDay]);

        $this->audit('cleaner.off_day_changed', $cleaner, ['weekly_off_day' => $old], ['weekly_off_day' => $newDay], $by);

        return $this->scanCleaner($cleaner->id, null, $by);
    }

    /**
     * A cleaner quits or is suspended. Stand-ins are found for all their future jobs first,
     * then they are taken out of every usual crew (each of those crews is then one short until admin adds someone).
     *
     * @return array{affected:int, replaced:int, open:int, removed_from_crews:int}
     *
     * @throws ReplacementException
     */
    public function offboard(Cleaner|int $cleaner, string $status = 'inactive', ?User $by = null): array
    {
        if (! in_array($status, ['inactive', 'suspended'], true)) {
            throw new ReplacementException('A cleaner can only be set to inactive or suspended here.');
        }

        $cleaner = $cleaner instanceof Cleaner ? $cleaner : Cleaner::findOrFail($cleaner);
        $old = $cleaner->status;

        $cleaner->update(['status' => $status]);

        $this->audit('cleaner.status_changed', $cleaner, ['status' => $old], ['status' => $status], $by);

        $summary = $this->scanCleaner($cleaner->id, 'quit', $by);

        $removed = 0;

        foreach (SubscriptionCleaner::active()->where('cleaner_id', $cleaner->id)->pluck('subscription_id') as $subscriptionId) {
            try {
                $this->subscriptions->removeFromCrew((int) $subscriptionId, $cleaner->id, $by);
                $removed++;
            } catch (SubscriptionException) {
                // the subscription has already ended
            }
        }

        return $summary + ['removed_from_crews' => $removed];
    }

    /**
     * Find every future job this cleaner can no longer do (inactive, off day, approved leave),
     * flag it, and look for a stand-in. Safe to run again.
     *
     * @return array{affected:int, replaced:int, open:int}
     */
    public function scanCleaner(Cleaner|int $cleaner, ?string $reasonOverride = null, ?User $by = null): array
    {
        $cleaner = $cleaner instanceof Cleaner ? $cleaner->fresh() : Cleaner::findOrFail($cleaner);
        $summary = ['affected' => 0, 'replaced' => 0, 'open' => 0];

        foreach ($this->futureAssignments($cleaner->id)->orderBy('work_date')->get() as $assignment) {
            $conflict = $this->availability->standingConflict($cleaner, $assignment->work_date);

            if ($conflict === null) {
                continue;
            }

            $summary['affected']++;

            $request = $this->flag($assignment, $reasonOverride ?? self::REASONS[$conflict], $by);

            $this->tryAutoReplace($request, $by) ? $summary['replaced']++ : $summary['open']++;
        }

        return $summary;
    }

    // =====================================================================
    // Previews (read-only), so admin can plan before approving
    // =====================================================================

    /** @return Collection<int, BookingCleaner> the jobs this leave would affect */
    public function previewTimeOff(CleanerTimeOff $timeOff): Collection
    {
        return $this->futureAssignments($timeOff->cleaner_id)
            ->whereBetween('work_date', [$timeOff->start_date->toDateString(), $timeOff->end_date->toDateString()])
            ->with(['booking.workDay', 'booking.client.user'])
            ->orderBy('work_date')
            ->get();
    }

    /** @return Collection<int, BookingCleaner> the jobs a new off day would affect */
    public function previewOffDayChange(Cleaner $cleaner, int $newDay): Collection
    {
        return $this->futureAssignments($cleaner->id)
            ->whereRaw('DAYOFWEEK(work_date) = ?', [$newDay + 1])   // MySQL: 1 = Sunday
            ->with(['booking.workDay', 'booking.client.user'])
            ->orderBy('work_date')
            ->get();
    }

    // =====================================================================
    // The admin "Needs replacement" queue
    // =====================================================================

    /** @return Collection<int, ReplacementRequest> open requests for jobs that are still ahead, soonest first */
    public function openQueue(): Collection
    {
        return ReplacementRequest::open()
            ->whereHas('booking', fn ($q) => $q->whereIn('status', self::LIVE_BOOKING_STATUSES)->where('start_at', '>', now()))
            ->with(['booking.workDay', 'booking.client.user', 'bookingCleaner.cleaner.user'])
            ->orderBy(Booking::select('start_at')->whereColumn('bookings.id', 'replacement_requests.booking_id'))
            ->get();
    }

    /** Try to find a stand-in for an open request. Returns true if one was placed. */
    public function tryAutoReplace(ReplacementRequest|int $request, ?User $by = null): bool
    {
        $id = $request instanceof ReplacementRequest ? $request->id : $request;
        $request = ReplacementRequest::with(['booking.workDay', 'bookingCleaner'])->findOrFail($id);

        if ($request->status !== 'open') {
            return false;
        }

        $booking = $request->booking;

        if (! in_array($booking->status, self::LIVE_BOOKING_STATUSES, true) || $booking->start_at->isPast()) {
            return false;
        }

        $standIn = $this->findStandIn($booking);

        if (! $standIn) {
            return false;
        }

        try {
            $row = $this->place($booking, $standIn, $request->bookingCleaner, $by);
        } catch (AssignmentException) {
            return false;   // lost a race for that cleaner: the request stays open
        }

        $request->update([
            'status' => 'replaced',
            'replacement_booking_cleaner_id' => $row->id,
            'resolved_by_user_id' => $by?->id,
            'resolved_at' => now(),
        ]);

        return true;
    }

    /**
     * Admin picks the stand-in.
     *
     * @throws ReplacementException
     */
    public function assignStandIn(ReplacementRequest|int $request, Cleaner|int $cleaner, ?User $by = null): ReplacementRequest
    {
        $id = $request instanceof ReplacementRequest ? $request->id : $request;
        $request = ReplacementRequest::with(['booking', 'bookingCleaner'])->findOrFail($id);
        $cleaner = $cleaner instanceof Cleaner ? $cleaner : Cleaner::findOrFail($cleaner);

        if ($request->status !== 'open') {
            throw new ReplacementException('This request has already been dealt with.');
        }

        try {
            $row = $this->place($request->booking, $cleaner, $request->bookingCleaner, $by);
        } catch (AssignmentException $e) {
            throw new ReplacementException($e->getMessage(), 0, $e);
        }

        $request->update([
            'status' => 'replaced',
            'replacement_booking_cleaner_id' => $row->id,
            'resolved_by_user_id' => $by?->id,
            'resolved_at' => now(),
        ]);

        return $request->fresh(['replacementAssignment']);
    }

    /**
     * Admin moves the job to another day (the same week or another week) instead of finding a stand-in.
     * The original cleaner stays on it if they are free on the new date.
     *
     * @throws ReplacementException
     */
    public function moveVisit(ReplacementRequest|int $request, string $newDate, ?User $by = null, ?string $reason = null): ReplacementRequest
    {
        $id = $request instanceof ReplacementRequest ? $request->id : $request;
        $request = ReplacementRequest::with(['booking', 'bookingCleaner'])->findOrFail($id);

        if ($request->status !== 'open') {
            throw new ReplacementException('This request has already been dealt with.');
        }

        $booking = $request->booking;
        $original = $request->bookingCleaner;
        $reason ??= 'Cleaner unavailable';

        try {
            if ($booking->subscription_id) {
                // also refills the crew places (usual crew first, then stand-ins)
                $this->subscriptions->rescheduleVisit($booking, $newDate, $by, $reason);
            } else {
                $this->bookings->reschedule($booking, $newDate, $by, $reason);

                try {
                    $this->assignments->assign($booking->id, $original->cleaner_id, $by, $original->role);
                } catch (AssignmentException) {
                    // not free on the new date: the job waits in the normal admin queue
                }
            }
        } catch (BookingException|SubscriptionException $e) {
            throw new ReplacementException($e->getMessage(), 0, $e);
        }

        $request->update(['status' => 'moved', 'resolved_by_user_id' => $by?->id, 'resolved_at' => now()]);

        return $request->fresh();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    // The job can no longer be done by this cleaner: free their day, put the booking back in the queue, open a request.
    private function flag(BookingCleaner $assignment, string $reason, ?User $by): ReplacementRequest
    {
        return DB::transaction(function () use ($assignment, $reason, $by) {
            $booking = Booking::whereKey($assignment->booking_id)->lockForUpdate()->firstOrFail();

            // the model clears active_work_date, so the cleaner's day is freed
            $assignment->update(['status' => 'needs_replacement', 'replacement_reason' => $reason]);

            if (in_array($booking->status, ['assigned', 'confirmed'], true)) {
                $booking->changeStatus('awaiting_assignment', $by, 'A cleaner can no longer do this job');
            }

            return ReplacementRequest::updateOrCreate(
                ['booking_cleaner_id' => $assignment->id],
                [
                    'booking_id' => $booking->id,
                    'subscription_id' => $booking->subscription_id,
                    'reason' => $reason,
                    'status' => 'open',
                    'replacement_booking_cleaner_id' => null,
                    'resolved_by_user_id' => null,
                    'resolved_at' => null,
                ],
            );
        });
    }

    // Puts the stand-in on the job with all the usual checks and locks (AssignmentService), then links it to the original.
    private function place(Booking $booking, Cleaner $cleaner, BookingCleaner $original, ?User $by): BookingCleaner
    {
        $row = $this->assignments->assign($booking->id, $cleaner->id, $by, $original->role);

        $row->update([
            'replaces_id' => $original->id,
            'replacement_reason' => $original->replacement_reason,
            // a subscription's standing crew needs no per-visit acceptance; a one-off stand-in must accept
            'status' => $booking->assignment_mode === 'roster' ? 'assigned' : $row->status,
        ]);

        return $row;
    }

    // Free that day, same zone first, then anyone who has worked for this client before, then best rated
    private function findStandIn(Booking $booking): ?Cleaner
    {
        $already = $booking->assignments()->pluck('cleaner_id')->all();   // anyone with a row on this job is out

        $workedWith = BookingCleaner::where('status', 'completed')
            ->whereIn('booking_id', Booking::where('client_id', $booking->client_id)->select('id'))
            ->pluck('cleaner_id')
            ->unique()
            ->all();

        return $this->availability->availableCleaners($booking->workDay->work_date, $booking->zone_id)
            ->reject(fn (Cleaner $c) => in_array($c->id, $already, true))
            ->sort(fn (Cleaner $a, Cleaner $b) => [! $a->in_zone, ! in_array($a->id, $workedWith, true), -(float) $a->avg_rating, $a->id]
                <=> [! $b->in_zone, ! in_array($b->id, $workedWith, true), -(float) $b->avg_rating, $b->id])
            ->first();
    }

    private function futureAssignments(int $cleanerId): Builder
    {
        return BookingCleaner::where('cleaner_id', $cleanerId)
            ->whereIn('status', ['offered', 'accepted', 'assigned'])
            ->whereHas('booking', fn ($q) => $q->whereIn('status', self::LIVE_BOOKING_STATUSES)->where('start_at', '>', now()));
    }

    private function audit(string $action, Model $entity, ?array $old, ?array $new, ?User $by): void
    {
        AuditLog::create([
            'user_id' => $by?->id,
            'action' => $action,
            'entity_type' => $entity->getMorphClass(),
            'entity_id' => $entity->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip' => request()->ip(),
        ]);
    }

    private function parseDate(mixed $value): Carbon
    {
        if (blank($value)) {
            throw new ReplacementException('Please choose a valid date.');
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw new ReplacementException('Please choose a valid date.');
        }
    }
}