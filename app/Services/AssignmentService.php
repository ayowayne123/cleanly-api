<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Models\Booking;
use App\Models\BookingCleaner;
use App\Models\Cleaner;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    private const ASSIGNABLE_BOOKING_STATUSES = ['awaiting_assignment', 'assigned', 'confirmed'];

    private const MESSAGES = [
        'inactive' => 'That cleaner is not active.',
        'closed_day' => 'That date is not an open work day.',
        'off_day' => 'That cleaner is off on that day of the week.',
        'time_off' => 'That cleaner is on approved leave that day.',
        'booked' => 'That cleaner already has a job that day.',
    ];

    public function __construct(private AvailabilityService $availability)
    {
    }

    /**
     * Put a cleaner on a booking. Everything is checked again inside a lock, so two admins
     * clicking at the same moment can't double-book a cleaner or overfill a crew.
     * The unique (cleaner_id, active_work_date) index is the last line of defence.
     *
     * @throws AssignmentException
     */
    public function assign(Booking|int $booking, Cleaner|int $cleaner, ?User $by = null, string $role = 'member'): BookingCleaner
    {
        $bookingId = $booking instanceof Booking ? $booking->id : $booking;
        $cleanerId = $cleaner instanceof Cleaner ? $cleaner->id : $cleaner;

        try {
            return DB::transaction(function () use ($bookingId, $cleanerId, $by, $role) {
                // Always lock the booking first, then the cleaner, so two admins can never deadlock
                $booking = Booking::with('workDay')->whereKey($bookingId)->lockForUpdate()->firstOrFail();
                $cleaner = Cleaner::whereKey($cleanerId)->lockForUpdate()->firstOrFail();

                if (! in_array($booking->status, self::ASSIGNABLE_BOOKING_STATUSES, true)) {
                    throw new AssignmentException(
                        'This booking cannot be assigned while it is '.str_replace('_', ' ', $booking->status).'.'
                    );
                }

                if ($booking->activeAssignments()->count() >= $booking->cleaners_required) {
                    throw new AssignmentException('This booking already has its full crew.');
                }

                // One row per cleaner per booking (unique index), so a cleaner who declined earlier is re-offered
                $existing = BookingCleaner::where('booking_id', $booking->id)
                    ->where('cleaner_id', $cleaner->id)
                    ->first();

                if ($existing && in_array($existing->status, BookingCleaner::ACTIVE_STATUSES, true)) {
                    throw new AssignmentException('That cleaner is already on this booking.');
                }

                $reason = $this->availability->unavailabilityReason($cleaner, $booking->workDay->work_date);

                if ($reason !== null) {
                    throw new AssignmentException(self::MESSAGES[$reason]);
                }

                $attributes = [
                    'role' => $role,
                    'status' => 'offered',
                    'assigned_by_user_id' => $by?->id,
                    'replaces_id' => null,
                    'replacement_reason' => null,
                    'responded_at' => null,
                ];

                $assignment = $existing
                    ? tap($existing)->update($attributes)
                    : $booking->assignments()->create(['cleaner_id' => $cleaner->id] + $attributes);

                if ($booking->status === 'awaiting_assignment' && $booking->isFullyStaffed()) {
                    $booking->changeStatus('assigned', $by, 'Full crew assigned');
                }

                return $assignment;
            });
        } catch (UniqueConstraintViolationException) {
            // only reachable if the checks above were somehow beaten; the database refused the double booking
            throw new AssignmentException(self::MESSAGES['booked']);
        }
    }

    /** The cleaner accepts an offered job. When the whole crew has accepted, the booking is confirmed. */
    public function accept(BookingCleaner|int $assignment, ?User $by = null): BookingCleaner
    {
        return DB::transaction(function () use ($assignment, $by) {
            [$booking, $assignment] = $this->lockAssignment($assignment);

            if ($assignment->status !== 'offered') {
                throw new AssignmentException('Only an offered job can be accepted.');
            }

            $assignment->update(['status' => 'accepted', 'responded_at' => now()]);

            $crewComplete = $booking->isFullyStaffed()
                && ! $booking->activeAssignments()->whereIn('status', ['offered', 'assigned'])->exists();

            if ($booking->status === 'assigned' && $crewComplete) {
                $booking->changeStatus('confirmed', $by, 'All cleaners accepted');
            }

            return $assignment;
        });
    }

    /** The cleaner declines an offered job. Their day is freed and the booking goes back to the admin queue. */
    public function decline(BookingCleaner|int $assignment, ?User $by = null): BookingCleaner
    {
        return DB::transaction(function () use ($assignment, $by) {
            [$booking, $assignment] = $this->lockAssignment($assignment);

            if ($assignment->status !== 'offered') {
                throw new AssignmentException('Only an offered job can be declined.');
            }

            $assignment->update(['status' => 'declined', 'responded_at' => now()]);

            $this->backToQueue($booking, $by, 'A cleaner declined');

            return $assignment;
        });
    }

    /** Admin takes a cleaner off a booking. Their day is freed and the booking goes back to the admin queue. */
    public function unassign(BookingCleaner|int $assignment, ?User $by = null): BookingCleaner
    {
        return DB::transaction(function () use ($assignment, $by) {
            [$booking, $assignment] = $this->lockAssignment($assignment);

            if ($assignment->status === 'completed') {
                throw new AssignmentException('A completed job cannot be unassigned.');
            }

            if (! in_array($assignment->status, ['offered', 'accepted', 'assigned'], true)) {
                throw new AssignmentException('That cleaner is not on this booking.');
            }

            $assignment->update(['status' => 'removed', 'replacement_reason' => 'admin_change']);

            $this->backToQueue($booking, $by, 'A cleaner was removed');

            return $assignment;
        });
    }

    private function backToQueue(Booking $booking, ?User $by, string $note): void
    {
        if (in_array($booking->status, ['assigned', 'confirmed'], true)) {
            $booking->changeStatus('awaiting_assignment', $by, $note);
        }
    }

    // same lock order as assign(): booking first, then the assignment row
    private function lockAssignment(BookingCleaner|int $assignment): array
    {
        $id = $assignment instanceof BookingCleaner ? $assignment->id : $assignment;

        $bookingId = BookingCleaner::whereKey($id)->value('booking_id');

        $booking = Booking::whereKey($bookingId)->lockForUpdate()->firstOrFail();
        $assignment = BookingCleaner::whereKey($id)->lockForUpdate()->firstOrFail();

        return [$booking, $assignment];
    }
}