<?php

namespace Tests\Unit;

use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_reservation_for_user_and_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $service = app(ReservationService::class);
        $reservation = $service->create($user, $spot->id, now()->addHour()->toISOString(), now()->addHours(2)->toISOString());

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'user_id' => $user->id,
            'spot_id' => $spot->id,
            'status' => Reservation::STATUS_BOOKED,
        ]);
    }

    public function test_complete_marks_reservation_completed(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_BOOKED]);

        $service = app(ReservationService::class);
        $service->complete($reservation->id);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);
    }
}
