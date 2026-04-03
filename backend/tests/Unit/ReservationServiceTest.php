<?php

namespace Tests\Unit;

use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use App\Events\ParkingSlotStatusChanged;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reset Carbon's test clock after each test.
     * This exists to ensure one test's frozen time does not leak into another.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Freeze "now" to a moment before the provided local date.
     * This exists so tests using hard-coded dates remain stable even as real time passes.
     */
    private function freezeNowBeforeLocalDate(string $date, string $timezone = ReservationService::getSlotTimezone()): void
    {
        $nowUtc = Carbon::parse($date, $timezone)->startOfDay()->subHour()->utc();
        Carbon::setTestNow($nowUtc);
    }

    /**
     * Build a local datetime for a slot boundary based on ReservationService::SLOT_DEFINITIONS.
     * This keeps tests resilient if slot times change.
     */
    private function localSlotBoundary(string $date, int $slotIndex, string $boundary, string $timezone = ReservationService::getSlotTimezone()): Carbon
    {
        $slot = ReservationService::SLOT_DEFINITIONS[$slotIndex];

        return Carbon::parse($date, $timezone)
            ->startOfDay()
            ->setTimeFromTimeString($slot[$boundary]);
    }

    /**
     * Create a time inside a slot by applying an offset to the slot start.
     */
    private function localTimeInsideSlot(string $date, int $slotIndex, int $minutesFromStart, string $timezone = ReservationService::getSlotTimezone()): Carbon
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
        string $timezone = ReservationService::getSlotTimezone(),
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

    /**
     * Creates a reservation and verifies it is persisted for the given user and spot.
     */
    public function test_create_persists_reservation_for_user_and_spot(): void
    {
        // Create a user + a parking spot that will be referenced by the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = Carbon::now($timezone)->addDay()->toDateString();
        $startUtc = $this->localTimeInsideSlot($date, 0, 60)->utc();
        $endUtc = $this->localTimeInsideSlot($date, 0, 120)->utc();

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

    /**
     * Ensures a start time in the past is clamped to "now" so reservations never begin before the server time.
     */
    public function test_create_clamps_start_time_to_now_when_start_is_in_the_past(): void
    {
        // Create a user + spot used for the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
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
        // Create a single user + spot; we will attempt to reserve overlapping ranges on the same spot.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = Carbon::now($timezone)->addDay()->toDateString();
        $startTime = $this->localTimeInsideSlot($date, 0, 60);
        $endTime = $this->localTimeInsideSlot($date, 0, 180);
        $conflictingStartTime = $this->localTimeInsideSlot($date, 0, 120);
        $conflictingEndTime = $this->localTimeInsideSlot($date, 0, 240);

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
        // Create one user and two spots to confirm overlaps are only rejected per-spot.
        $user = User::factory()->create();
        $firstSpot = ParkingSpot::factory()->create();
        $secondSpot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = Carbon::now($timezone)->addDay()->toDateString();
        $startTime = $this->localTimeInsideSlot($date, 0, 60);
        $endTime = $this->localTimeInsideSlot($date, 0, 180);
        $secondStartTime = $this->localTimeInsideSlot($date, 0, 120);
        $secondEndTime = $this->localTimeInsideSlot($date, 0, 240);

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
        // Create one user + spot; create 2 reservations that touch at the boundary.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = Carbon::now($timezone)->addDay()->toDateString();
        $startTime = $this->localTimeInsideSlot($date, 0, 60);
        $middleTime = $this->localTimeInsideSlot($date, 0, 180);
        $endTime = $this->localTimeInsideSlot($date, 0, 300);

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
        // Create a booked reservation that can be completed.
        $reservation = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => Carbon::now('UTC')->subHours(2)->toDateTimeString(),
            'end_time' => Carbon::now('UTC')->addHours(2)->toDateTimeString(),
        ]);

        $originalEndTime = $reservation->end_time;

        $completedAtLowerBound = Carbon::now('UTC')->subSeconds(2);

        $service = app(ReservationService::class);
        $service->complete($reservation->id);

        $completedAtUpperBound = Carbon::now('UTC')->addSeconds(2);

        $reservation->refresh();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);

        $this->assertInstanceOf(Carbon::class, $reservation->completed_at);
        $this->assertTrue(
            $reservation->completed_at->betweenIncluded($completedAtLowerBound, $completedAtUpperBound),
            'Expected completed_at to be set to approximately now when completing the reservation.'
        );

        $this->assertTrue(
            $reservation->end_time->equalTo($originalEndTime),
            'Expected end_time to remain unchanged when completing a reservation.'
        );
    }

    /**
     * Ensures that calling complete on an already completed reservation remains a no-op.
     */
    public function test_complete_keeps_completed_reservation_completed(): void
    {
        // Create an already-completed reservation.
        $reservation = Reservation::factory()->create([
            'status' => Reservation::STATUS_COMPLETED,
            'start_time' => Carbon::now('UTC')->subDays(2)->toDateTimeString(),
            'end_time' => Carbon::now('UTC')->subDay()->toDateTimeString(),
            'completed_at' => Carbon::now('UTC')->subDay()->toDateTimeString(),
        ]);

        $originalEndTime = $reservation->end_time;
        $originalCompletedAt = $reservation->completed_at;

        $service = app(ReservationService::class);
        $service->complete($reservation->id);

        $reservation->refresh();

        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);

        $this->assertTrue(
            $reservation->end_time->equalTo($originalEndTime),
            'Expected end_time to remain unchanged when completing an already completed reservation.'
        );

        $this->assertTrue(
            $reservation->completed_at->equalTo($originalCompletedAt),
            'Expected completed_at to remain unchanged when completing an already completed reservation.'
        );
    }

    /**
     * Confirms that completing a missing reservation throws.
     */
    public function test_complete_does_nothing_when_reservation_does_not_exist(): void
    {
        $service = app(ReservationService::class);

        $this->expectException(ModelNotFoundException::class);
        $service->complete(PHP_INT_MAX);
    }

    /**
     * Rejects reservations that start before the daily allowed slot window (local-time business rule).
     */
    public function test_create_reservation_throws_out_of_range_when_start_before_allowed_window_in_local_timezone(): void
    {
        // Create the user + spot used for the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Build a reservation that begins one minute before the first allowed slot and ends shortly after it begins,
        // using SLOT_DEFINITIONS so the test stays aligned with configured business hours.
        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $firstSlot = ReservationService::SLOT_DEFINITIONS[0];
        $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot['start'], $timezone);

        $startUtc = $slotStartLocal->copy()->subMinute()->utc();
        $endUtc = $slotStartLocal->copy()->addMinutes(30)->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                ReservationService::SLOT_DEFINITIONS[0]['start'],
                ReservationService::SLOT_DEFINITIONS[array_key_last(ReservationService::SLOT_DEFINITIONS)]['end'],
            )
        );

        $service->createReservation($user, $spot->id, $startUtc, $endUtc);
    }

    /**
     * Rejects reservations that end after the daily allowed slot window (local-time business rule).
     */
    public function test_create_reservation_throws_out_of_range_when_end_after_allowed_window_in_local_timezone(): void
    {
        // Create the user + spot used for the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Build a reservation whose local end time extends one minute beyond the last allowed slot,
        // using SLOT_DEFINITIONS so the test follows configured business hours.
        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $lastSlotIndex = array_key_last(ReservationService::SLOT_DEFINITIONS);
        $lastSlot = ReservationService::SLOT_DEFINITIONS[$lastSlotIndex];

        $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot['start'], $timezone);
        $slotEndLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot['end'], $timezone);

        // Start at the beginning of the last allowed slot, end one minute after it.
        $startUtc = $slotStartLocal->copy()->utc();
        $endUtc = $slotEndLocal->copy()->addMinute()->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                ReservationService::SLOT_DEFINITIONS[0]['start'],
                ReservationService::SLOT_DEFINITIONS[array_key_last(ReservationService::SLOT_DEFINITIONS)]['end'],
            )
        );

        $service->createReservation($user, $spot->id, $startUtc, $endUtc);
    }

    /**
     * Rejects reservations that cross local midnight to keep slot boundaries unambiguous.
     */
    public function test_create_reservation_throws_out_of_range_when_crossing_local_midnight(): void
    {
        // Create the user + spot used for the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a reservation that starts late on one day and ends after the allowed window on the next day,
        // ensuring cross-midnight ranges are rejected according to SLOT_DEFINITIONS.
        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $firstSlot = ReservationService::SLOT_DEFINITIONS[0];
        $lastSlotIndex = array_key_last(ReservationService::SLOT_DEFINITIONS);
        $lastSlot = ReservationService::SLOT_DEFINITIONS[$lastSlotIndex];

        $firstSlotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot['start'], $timezone);
        $lastSlotEndLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot['end'], $timezone)->addMinutes(30);

        $localStart = $lastSlotEndLocal->copy()->subHours(11.5);
        $localEnd = $lastSlotEndLocal;

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                ReservationService::SLOT_DEFINITIONS[0]['start'],
                ReservationService::SLOT_DEFINITIONS[array_key_last(ReservationService::SLOT_DEFINITIONS)]['end'],
            )
        );

        $service->createReservation($user, $spot->id, $localStart->copy()->utc(), $localEnd->copy()->utc());
    }

    /**
     * Returns a full daily snapshot and marks only the spot/slot combinations that overlap booked reservations.
     */
    public function test_get_slot_availability_for_date_marks_taken_slots_per_spot(): void
    {
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create two parking spots so we can verify taken slots are per-spot.
        $spotA = ParkingSpot::factory()->create(['spot_number' => 'A']);
        $spotB = ParkingSpot::factory()->create(['spot_number' => 'B']);
        // Create a user that owns the reservation.
        $user = User::factory()->create();

        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        // Mark a reservation inside the second slot only and assert that availability reflects
        // one taken slot for the reserved spot and no taken slots for the other spot.
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
        $availability = $service->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));

        $spotAAvailability = $this->getSpotAvailability($availability, $spotA->id);
        $spotBAvailability = $this->getSpotAvailability($availability, $spotB->id);

        $this->assertIsString($spotAAvailability->slots[0]->key);
        $this->assertSame(
            ReservationService::SLOT_DEFINITIONS[0]['start'].' - '.ReservationService::SLOT_DEFINITIONS[0]['end'],
            $spotAAvailability->slots[0]->key
        );
        $this->assertIsString($spotAAvailability->slots[0]->startUtc);
        $this->assertIsString($spotAAvailability->slots[0]->endUtc);

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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a spot + user and book exactly one slot boundary.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a spot + user and book a reservation starting inside the slot.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localTimeInsideSlot($date->toDateString(), 0, 60);
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a spot + user and book a reservation ending before the slot ends.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 0, 'end')->copy()->subMinutes(60);
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a spot + user and book a reservation spanning the boundary between slot 0 and 1.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'end')->copy()->subMinute();
        $end = $this->localSlotBoundary($date->toDateString(), 1, 'end');
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create a spot + user and book a reservation that starts in slot 1 and ends after it.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localSlotBoundary($date->toDateString(), 1, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 1, 'end')->copy()->addMinute();
        $this->createBookedReservation($spot, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31');

        // Create two spots so we can verify one is fully taken and the other remains available.
        $spotA = ParkingSpot::factory()->create();
        $spotB = ParkingSpot::factory()->create();
        // Create a user that owns the reservation spanning the whole day.
        $user = User::factory()->create();
        $date = Carbon::parse('2026-03-31', ReservationService::getSlotTimezone());

        $start = $this->localSlotBoundary($date->toDateString(), 0, 'start');
        $end = $this->localSlotBoundary($date->toDateString(), 2, 'end');
        $this->createBookedReservation($spotA, $user, $date->toDateString(), $start->format('H:i'), $end->format('H:i'));

        $availability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone(ReservationService::getSlotTimezone()));
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
        $this->freezeNowBeforeLocalDate('2026-03-31', 'America/New_York');

        // Create a single spot + user and reserve the first slot in New York local time.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();
        $timezone = 'America/New_York';
        $date = Carbon::parse('2026-03-31', $timezone);

        $start = $this->localSlotBoundary('2026-03-31', 0, 'start', $timezone);
        $end = $this->localSlotBoundary('2026-03-31', 0, 'end', $timezone);
        $this->createBookedReservation($spot, $user, '2026-03-31', $start->format('H:i'), $end->format('H:i'), $timezone);

        $newYorkAvailability = app(ReservationService::class)->getSlotAvailabilityForDate($date, new DateTimeZone($timezone));
        $jerusalemTimezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $jerusalemAvailability = app(ReservationService::class)->getSlotAvailabilityForDate(
            Carbon::parse('2026-03-31', $jerusalemTimezone),
            $jerusalemTimezone,
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

    /**
     * Rejects reservations that have already ended when received by the server.
     */
    public function test_create_reservation_throws_out_of_range_when_end_time_is_in_the_past(): void
    {
        // Create the user + spot used for the reservation.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        // Freeze "now" to the start of the first slot and build a reservation that ended in the past
        // to exercise the "end time cannot be in the past" guard.
        $nowLocalDate = '2026-03-31';
        $timezone = ReservationService::getSlotTimezone();
        $nowLocal = Carbon::parse($nowLocalDate.' '.ReservationService::SLOT_DEFINITIONS[0]['start'], $timezone);
        $nowUtc = $nowLocal->copy()->utc();
        Carbon::setTestNow($nowUtc);

        $startUtc = $nowUtc->copy()->subHours(2);
        $endUtc = $nowUtc->copy()->subHour();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage('Reservation end time cannot be in the past');
        $service->createReservation($user, $spot->id, $startUtc, $endUtc);

        Carbon::setTestNow();
    }

    /**
     * Returns only the slot(s) overlapped by the reservation so the frontend can patch the UI efficiently.
     */
    public function test_get_spot_slot_availability_for_reservation_returns_only_overlapping_slots_for_that_spot(): void
    {
        $this->freezeNowBeforeLocalDate('2026-03-31');

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = '2026-03-31';

        // Create a spot + user and a small reservation range inside slot 0.
        $spot = ParkingSpot::factory()->create();
        $user = User::factory()->create();

        $localStart = $this->localTimeInsideSlot($date, 0, 10);
        $localEnd = $this->localTimeInsideSlot($date, 0, 20);
        $reservation = $this->createBookedReservation($spot, $user, $date, $localStart->format('H:i'), $localEnd->format('H:i'));

        $service = app(ReservationService::class);
        $updates = $service->getSpotSlotAvailabilityForReservation($reservation, $date);

        $this->assertCount(1, $updates);
        $this->assertSame($spot->id, $updates[0]->id);
        $this->assertCount(1, $updates[0]->slots);
        $this->assertSame(
            ReservationService::SLOT_DEFINITIONS[0]['start'].' - '.ReservationService::SLOT_DEFINITIONS[0]['end'],
            $updates[0]->slots[0]->key
        );
        $this->assertTrue($updates[0]->slots[0]->taken);
    }

    /**
     * Broadcasts only the affected slots when creating a reservation.
     */
    public function test_create_reservation_broadcasts_slot_updates_only_for_overlapping_slots(): void
    {
        Event::fake([ParkingSlotStatusChanged::class]);

        $this->freezeNowBeforeLocalDate('2026-03-31');

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = '2026-03-31';

        // Create a user + spot, then reserve inside slot 1 so only one event should be dispatched.
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $startLocal = $this->localTimeInsideSlot($date, 1, 10);
        $endLocal = $this->localTimeInsideSlot($date, 1, 20);

        $service = app(ReservationService::class);
        $service->createReservation(
            $user,
            $spot->id,
            $startLocal->copy()->utc(),
            $endLocal->copy()->utc(),
        );

        Event::assertDispatched(ParkingSlotStatusChanged::class, 1);
        Event::assertDispatched(ParkingSlotStatusChanged::class, function (ParkingSlotStatusChanged $event) use ($spot, $date): bool {
            $expectedKey = ReservationService::SLOT_DEFINITIONS[1]['start'].' - '.ReservationService::SLOT_DEFINITIONS[1]['end'];
            return $event->date === $date && $event->spotId === $spot->id && $event->slotKey === $expectedKey && $event->taken === true;
        });
    }

    /**
     * Completing a reservation broadcasts the affected slot as available.
     */
    public function test_complete_broadcasts_slot_updates_as_available(): void
    {
        Event::fake([ParkingSlotStatusChanged::class]);

        $this->freezeNowBeforeLocalDate('2026-03-31');

        $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
        $date = '2026-03-31';

        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();

        $startLocal = $this->localTimeInsideSlot($date, 0, 10);
        $endLocal = $this->localTimeInsideSlot($date, 0, 20);

        $reservation = $this->createBookedReservation($spot, $user, $date, $startLocal->format('H:i'), $endLocal->format('H:i'));

        $service = app(ReservationService::class);
        $service->complete($reservation->id);

        Event::assertDispatched(ParkingSlotStatusChanged::class, 1);
        Event::assertDispatched(ParkingSlotStatusChanged::class, function (ParkingSlotStatusChanged $event) use ($spot, $date): bool {
            $expectedKey = ReservationService::SLOT_DEFINITIONS[0]['start'].' - '.ReservationService::SLOT_DEFINITIONS[0]['end'];
            return $event->date === $date && $event->spotId === $spot->id && $event->slotKey === $expectedKey && $event->taken === false;
        });
    }
}
