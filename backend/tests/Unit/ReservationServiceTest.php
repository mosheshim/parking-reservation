<?php

namespace Tests\Unit;

use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a local datetime for a slot boundary based on ReservationService::SLOT_DEFINITIONS.
     * This keeps tests resilient if slot times change.
     */
    private function localSlotBoundary(string $date, int $slotIndex, string $boundary, string $timezone = ReservationService::SLOT_TIMEZONE): Carbon
    {
        $slot = ReservationService::SLOT_DEFINITIONS[$slotIndex];

        return Carbon::parse($date, $timezone)
            ->startOfDay()
            ->setTimeFromTimeString($slot[$boundary]);
    }

    /**
     * Create a time inside a slot by applying an offset to the slot start.
     */
    private function localTimeInsideSlot(string $date, int $slotIndex, int $minutesFromStart, string $timezone = ReservationService::SLOT_TIMEZONE): Carbon
    {
        return $this->localSlotBoundary($date, $slotIndex, 'start', $timezone)
            ->copy()
            ->addMinutes($minutesFromStart);
    }

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
    private function getSpotAvailability(array $availability, int $spotId): SpotSlotAvailability
    {
        $spotAvailability = collect($availability)->firstWhere('id', $spotId);

        $this->assertNotNull($spotAvailability);

        return $spotAvailability;
    }

    public function test_create_persists_reservation_for_user_and_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startUtc = $localDate->copy()->setTimeFromTimeString('10:00')->utc();
        $endUtc = $localDate->copy()->setTimeFromTimeString('11:00')->utc();

        $service = app(ReservationService::class);
        $reservation = $service->createReservation(
            $user,
            $spot->id,
            $startUtc,
            $endUtc,
        );

        $this->assertInstanceOf(Reservation::class, $reservation);
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'user_id' => $user->id,
            'spot_id' => $spot->id,
            'status' => Reservation::STATUS_BOOKED,
        ]);
    }

    public function test_create_clamps_start_time_to_now_when_start_is_in_the_past(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localNow = Carbon::parse('2026-03-31 10:00:00', $timezone);
        $nowUtc = $localNow->copy()->utc();
        // Freeze time so the service's "now" comparison and clamping behavior is deterministic.
        Carbon::setTestNow($nowUtc);

        $startUtc = $localNow->copy()->subHour()->utc();
        $endUtc = $localNow->copy()->addHour()->utc();

        $service = app(ReservationService::class);
        $reservation = $service->createReservation($user, $spot->id, $startUtc, $endUtc);

        $reservation->refresh();
        $this->assertSame($nowUtc->toDateTimeString(), $reservation->start_time->copy()->utc()->toDateTimeString());
        $this->assertSame($endUtc->toDateTimeString(), $reservation->end_time->copy()->utc()->toDateTimeString());

        Carbon::setTestNow();
    }

    /**
     * Verifies that overlapping reservations for the same parking spot are rejected.
     */
    public function test_create_throws_conflict_for_overlapping_reservations_on_same_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startTime = $localDate->copy()->setTimeFromTimeString('10:00');
        $endTime = $localDate->copy()->setTimeFromTimeString('12:00');
        $conflictingStartTime = $localDate->copy()->setTimeFromTimeString('11:00');
        $conflictingEndTime = $localDate->copy()->setTimeFromTimeString('13:00');

        $service = app(ReservationService::class);
        $service->createReservation($user, $spot->id, $startTime->copy()->utc(), $endTime->copy()->utc());

        try {
            $service->createReservation(
                $user,
                $spot->id,
                $conflictingStartTime->copy()->utc(),
                $conflictingEndTime->copy()->utc(),
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

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startTime = $localDate->copy()->setTimeFromTimeString('10:00');
        $endTime = $localDate->copy()->setTimeFromTimeString('12:00');
        $secondStartTime = $localDate->copy()->setTimeFromTimeString('11:00');
        $secondEndTime = $localDate->copy()->setTimeFromTimeString('13:00');

        $service = app(ReservationService::class);
        $service->createReservation($user, $firstSpot->id, $startTime->copy()->utc(), $endTime->copy()->utc());
        $service->createReservation($user, $secondSpot->id, $secondStartTime->copy()->utc(), $secondEndTime->copy()->utc());

        $this->assertDatabaseCount('reservations', 2);
    }

    /**
     * Confirms that back-to-back reservations are allowed because their time ranges only touch at a boundary.
     */
    public function test_create_allows_back_to_back_reservations_on_same_spot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startTime = $localDate->copy()->setTimeFromTimeString('10:00');
        $middleTime = $localDate->copy()->setTimeFromTimeString('12:00');
        $endTime = $localDate->copy()->setTimeFromTimeString('14:00');

        $service = app(ReservationService::class);
        $service->createReservation($user, $spot->id, $startTime->copy()->utc(), $middleTime->copy()->utc());
        $service->createReservation($user, $spot->id, $middleTime->copy()->utc(), $endTime->copy()->utc());

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

    public function test_create_reservation_throws_out_of_range_when_start_before_allowed_window_in_local_timezone(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::parse('2026-03-31', $timezone);
        $startUtc = $localDate->copy()->setTimeFromTimeString('07:59')->utc();
        $endUtc = $localDate->copy()->setTimeFromTimeString('08:30')->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage('Reservation can only be in the following time range 08:00-20:00');

        $service->createReservation($user, $spot->id, $startUtc, $endUtc);
    }

    public function test_create_reservation_throws_out_of_range_when_end_after_allowed_window_in_local_timezone(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localDate = Carbon::parse('2026-03-31', $timezone);
        $startUtc = $localDate->copy()->setTimeFromTimeString('19:00')->utc();
        $endUtc = $localDate->copy()->setTimeFromTimeString('20:01')->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage('Reservation can only be in the following time range 08:00-20:00');

        $service->createReservation($user, $spot->id, $startUtc, $endUtc);
    }

    public function test_create_reservation_throws_out_of_range_when_crossing_local_midnight(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::SLOT_TIMEZONE);
        $localStart = Carbon::parse('2026-03-31 19:00:00', $timezone);
        $localEnd = Carbon::parse('2026-04-01 08:30:00', $timezone);

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage('Reservation can only be in the following time range 08:00-20:00');

        $service->createReservation($user, $spot->id, $localStart->copy()->utc(), $localEnd->copy()->utc());
    }

    public function test_get_slot_availability_for_date_marks_taken_slots_per_spot(): void
    {
        $spotA = ParkingSpot::factory()->create(['spot_number' => 'A']);
        $spotB = ParkingSpot::factory()->create(['spot_number' => 'B']);
        $user = User::factory()->create();

        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $localStart = $this->localTimeInsideSlot($date->toDateString(), 1, 30);
        $localEnd = $this->localTimeInsideSlot($date->toDateString(), 1, 90);
        $this->createBookedReservation(
            $spotA,
            $user,
            $date->toDateString(),
            $localStart->format('H:i'),
            $localEnd->format('H:i'),
        );

        $service = app(ReservationService::class);
        $availability = $service->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));

        $spotAAvailability = $this->getSpotAvailability($availability, $spotA->id);
        $spotBAvailability = $this->getSpotAvailability($availability, $spotB->id);

        $this->assertFalse($spotAAvailability->slots[0]->taken);
        $this->assertTrue($spotAAvailability->slots[1]->taken);
        $this->assertFalse($spotAAvailability->slots[2]->taken);

        $this->assertFalse($spotBAvailability->slots[0]->taken);
        $this->assertFalse($spotBAvailability->slots[1]->taken);
        $this->assertFalse($spotBAvailability->slots[2]->taken);
    }

    /**
     * Exact slot boundaries should mark only the slot they fully cover.
     */
    public function test_get_slot_availability_marks_exact_slot_boundary_as_taken(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability->slots[0]->taken);
        $this->assertFalse($spotAvailability->slots[1]->taken);
        $this->assertFalse($spotAvailability->slots[2]->taken);
    }

    /**
     * Reservations that start inside a slot and end at the slot boundary should still mark the slot.
     */
    public function test_get_slot_availability_marks_partial_overlap_inside_slot(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $start = $this->localTimeInsideSlot($date->toDateString(), 0, 60);
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability->slots[0]->taken);
        $this->assertFalse($spotAvailability->slots[1]->taken);
        $this->assertFalse($spotAvailability->slots[2]->taken);
    }

    /**
     * Reservations that begin at the start boundary and end before the slot ends should still mark that slot only.
     */
    public function test_get_slot_availability_marks_partial_overlap_at_slot_start(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end')->copy()->subMinutes(60);
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability->slots[0]->taken);
        $this->assertFalse($spotAvailability->slots[1]->taken);
        $this->assertFalse($spotAvailability->slots[2]->taken);
    }

    /**
     * Reservations crossing a slot boundary should mark both overlapping slots.
     */
    public function test_get_slot_availability_marks_two_slots_when_reservation_crosses_boundary_from_before(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'end')->copy()->subMinute();
        $end = $this->localSlotBoundary($date->toDateString(), 1, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertTrue($spotAvailability->slots[0]->taken);
        $this->assertTrue($spotAvailability->slots[1]->taken);
        $this->assertFalse($spotAvailability->slots[2]->taken);
    }

    /**
     * Reservations crossing a slot boundary from the end side should mark both overlapping slots.
     */
    public function test_get_slot_availability_marks_two_slots_when_reservation_crosses_boundary_from_after(): void
    {
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE);

        $start = $this->localSlotBoundary($date->toDateString(), 1, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 1, 'end')->copy()->addMinute();
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertFalse($spotAvailability->slots[0]->taken);
        $this->assertTrue($spotAvailability->slots[1]->taken);
        $this->assertTrue($spotAvailability->slots[2]->taken);
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

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 2, 'end');
        $this->createBookedReservation($spotA, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::SLOT_TIMEZONE));
        $spotAAvailability = $this->getSpotAvailability($availability, $spotA->id);
        $spotBAvailability = $this->getSpotAvailability($availability, $spotB->id);

        $this->assertTrue($spotAAvailability->slots[0]->taken);
        $this->assertTrue($spotAAvailability->slots[1]->taken);
        $this->assertTrue($spotAAvailability->slots[2]->taken);

        $this->assertFalse($spotBAvailability->slots[0]->taken);
        $this->assertFalse($spotBAvailability->slots[1]->taken);
        $this->assertFalse($spotBAvailability->slots[2]->taken);
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

        $start = $this->localSlotBoundary('2026-03-31', 0, 'start', $timezone);
        $end = $this->localSlotBoundary('2026-03-31', 0, 'end', $timezone);
        $this->createBookedReservation($spot, $user, '2026-03-31', $start->format('H:i'), $end->format('H:i'), $timezone);

        $newYorkAvailability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone($timezone));
        $jerusalemAvailability = app(ReservationService::class)->getSlotAvailabilityForDate(
            Carbon::parse('2026-03-31', ReservationService::SLOT_TIMEZONE),
            new DateTimeZone(ReservationService::SLOT_TIMEZONE),
        );

        $newYorkSpotAvailability = $this->getSpotAvailability($newYorkAvailability, $spot->id);
        $jerusalemSpotAvailability = $this->getSpotAvailability($jerusalemAvailability, $spot->id);

        $this->assertTrue($newYorkSpotAvailability->slots[0]->taken);
        $this->assertFalse($newYorkSpotAvailability->slots[1]->taken);
        $this->assertFalse($newYorkSpotAvailability->slots[2]->taken);

        $this->assertFalse($jerusalemSpotAvailability->slots[0]->taken);
        $this->assertTrue($jerusalemSpotAvailability->slots[1]->taken);
        $this->assertTrue($jerusalemSpotAvailability->slots[2]->taken);
    }
}
