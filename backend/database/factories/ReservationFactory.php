<?php

namespace Database\Factories;

use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addHour();

        return [
            'user_id' => User::factory(),
            'spot_id' => ParkingSpot::factory(),
            'start_time' => $start,
            'end_time' => (clone $start)->addHour(),
            'status' => 'Booked',
        ];
    }
}
