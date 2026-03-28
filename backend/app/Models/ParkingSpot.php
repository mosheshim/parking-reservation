<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['spot_number'])]
class ParkingSpot extends Model
{
    use HasFactory;

    /**
     * Get the reservations made for this parking spot.
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'spot_id');
    }
}
