<?php

namespace Tests\Unit;

use App\Exceptions\ReservationTimeConflictException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a booked reservation for a specific spot using Jerusalem-local timestamps.
     * The helper keeps the tests readable while ensuring all created reservations are stored in UTC.
     */
    private function createBookedReservation(
        ParkingSpot $spot,
        User $user,
        string $date,
        string $startTime,
        string $endTime,
        string $timezone = ReservationService::SLOT_TIMEZONE,
    ): Reservation
    {
        $localStart = Carbon::parse($date, $timezone)->setTimeFromTimeString($startTime);
        $localEnd = Carbon::parse($date, $timezone)->setTimeFromTimeString($endTime);

        return Reservation::factory()->create([
            'user_id' => $user->id,
            'spot_id' => $spot->id,
            'start_time' => $localStart->copy()->utc()->toDateTimeString(),
            'end_time' => $localEnd->copy()->utc()->toDateTimeString(),
            'status' => Reservation::STATUS_BOOKED,
        ]);
    }

    /**
     * Read the availability record for a single spot from the service response.
     */
    private function getSpotAvailability(array $availability, int $spotId): array
    {
        $spotAvailability = collect($availability)->firstWhere('id', $spotId);

        $this->assertNotNull($spotAvailability);

        return $spotAvailability;
    }

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

    public function test_get_slot_availability_for_date_marks_taken_slots_per_spot(): void
    {
        $spotA = ParkingSpot::factory()->create(['spot_number' => 'A']);
        $spotB = ParkingSpot::factory()->create(['spot_number' => 'B']);
        $user = User::factory()->create();

        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spotA, $user, $date->toDateString(), '12:30', '13:30');

        $service = app(ReservationService::class);
        $availability = $service->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);

        $spotAAvailability = $this->getSpotAvailability($availability, $spotA->id);
        $spotBAvailability = $this->getSpotAvailability($availability, $spotB->id);

        $this->assertFalse($spotAAvailability['slots'][0]['taken']);
        $this->assertTrue($spotAAvailability['slots'][1]['taken']);
        $this->assertFalse($spotAAvailability['slots'][2]['taken']);

        $this->assertFalse($spotBAvailability['slots'][0]['taken']);
        $this->assertFalse($spotBAvailability['slots'][1]['taken']);
        $this->assertFalse($spotBAvailability['slots'][2]['taken']);
    }

    /**
     * Exact slot boundaries should mark only the slot they fully cover.
     */
    public function test_get_slot_availability_marks_exact_slot_boundary_as_taken(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spot, $user, $date->toDateString(), '08:00', '12:00');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability['slots'][0]['taken']);
        $this->assertFalse($spotAvailability['slots'][1]['taken']);
        $this->assertFalse($spotAvailability['slots'][2]['taken']);
    }

    /**
     * Reservations that start inside a slot and end at the slot boundary should still mark the slot.
     */
    public function test_get_slot_availability_marks_partial_overlap_inside_slot(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spot, $user, $date->toDateString(), '09:00', '12:00');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability['slots'][0]['taken']);
        $this->assertFalse($spotAvailability['slots'][1]['taken']);
        $this->assertFalse($spotAvailability['slots'][2]['taken']);
    }

    /**
     * Reservations that begin at the start boundary and end before the slot ends should still mark that slot only.
     */
    public function test_get_slot_availability_marks_partial_overlap_at_slot_start(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spot, $user, $date->toDateString(), '08:00', '11:00');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability['slots'][0]['taken']);
        $this->assertFalse($spotAvailability['slots'][1]['taken']);
        $this->assertFalse($spotAvailability['slots'][2]['taken']);
    }

    /**
     * Reservations crossing a slot boundary should mark both overlapping slots.
     */
    public function test_get_slot_availability_marks_two_slots_when_reservation_crosses_boundary_from_before(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spot, $user, $date->toDateString(), '11:59', '16:00');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability['slots'][0]['taken']);
        $this->assertTrue($spotAvailability['slots'][1]['taken']);
        $this->assertFalse($spotAvailability['slots'][2]['taken']);
    }

    /**
     * Reservations crossing a slot boundary from the end side should mark both overlapping slots.
     */
    public function test_get_slot_availability_marks_two_slots_when_reservation_crosses_boundary_from_after(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spot, $user, $date->toDateString(), '12:00', '16:01');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertFalse($spotAvailability['slots'][0]['taken']);
        $this->assertTrue($spotAvailability['slots'][1]['taken']);
        $this->assertTrue($spotAvailability['slots'][2]['taken']);
    }

    /**
     * A reservation that spans the whole day should mark all slots as taken for that spot only.
     */
    public function test_get_slot_availability_marks_all_slots_for_full_day_overlap(): void
    {
        $spotA = ParkingSpot::factory()->create();
        $spotB = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $this->createBookedReservation($spotA, $user, $date->toDateString(), '08:00', '20:00');

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, ReservationService::SLOT_TIMEZONE);
        $spotAAvailability = $this->getSpotAvailability($availability, $spotA->id);
        $spotBAvailability = $this->getSpotAvailability($availability, $spotB->id);

        $this->assertTrue($spotAAvailability['slots'][0]['taken']);
        $this->assertTrue($spotAAvailability['slots'][1]['taken']);
        $this->assertTrue($spotAAvailability['slots'][2]['taken']);

        $this->assertFalse($spotBAvailability['slots'][0]['taken']);
        $this->assertFalse($spotBAvailability['slots'][1]['taken']);
        $this->assertFalse($spotBAvailability['slots'][2]['taken']);
    }

    /**
     * The same reservation should map to different slots when the local timezone changes.
     */
    public function test_get_slot_availability_uses_provided_timezone(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $timezone = 'America/New_York';
        $date = Carbon::parse('2026-03-31', $timezone);

        $this->createBookedReservation($spot, $user, '2026-03-31', '08:00', '12:00', $timezone);

        $newYorkAvailability = app(ReservationService::class)->getSlotAvailabilityForDate($date, $timezone);
        $jerusalemAvailability = app(ReservationService::class)->getSlotAvailabilityForDate(
            Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE),
            ReservationService::SLOT_TIMEZONE,
        );

        $newYorkSpotAvailability = $this->getSpotAvailability($newYorkAvailability, $spot->id);
        $jerusalemSpotAvailability = $this->getSpotAvailability($jerusalemAvailability, $spot->id);

        $this->assertTrue($newYorkSpotAvailability['slots'][0]['taken']);
        $this->assertFalse($newYorkSpotAvailability['slots'][1]['taken']);
        $this->assertFalse($newYorkSpotAvailability['slots'][2]['taken']);

        $this->assertFalse($jerusalemSpotAvailability['slots'][0]['taken']);
        $this->assertTrue($jerusalemSpotAvailability['slots'][1]['taken']);
        $this->assertTrue($jerusalemSpotAvailability['slots'][2]['taken']);
    }
}
