<?php

namespace Tests\Feature;

use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReservationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create an ID string that will always be larger than PHP_INT_MAX.
     */
    private function tooLargeId(): string
    {
        return (string) PHP_INT_MAX.'0';
    }

    /**
     * Requires authentication when creating a reservation.
     */
    public function test_post_reservations_requires_bearer_token(): void
    {
        $response = $this->postJson('/api/reservations', [
            'spot_id' => 1,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Validates that end time must be after start time.
     */
    public function test_post_reservations_validates_time_range(): void
    {
        $spot = ParkingSpot::factory()->create();

        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => now()->addHours(2)->toISOString(),
            'end_time' => now()->addHour()->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    /**
     * Allows a past start time in the request, but clamps it to server "now".
     */
    public function test_post_reservations_allows_start_time_in_the_past_and_clamps_to_now(): void
    {
        $spot = ParkingSpot::factory()->create();

        $timezone = ReservationService::getSlotTimezone();
        $nowUtc = Carbon::parse('2026-03-31 10:00:00', $timezone)->utc();
        // Freeze time so the validation rule (after:now) and the service clamping logic are deterministic.
        // Otherwise this test can be flaky if time advances between request building and the assertion.
        Carbon::setTestNow($nowUtc);

        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $nowUtc->copy()->subHour()->toISOString(),
            'end_time' => $nowUtc->copy()->addHour()->toISOString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('reservations', [
            'spot_id' => $spot->id,
            'start_time' => $nowUtc->toDateTimeString(),
        ]);

        Carbon::setTestNow();
    }

    /**
     * Rejects requests where the reservation already ended.
     */
    public function test_post_reservations_rejects_end_time_in_the_past(): void
    {
        $spot = ParkingSpot::factory()->create();

        $timezone = ReservationService::getSlotTimezone();
        $nowUtc = Carbon::parse('2026-03-31 10:00:00', $timezone)->utc();
        // Freeze time so "in the past" comparisons are stable and don't depend on test execution speed.
        Carbon::setTestNow($nowUtc);

        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $nowUtc->copy()->subHours(2)->toISOString(),
            'end_time' => $nowUtc->copy()->subHour()->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);

        Carbon::setTestNow();
    }

    /**
     * Validates that spot_id must exist.
     */
    public function test_post_reservations_rejects_non_existing_spot_id(): void
    {
        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => 999999,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    /**
     * Validates that spot_id fits within the integer range.
     */
    public function test_post_reservations_validates_id_range(): void
    {
        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $this->tooLargeId(),
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    /**
     * Creates a reservation for the authenticated user.
     */
    public function test_post_reservations_creates_reservation(): void
    {
        $user = User::factory()->loginable()->create();

        $spot = ParkingSpot::factory()->create();

        $timezone = ReservationService::getSlotTimezone();
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startTimeUtc = $localDate->copy()->setTimeFromTimeString('10:00')->utc();
        $endTimeUtc = $localDate->copy()->setTimeFromTimeString('11:00')->utc();

        $response = $this->withValidJwt($user)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $startTimeUtc->toISOString(),
            'end_time' => $endTimeUtc->toISOString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($user->id, $response->json('user_id'));
        $this->assertSame($spot->id, $response->json('spot_id'));
        $this->assertSame(Reservation::STATUS_BOOKED, $response->json('status'));
    }

    /**
     * Validates the reservation id range when completing.
     */
    public function test_put_complete_validates_id_range(): void
    {
        $tooBig = $this->tooLargeId();

        $response = $this->withValidJwt()
            ->putJson('/api/reservations/'.$tooBig.'/complete');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    /**
     * Marks a reservation as completed.
     */
    public function test_put_complete_marks_reservation_completed(): void
    {
        $reservation = Reservation::factory()->create([
            'status' => Reservation::STATUS_BOOKED,
            'start_time' => Carbon::now('UTC')->subHours(2)->toDateTimeString(),
            'end_time' => Carbon::now('UTC')->addHours(2)->toDateTimeString(),
        ]);

        $originalEndTime = $reservation->end_time;


        $response = $this->withValidJwt()
            ->putJson('/api/reservations/'.$reservation->id.'/complete');

        // Add a small margin of error to account for test execution time.
        $completedAtLowerBound = Carbon::now('UTC')->subSeconds(2);
        $completedAtUpperBound = Carbon::now('UTC')->addSeconds(2);

        $response->assertNoContent();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);

        $reservation->refresh();
        $this->assertTrue(
            $reservation->completed_at->betweenIncluded($completedAtLowerBound, $completedAtUpperBound),
            'Expected completed_at to be set to approximately now when completing the reservation.'
        );

        $this->assertTrue(
            $reservation->end_time->equalTo($originalEndTime),
            'Expected end_time to remain unchanged when completing the reservation.'
        );
    }

    /**
     * Non-numeric route params are rejected by routing.
     */
    public function test_put_complete_with_non_numeric_id_is_rejected_by_routing(): void
    {
        $response = $this->withValidJwt()
            ->putJson('/api/reservations/not-a-number/complete');

        $response->assertStatus(404);
        $this->assertIsArray($response->json());
    }

    /**
     * API 404 responses are JSON to keep the frontend error handling consistent.
     */
    public function test_api_404_is_json_even_without_accept_header(): void
    {
        $response = $this->withValidJwt()
            ->put('/api/reservations/not-a-number/complete');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
        $this->assertIsArray($response->json());
    }

    /**
     * Invalid bearer token is rejected.
     */
    public function test_invalid_token_returns_401(): void
    {
        $spot = ParkingSpot::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer invalid.token.value')->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(401);
    }

    /**
     * Rejects a negative spot id.
     */
    public function test_post_reservations_rejects_negative_spot_id(): void
    {
        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => -1,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    /**
     * Rejects spot_id when it is a non-numeric string.
     */
    public function test_post_reservations_rejects_spot_id_when_string_is_not_numeric(): void
    {
        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => 'not-an-int',
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    /**
     * Rejects spot_id when it is an array.
     */
    public function test_post_reservations_rejects_spot_id_when_array_is_provided(): void
    {
        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => ['1'],
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    /**
     * Rejects invalid date strings for start/end.
     */
    public function test_post_reservations_rejects_invalid_date_strings(): void
    {
        $spot = ParkingSpot::factory()->create();

        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => 'not-a-date',
            'end_time' => 'also-not-a-date',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    /**
     * Rejects dates when arrays are provided.
     */
    public function test_post_reservations_rejects_dates_when_arrays_are_provided(): void
    {
        $spot = ParkingSpot::factory()->create();

        $response = $this->withValidJwt()->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => ['2026-01-01T00:00:00Z'],
            'end_time' => ['2026-01-01T01:00:00Z'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time', 'end_time']);
    }

    /**
     * Returns 409 when a reservation overlaps an existing booking.
     */
    public function test_post_reservations_returns_409_when_time_overlaps_existing_booking(): void
    {
        $user = User::factory()->loginable()->create();
        $spot = ParkingSpot::factory()->create();

        $timezone = ReservationService::getSlotTimezone();
        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $startTime = $localDate->copy()->setTimeFromTimeString('10:00')->utc();
        $endTime = $localDate->copy()->setTimeFromTimeString('12:00')->utc();

        $first = $this->withValidJwt($user)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $startTime->toISOString(),
            'end_time' => $endTime->toISOString(),
        ]);
        $first->assertStatus(201);

        $overlap = $this->withValidJwt($user)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $startTime->copy()->addMinutes(30)->toISOString(),
            'end_time' => $endTime->copy()->addMinutes(30)->toISOString(),
        ]);

        $overlap->assertStatus(409);
        $this->assertIsArray($overlap->json());
        $this->assertSame(1, Reservation::query()->count());
    }

    /**
     * Returns 422 when requested times are outside allowed business hours.
     */
    public function test_post_reservations_returns_422_when_time_is_outside_allowed_window(): void
    {
        $user = User::factory()->loginable()->create();
        $spot = ParkingSpot::factory()->create();

        // Build a reservation that starts just before the first allowed slot and ends shortly after it begins,
        // using SLOT_DEFINITIONS so the test stays in sync with configured business hours.
        $timezone = ReservationService::getSlotTimezone();
        $firstSlot = ReservationService::SLOT_DEFINITIONS[0];

        $localDate = Carbon::now($timezone)->addDay()->startOfDay();
        $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot['start'], $timezone);

        // Start one minute before the first allowed slot, end shortly after it begins.
        $startUtc = $slotStartLocal->copy()->subMinute()->utc();
        $endUtc = $slotStartLocal->copy()->addMinutes(30)->utc();

        $response = $this->withValidJwt($user)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => $startUtc->toISOString(),
            'end_time' => $endUtc->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'message' => sprintf(
                'Reservation can only be in the following time range %s-%s',
                ReservationService::SLOT_DEFINITIONS[0]['start'],
                ReservationService::SLOT_DEFINITIONS[array_key_last(ReservationService::SLOT_DEFINITIONS)]['end'],
            ),
        ]);
        $this->assertDatabaseCount('reservations', 0);
    }

    /**
     * Requires authentication when completing a reservation.
     */
    public function test_put_complete_requires_bearer_token(): void
    {
        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_BOOKED]);

        $response = $this->putJson('/api/reservations/'.$reservation->id.'/complete');

        $response->assertStatus(401);
    }

    /**
     * Negative ids are rejected by routing.
     */
    public function test_put_complete_with_negative_id_is_rejected_by_routing(): void
    {
        $response = $this->withValidJwt()
            ->putJson('/api/reservations/-1/complete');

        $response->assertStatus(404);
        $this->assertIsArray($response->json());
    }

    /**
     * Requires authentication when loading slots availability.
     */
    public function test_get_slots_requires_bearer_token(): void
    {
        $response = $this->getJson('/api/slots?date=2026-04-02');

        $response->assertStatus(401);
    }

    /**
     * Returns a slot availability snapshot for the requested date.
     */
    public function test_get_slots_returns_snapshot_for_date(): void
    {
        $this->mock(ReservationService::class, function ($mock): void {
            $firstSlot = ReservationService::SLOT_DEFINITIONS[0];
            $slotKey = $firstSlot['start'].' - '.$firstSlot['end'];

            // Derive the UTC start/end of the first slot for a fixed local date so the snapshot
            // format test remains correct even if SLOT_DEFINITIONS change.
            $timezone = new DateTimeZone(ReservationService::getSlotTimezone());
            $localDate = Carbon::parse('2026-04-02', $timezone);
            $slotStartLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot['start'], $timezone);
            $slotEndLocal = Carbon::parse($localDate->toDateString().' '.$firstSlot['end'], $timezone);

            $mock->shouldReceive('getSlotAvailabilityForDate')
                ->once()
                ->andReturn([
                    [
                        'id' => 1,
                        'spotNumber' => 'A-01',
                        'slots' => [
                            [
                                'key' => $slotKey,
                                'start' => $firstSlot['start'],
                                'end' => $firstSlot['end'],
                                'startUtc' => $slotStartLocal->copy()->utc()->toISOString(),
                                'endUtc' => $slotEndLocal->copy()->utc()->toISOString(),
                                'taken' => false,
                            ],
                        ],
                    ],
                ]);
        });

        $response = $this->withValidJwt()->getJson('/api/slots?date=2026-04-02');

        $response->assertOk();
        $response->assertJson([
            'date' => '2026-04-02',
        ]);
        $response->assertJsonStructure([
            'date',
            'spots' => [
                ['id', 'spotNumber', 'slots'],
            ],
        ]);
    }

    /**
     * Validates the date query string format.
     */
    public function test_get_slots_validates_date_format(): void
    {
        $response = $this->withValidJwt()->getJson('/api/slots?date=not-a-date');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['date']);
    }
}
