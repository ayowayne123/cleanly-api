<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingCleaner;
use App\Models\Cleaner;
use App\Models\CleanerTimeOff;
use App\Models\Setting;
use App\Models\WorkDay;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AvailabilityService
{

    // Bookings in these statuses are holding a day for their crew
    private const SLOT_HOLDING_STATUSES = [
        'pending_payment', 'awaiting_assignment', 'assigned', 'confirmed', 'in_progress',
    ];

        /**
     * Why a cleaner can no longer do a job on this date, ignoring their own bookings:
     * inactive, off_day or time_off. Null means nothing stands in their way.
     * Used to find jobs that need a replacement after leave is approved or an off day changes.
     */
    public function standingConflict(Cleaner $cleaner, CarbonInterface|string $date): ?string
    {
        $day = $this->dateString($date);

        if ($cleaner->status !== 'active') {
            return 'inactive';
        }

        if ($cleaner->weekly_off_day !== null && (int) $cleaner->weekly_off_day === Carbon::parse($day)->dayOfWeek) {
            return 'off_day';
        }

        $onLeave = CleanerTimeOff::approved()
            ->where('cleaner_id', $cleaner->id)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->exists();

        return $onLeave ? 'time_off' : null;
    }
    
    /**
     * Why a cleaner can't work on a date, or null if they can (section 3.2).
     * Reasons: inactive, closed_day, off_day, time_off, booked.
     */
    public function unavailabilityReason(Cleaner $cleaner, CarbonInterface|string $date): ?string
    {
        $day = $this->dateString($date);

        if ($cleaner->status !== 'active') {
            return 'inactive';
        }

        if (! $this->isOpenWorkDay($day)) {
            return 'closed_day';
        }

        return $this->unavailableDates(collect([$cleaner]), [$day])[$cleaner->id][$day] ?? null;
    }

    public function isAvailable(Cleaner $cleaner, CarbonInterface|string $date): bool
    {
        return $this->unavailabilityReason($cleaner, $date) === null;
    }

    /**
     * Cleaners an admin can assign on a date. Cleaners who cover the zone come first
     * (zone is only a preference for admin), then the rest, best rated first.
     * Pass $zoneOnly = true to hide cleaners outside the zone.
     *
     * @return Collection<int, Cleaner>
     */
    public function availableCleaners(CarbonInterface|string $date, ?int $zoneId = null, bool $zoneOnly = false): Collection
    {
        $day = $this->dateString($date);

        if (! $this->isOpenWorkDay($day)) {
            return collect();
        }

        $cleaners = Cleaner::active()->with(['user', 'zones'])->get();
        $unavailable = $this->unavailableDates($cleaners, [$day]);

        $free = $cleaners
            ->reject(fn (Cleaner $c) => isset($unavailable[$c->id][$day]))
            ->each(fn (Cleaner $c) => $c->setAttribute(
                'in_zone',
                $zoneId !== null && $c->zones->contains('id', $zoneId),
            ));

        if ($zoneOnly && $zoneId !== null) {
            $free = $free->filter(fn (Cleaner $c) => $c->in_zone);
        }

        return $free
            ->sort(fn (Cleaner $a, Cleaner $b) => [! $a->in_zone, -(float) $a->avg_rating, $a->id]
                <=> [! $b->in_zone, -(float) $b->avg_rating, $b->id])
            ->values();
    }

      /**
     * For the date picker: every open work day in the range, how many cleaners are free,
     * how many crew places are really open, and whether this job fits.
     *
     * open_slots = free cleaners minus crew places still unfilled on bookings already holding
     * that day. Without that, five one-cleaner bookings would all "fit" into two free cleaners.
     * (Zone is only a preference, so every free active cleaner counts.)
     *
     * @return Collection<int, array{date:string, opens_at:string, available_cleaners:int, open_slots:int, bookable:bool}>
     */
    public function bookableDates(CarbonInterface|string $from, CarbonInterface|string $to, int $cleanersRequired): Collection
    {
        $days = WorkDay::open()
            ->whereBetween('work_date', [$this->dateString($from), $this->dateString($to)])
            ->orderBy('work_date')
            ->get();

        if ($days->isEmpty()) {
            return collect();
        }

        $cleaners = Cleaner::active()->get();
        $unavailable = $this->unavailableDates(
            $cleaners,
            $days->map(fn (WorkDay $d) => $d->work_date->toDateString())->all(),
        );

        $unfilled = Booking::whereIn('work_day_id', $days->pluck('id'))
            ->whereIn('status', self::SLOT_HOLDING_STATUSES)
            ->withCount('activeAssignments')
            ->get()
            ->groupBy('work_day_id')
            ->map(fn ($bookings) => $bookings->sum(
                fn (Booking $b) => max(0, $b->cleaners_required - $b->active_assignments_count)
            ));

        return $days->map(function (WorkDay $day) use ($cleaners, $unavailable, $unfilled, $cleanersRequired) {
            $key = $day->work_date->toDateString();
            $free = $cleaners->filter(fn (Cleaner $c) => ! isset($unavailable[$c->id][$key]))->count();
            $open = max(0, $free - (int) ($unfilled[$day->id] ?? 0));

            return [
                'date' => $key,
                'opens_at' => substr((string) $day->opens_at, 0, 5),
                'available_cleaners' => $free,
                'open_slots' => $open,
                'bookable' => $open >= $cleanersRequired,
            ];
        });
    }

    /**
     * The preferred-cleaner list for a subscription (section 3.7).
     * Only cleaners who cover the zone are shown (a hard filter for clients).
     * $dates = every visit date in the first billing period.
     * Visit dates that are not open work days are left out of the count.
     *
     * Green = free for every visit, orange = free for some, red = free for none.
     * Safe to send to clients: first name and photo only, ratings are used to rank and then dropped.
     *
     * @param  array<int, string>  $dates  Y-m-d strings
     * @return Collection<int, array{id:int, first_name:string, photo_url:?string, status:string, selectable:bool, free_visits:int, total_visits:int, conflict_dates:array<int,string>}>
     */
    public function preferredCleanerOptions(int $zoneId, array $dates): Collection
    {
        $open = WorkDay::open()
            ->whereIn('work_date', $dates)
            ->get()
            ->map(fn (WorkDay $d) => $d->work_date->toDateString())
            ->sort()
            ->values()
            ->all();

        $total = count($open);

        if ($total === 0) {
            return collect();
        }

        $cleaners = Cleaner::active()
            ->whereHas('zones', fn ($q) => $q->whereKey($zoneId))
            ->with('user')
            ->get();

        $unavailable = $this->unavailableDates($cleaners, $open);
        $maxConflicts = (int) Setting::get('preferred_cleaner_max_conflicts', 0);

        // upcoming jobs per cleaner, used only as a tie-breaker (lightest workload first)
        $workload = BookingCleaner::active()
            ->whereIn('cleaner_id', $cleaners->pluck('id')->all())
            ->whereDate('work_date', '>=', today())
            ->selectRaw('cleaner_id, count(*) as jobs')
            ->groupBy('cleaner_id')
            ->pluck('jobs', 'cleaner_id');

        $rank = ['green' => 0, 'orange' => 1, 'red' => 2];

        return $cleaners
            ->map(function (Cleaner $cleaner) use ($unavailable, $open, $total, $maxConflicts, $workload) {
                $conflicts = array_keys($unavailable[$cleaner->id] ?? []);
                sort($conflicts);

                $free = $total - count($conflicts);
                $status = $free === $total ? 'green' : ($free === 0 ? 'red' : 'orange');

                return [
                    'id' => $cleaner->id,
                    'first_name' => Str::before($cleaner->user->name, ' '),
                    'photo_url' => $cleaner->photo_path ? Storage::url($cleaner->photo_path) : null,
                    'status' => $status,
                    'selectable' => $free > 0 && count($conflicts) <= $maxConflicts,
                    'free_visits' => $free,
                    'total_visits' => $total,
                    'conflict_dates' => $conflicts,
                    // ordering only; removed below
                    '_rating' => (float) $cleaner->avg_rating,
                    '_workload' => (int) ($workload[$cleaner->id] ?? 0),
                ];
            })
            ->sort(fn (array $a, array $b) => [$rank[$a['status']], -$a['_rating'], $a['_workload'], $a['id']]
                <=> [$rank[$b['status']], -$b['_rating'], $b['_workload'], $b['id']])
            ->map(fn (array $row) => Arr::except($row, ['_rating', '_workload']))
            ->values();
    }

    public function isOpenWorkDay(CarbonInterface|string $date): bool
    {
        return WorkDay::open()->whereDate('work_date', $this->dateString($date))->exists();
    }

    /**
     * The one place the cleaner-level rules live: weekly off day, approved leave, another active job.
     * Loads leave and jobs for all cleaners and dates in two queries, then checks in PHP.
     *
     * @param  Collection<int, Cleaner>  $cleaners
     * @param  array<int, string>  $dates  Y-m-d strings
     * @return array<int, array<string, string>>  cleaner id => [date => reason]
     */
    private function unavailableDates(Collection $cleaners, array $dates): array
    {
        if ($cleaners->isEmpty() || ! $dates) {
            return [];
        }

        $ids = $cleaners->pluck('id')->all();
        $min = min($dates);
        $max = max($dates);

        $leave = CleanerTimeOff::approved()
            ->whereIn('cleaner_id', $ids)
            ->whereDate('start_date', '<=', $max)
            ->whereDate('end_date', '>=', $min)
            ->get()
            ->groupBy('cleaner_id');

        $jobs = BookingCleaner::active()
            ->whereIn('cleaner_id', $ids)
            ->whereBetween('work_date', [$min, $max])
            ->get(['cleaner_id', 'work_date'])
            ->groupBy('cleaner_id')
            ->map(fn ($rows) => $rows->map(fn ($row) => $row->work_date->toDateString())->all());

        // Carbon: 0 = Sunday ... 6 = Saturday, the same as cleaners.weekly_off_day
        $weekdays = [];
        foreach ($dates as $d) {
            $weekdays[$d] = Carbon::parse($d)->dayOfWeek;
        }

        $result = [];

        foreach ($cleaners as $cleaner) {
            foreach ($dates as $d) {
                if ($cleaner->weekly_off_day !== null && (int) $cleaner->weekly_off_day === $weekdays[$d]) {
                    $result[$cleaner->id][$d] = 'off_day';
                    continue;
                }

                $onLeave = ($leave->get($cleaner->id) ?? collect())->contains(
                    fn ($t) => $t->start_date->toDateString() <= $d && $t->end_date->toDateString() >= $d
                );

                if ($onLeave) {
                    $result[$cleaner->id][$d] = 'time_off';
                    continue;
                }

                if (in_array($d, $jobs->get($cleaner->id, []), true)) {
                    $result[$cleaner->id][$d] = 'booked';
                }
            }
        }

        return $result;
    }

    private function dateString(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString();
    }
}