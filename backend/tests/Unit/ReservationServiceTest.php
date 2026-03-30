<?php

namespace Tests\Unit;

use App\Exceptions\ReservationTimeConflictException;
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

    /**
     * Verifies that overlapping reservations for the same parking spot are rejected.
     */
    public function test_create_throws_conflict_for_overlapping_reservations_on_same_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $startTime = now()->addDay();
        $endTime = $startTime->copy()->addHours(2);
        $conflictingStartTime = $startTime->copy()->addHour();
        $conflictingEndTime = $endTime->copy()->addHour();

        $service = app(ReservationService::class);
        $service->create($user, $spot->id, $startTime->toISOString(), $endTime->toISOString());

        try {
            $service->create(
                $user,
                $spot->id,
                $conflictingStartTime->toISOString(),
                $conflictingEndTime->toISOString(),
            );

            $this->fail('Expected a ReservationTimeConflictException to be thrown.');
        } catch (ReservationTimeConflictException) {
            $this->assertDatabaseCount('reservations', 1);
        }
    }

    /**
     * Ensures that different parking spots can be reserved for the same time window.
     */
    public function test_create_allows_overlapping_reservations_on_different_spots(): void
    {
        $user = User::factory()->create();
        $firstSpot = ParkingSpot::factory()->create();
        $secondSpot = ParkingSpot::factory()->create();

        $startTime = now()->addDay();
        $endTime = $startTime->copy()->addHours(2);
        $secondStartTime = $startTime->copy()->addHour();
        $secondEndTime = $endTime->copy()->addHour();

        $service = app(ReservationService::class);
        $service->create($user, $firstSpot->id, $startTime->toISOString(), $endTime->toISOString());
        $service->create($user, $secondSpot->id, $secondStartTime->toISOString(), $secondEndTime->toISOString());

        $this->assertDatabaseCount('reservations', 2);
    }

    /**
     * Confirms that back-to-back reservations are allowed because their time ranges only touch at a boundary.
     */
    public function test_create_allows_back_to_back_reservations_on_same_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $startTime = now()->addDay();
        $middleTime = $startTime->copy()->addHours(2);
        $endTime = $middleTime->copy()->addHours(2);

        $service = app(ReservationService::class);
        $service->create($user, $spot->id, $startTime->toISOString(), $middleTime->toISOString());
        $service->create($user, $spot->id, $middleTime->toISOString(), $endTime->toISOString());

        $this->assertDatabaseCount('reservations', 2);
    }

    /**
     * Verifies that completing a booked reservation changes its status to completed.
     */
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

    /**
     * Ensures that calling complete on an already completed reservation remains a no-op.
     */
    public function test_complete_keeps_completed_reservation_completed(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_COMPLETED]);

        $service = app(ReservationService::class);
        $service->complete($reservation->id);

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);
    }

    /**
     * Confirms that completing a missing reservation is a no-op.
     */
    public function test_complete_does_nothing_when_reservation_does_not_exist(): void
    {
        $service = app(ReservationService::class);
        $service->complete(PHP_INT_MAX);

        $this->assertDatabaseCount('reservations', 0);
    }
}
