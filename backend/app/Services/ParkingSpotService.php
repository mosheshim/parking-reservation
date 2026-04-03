<?php

namespace App\Services;

use App\Models\ParkingSpot;
use Illuminate\Database\QueryException;

class ParkingSpotService
{
    /**
     * List all parking spots.
     *
     * @return array<int, array{id: int, spot_number: string}>
     * @throws QueryException When the database query fails.
     */
    public function listAll(): array
    {
        return ParkingSpot::query()
            ->orderBy('id')
            ->get(['id', 'spot_number'])
            ->map(static fn (ParkingSpot $spot): array => [
                'id' => $spot->id,
                'spot_number' => $spot->spot_number,
            ])
            ->all();
    }
}
