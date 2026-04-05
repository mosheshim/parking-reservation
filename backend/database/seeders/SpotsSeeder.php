<?php

namespace Database\Seeders;

use App\Models\ParkingSpot;
use Illuminate\Database\Seeder;

class SpotsSeeder extends Seeder
{
    /**
     * Seed the database with a fixed set of parking spots.
     */
    public function run(): void
    {
        $spots = [
            ['spot_number' => '1'],
            ['spot_number' => '2'],
            ['spot_number' => '3'],
            ['spot_number' => '4'],
            ['spot_number' => '5'],
        ];

        foreach ($spots as $spot) {
            ParkingSpot::updateOrCreate(
                ['spot_number' => $spot['spot_number']],
                $spot,
            );
        }
    }
}
