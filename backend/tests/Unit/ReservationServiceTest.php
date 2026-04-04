<?php

namespace Tests\Unit;

use App\Events\ParkingSlotStatusChanged;
use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use App\Services\SlotService;
use App\ValueObjects\SpotSlotAvailability;
use DateTimeZone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Return the slot time definition for a specific index.
     * This exists so tests remain stable while not coupling to internal service constants.
     */
    private function slotTimeDefinition(int $slotIndex): \App\ValueObjects\SlotTimeDefinition
    {
        $definitions = app(SlotService::class)->getSlotTimeDefinitions();

        return $definitions[$slotIndex];
    }

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
    private function freezeNowBeforeLocalDate(string $date, ?string $timezone = null): void
    {
        $timezone ??= app(SlotService::class)->getSlotTimezone();
        $nowUtc = Carbon::parse($date, $timezone)->startOfDay()->subHour()->utc();
        Carbon::setTestNow($nowUtc);
    }

    /**
     * Build a local datetime for a slot boundary based on the configured slot time definitions.
     * This keeps tests resilient if slot times change.
     */
    private function localSlotBoundary(string $date, int $slotIndex, string $boundary, ?string $timezone = null): Carbon
    {
        $timezone ??= app(SlotService::class)->getSlotTimezone();
        $slot = $this->slotTimeDefinition($slotIndex);

        return Carbon::parse($date, $timezone)
            ->startOfDay()
            ->setTimeFromTimeString($boundary === 'start' ? $slot->start : $slot->end);
    }

    /**
     * Create a time inside a slot by applying an offset to the slot start.
     */
    private function localTimeInsideSlot(string $date, int $slotIndex, int $minutesFromStart, ?string $timezone = null): Carbon
    {
        $timezone ??= app(SlotService::class)->getSlotTimezone();
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
        ?string $timezone = null,
    ): Reservation
    {
        $timezone ??= app(SlotService::class)->getSlotTimezone();
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

        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
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

        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
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

        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
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

        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
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

        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
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
     * Ensures slot availability stays taken when another booked reservation still overlaps the same slot window.
     * This exists because completing one reservation should not free a slot that remains occupied by another booking.
     */
    public function test_get_spot_slot_availability_for_completed_reservation_keeps_slot_taken_when_another_booking_still_overlaps_slot(): void
    {
        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();
        $date = '2026-04-02';

        $this->freezeNowBeforeLocalDate($date);

        $completedReservation = $this->createBookedReservation($spot, $user, $date, '08:00', '10:00');
        $overlappingReservation = $this->createBookedReservation($spot, $user, $date, '10:00', '12:00');

        $completedReservation->status = Reservation::STATUS_COMPLETED;
        $completedReservation->completed_at = Carbon::now('UTC')->toDateTimeString();
        $completedReservation->save();
        $completedReservation->refresh();

        $service = app(ReservationService::class);
        $availability = $service->getSpotSlotAvailabilityForReservation($completedReservation, $date);

        $spotAvailability = $this->getSpotAvailability($availability, $spot->id);

        $this->assertCount(1, $spotAvailability->slots);
        $this->assertSame('08:00 - 12:00', $spotAvailability->slots[0]->key);
        $this->assertTrue($spotAvailability->slots[0]->taken);

        $overlappingReservation->refresh();
        $this->assertSame(Reservation::STATUS_BOOKED, $overlappingReservation->status);
    }

    /**
     * Ensures completion broadcasts keep a slot marked taken when another booked reservation still occupies that slot.
     * This protects realtime clients from clearing a cell just because one of multiple slot-sharing reservations completed.
     */
    public function test_complete_broadcast_keeps_slot_taken_when_another_booking_still_overlaps_slot(): void
    {
        Event::fake([ParkingSlotStatusChanged::class]);

        $user = User::factory()->create();
        $spot = ParkingSpot::factory()->create();
        $date = '2026-04-02';

        $this->freezeNowBeforeLocalDate($date);

        $reservationToComplete = $this->createBookedReservation($spot, $user, $date, '08:00', '10:00');
        $this->createBookedReservation($spot, $user, $date, '10:00', '12:00');

        $service = app(ReservationService::class);
        $service->complete($reservationToComplete->id);

        $reservationToComplete->refresh();
        $this->assertSame(Reservation::STATUS_COMPLETED, $reservationToComplete->status);

        Event::assertDispatched(ParkingSlotStatusChanged::class, function (ParkingSlotStatusChanged $event) use ($date, $spot): bool {
            return $event->date === $date
                && $event->spotId === $spot->id
                && $event->slotKey === '08:00 - 12:00'
                && $event->taken === true;
        });
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
     * Does nothing and returns an empty collection when no reservations are stale.
     */
    public function test_complete_stale_reservations_batch_returns_empty_collection_when_nothing_to_complete(): void
    {
        $service = app(ReservationService::class);

        $nowUtc = Carbon::now('UTC');
        $result = $service->completeStaleReservationsBatch($nowUtc, 10);

        $this->assertCount(0, $result);
        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * Completes only reservations that ended before the given reference time when under the batch size.
     */
    public function test_complete_stale_reservations_batch_completes_only_stale_reservations_within_batch_size(): void
    {
        $service = app(ReservationService::class);

        $nowUtc = Carbon::now('UTC');
        Carbon::setTestNow($nowUtc);

        $freshReservation = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => $nowUtc->copy()->subHour()->toDateTimeString(),
            'end_time' => $nowUtc->copy()->addHour()->toDateTimeString(),
        ]);

        $staleReservation = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => $nowUtc->copy()->subHours(3)->toDateTimeString(),
            'end_time' => $nowUtc->copy()->subHour()->toDateTimeString(),
        ]);

        $result = $service->completeStaleReservationsBatch($nowUtc, 10);

        $this->assertCount(1, $result);
        $this->assertTrue($result->contains('id', $staleReservation->id));

        $staleReservation->refresh();
        $freshReservation->refresh();

        $this->assertSame(Reservation::STATUS_COMPLETED, $staleReservation->status);
        $this->assertSame(Reservation::STATUS_BOOKED, $freshReservation->status);

        $this->assertInstanceOf(Carbon::class, $staleReservation->completed_at);

        $completedAtLowerBound = $nowUtc->copy()->subSeconds(2);
        $completedAtUpperBound = $nowUtc->copy()->addSeconds(2);

        $this->assertTrue(
            $staleReservation->completed_at->betweenIncluded($completedAtLowerBound, $completedAtUpperBound),
            'Expected completed_at to be set to approximately now when completing stale reservations batch.'
        );

        Carbon::setTestNow();
    }

    /**
     * Limits the number of completed reservations per batch according to the provided batch size.
     */
    public function test_complete_stale_reservations_batch_respects_batch_size_limit(): void
    {
        $service = app(ReservationService::class);

        $nowUtc = Carbon::now('UTC');
        Carbon::setTestNow($nowUtc);

        $firstStale = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => $nowUtc->copy()->subHours(4)->toDateTimeString(),
            'end_time' => $nowUtc->copy()->subHours(3)->toDateTimeString(),
        ]);

        $secondStale = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => $nowUtc->copy()->subHours(3)->toDateTimeString(),
            'end_time' => $nowUtc->copy()->subHours(2)->toDateTimeString(),
        ]);

        $result = $service->completeStaleReservationsBatch($nowUtc, 1);

        $this->assertCount(1, $result);

        $firstStale->refresh();
        $secondStale->refresh();

        $this->assertSame(Reservation::STATUS_COMPLETED, $firstStale->status);
        $this->assertSame(Reservation::STATUS_BOOKED, $secondStale->status);

        Carbon::setTestNow();
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
        // using the configured slot definitions so the test stays aligned with business hours.
        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $firstSlot = $this->slotTimeDefinition(0);
        $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot->start, $timezone);

        $startUtc = $slotStartLocal->copy()->subMinute()->utc();
        $endUtc = $slotStartLocal->copy()->addMinutes(30)->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                $this->slotTimeDefinition(0)->start,
                app(SlotService::class)->getSlotTimeDefinitions()[array_key_last(app(SlotService::class)->getSlotTimeDefinitions())]->end,
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
        // using the configured slot definitions so the test follows business hours.
        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $slotTimeDefinitions = app(SlotService::class)->getSlotTimeDefinitions();
        $lastSlotIndex = array_key_last($slotTimeDefinitions);
        $lastSlot = $slotTimeDefinitions[$lastSlotIndex];

        $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot->start, $timezone);
        $slotEndLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot->end, $timezone);

        // Start at the beginning of the last allowed slot, end one minute after it.
        $startUtc = $slotStartLocal->copy()->utc();
        $endUtc = $slotEndLocal->copy()->addMinute()->utc();

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                $this->slotTimeDefinition(0)->start,
                app(SlotService::class)->getSlotTimeDefinitions()[array_key_last(app(SlotService::class)->getSlotTimeDefinitions())]->end,
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
        // ensuring cross-midnight ranges are rejected according to configured slot definitions.
        $timezone = new DateTimeZone(app(SlotService::class)->getSlotTimezone());
        $localDate = Carbon::parse('2026-03-31', $timezone);

        $slotTimeDefinitions = app(SlotService::class)->getSlotTimeDefinitions();
        $firstSlot = $slotTimeDefinitions[0];
        $lastSlotIndex = array_key_last($slotTimeDefinitions);
        $lastSlot = $slotTimeDefinitions[$lastSlotIndex];

        $firstSlotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot->start, $timezone);
        $lastSlotEndLocal = Carbon::parse($localDate->toDateString().' '.$lastSlot->end, $timezone)->addMinutes(30);

        $localStart = $lastSlotEndLocal->copy()->subHours(11.5);
        $localEnd = $lastSlotEndLocal;

        $service = app(ReservationService::class);

        $this->expectException(ReservationTimeOutOfRangeException::class);
        $this->expectExceptionMessage(
            sprintf(
                'Reservation can only be in the following time range %s-%s',
                $this->slotTimeDefinition(0)->start,
                app(SlotService::class)->getSlotTimeDefinitions()[array_key_last(app(SlotService::class)->getSlotTimeDefinitions())]->end,
            )
        );

        $service->createReservation($user, $spot->id, $localStart->copy()->utc(), $localEnd->copy()->utc());
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
        $timezone = app(SlotService::class)->getSlotTimezone();
        $nowLocal = Carbon::parse($nowLocalDate.' '.$this->slotTimeDefinition(0)->start, $timezone);
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

}
