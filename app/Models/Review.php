<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['booking_id', 'client_id', 'overall_rating', 'comment', 'status'])]
class Review extends Model
{
    protected $attributes = ['status' => 'visible'];

    protected static function booted(): void
    {
        // adding, hiding, un-hiding or deleting a review all change the averages
        static::saved(fn (Review $review) => $review->refreshCleanerRatings());
        static::deleted(fn (Review $review) => $review->refreshCleanerRatings());
    }

    // §3.4: one shared rating per job. A cleaner's average counts only jobs they completed
    // and only visible reviews. A cleaner replaced before the visit is not included.
    public function refreshCleanerRatings(): void
    {
        $cleanerIds = BookingCleaner::where('booking_id', $this->booking_id)
            ->where('status', 'completed')
            ->pluck('cleaner_id');

        foreach ($cleanerIds as $cleanerId) {
            $stats = DB::table('reviews')
                ->join('booking_cleaners', 'booking_cleaners.booking_id', '=', 'reviews.booking_id')
                ->where('booking_cleaners.cleaner_id', $cleanerId)
                ->where('booking_cleaners.status', 'completed')
                ->where('reviews.status', 'visible')
                ->selectRaw('count(*) as total, avg(reviews.overall_rating) as average')
                ->first();

            Cleaner::whereKey($cleanerId)->update([
                'rating_count' => $stats->total,
                'avg_rating' => round((float) ($stats->average ?? 0), 2),
            ]);
        }
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('status', 'visible');
    }
}