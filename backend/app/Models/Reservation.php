<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'spot_id', 'start_time', 'end_time', 'status', 'completed_at'])]
class Reservation extends Model
{
    use HasFactory;

    public const STATUS_BOOKED = 'Booked';
    public const STATUS_COMPLETED = 'Completed';

    /**
     * Cast reservation timestamps to Carbon for reliable range comparisons.
     * Required because the table stores times as SQL timestamps.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the user who created the reservation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the parking spot that was reserved.
     */
    public function parkingSpot(): BelongsTo
    {
        return $this->belongsTo(ParkingSpot::class, 'spot_id');
    }
}
