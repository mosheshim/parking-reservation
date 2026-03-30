<?php

namespace Tests\Feature;

use App\Models\ParkingSpot;
use App\Models\Reservation;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_post_reservations_requires_bearer_token(): void
    {
        $response = $this->postJson('/api/reservations', [
            'spot_id' => 1,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(401);
    }

    public function test_post_reservations_validates_time_range(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $spot = ParkingSpot::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => now()->addHours(2)->toISOString(),
            'end_time' => now()->addHour()->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_time']);
    }

    public function test_post_reservations_rejects_start_time_in_the_past(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $spot = ParkingSpot::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => now()->subHour()->toISOString(),
            'end_time' => now()->addHour()->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['start_time']);
    }

    public function test_post_reservations_rejects_non_existing_spot_id(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/reservations', [
            'spot_id' => 999999,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    public function test_post_reservations_validates_id_range(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/reservations', [
            'spot_id' => $this->tooLargeId(),
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['spot_id']);
    }

    public function test_post_reservations_creates_reservation(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $spot = ParkingSpot::factory()->create();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/reservations', [
            'spot_id' => $spot->id,
            'start_time' => now()->addHour()->toISOString(),
            'end_time' => now()->addHours(2)->toISOString(),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('reservations', 1);
        $this->assertSame($user->id, $response->json('user_id'));
        $this->assertSame($spot->id, $response->json('spot_id'));
        $this->assertSame(Reservation::STATUS_BOOKED, $response->json('status'));
    }

    public function test_put_complete_validates_id_range(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $tooBig = $this->tooLargeId();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/reservations/'.$tooBig.'/complete');

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['id']);
    }

    public function test_put_complete_marks_reservation_completed(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $reservation = Reservation::factory()->create(['status' => Reservation::STATUS_BOOKED]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/reservations/'.$reservation->id.'/complete');

        $response->assertNoContent();
        $this->assertDatabaseHas('reservations', [
            'id' => $reservation->id,
            'status' => Reservation::STATUS_COMPLETED,
        ]);
    }

    public function test_put_complete_with_non_numeric_id_is_rejected_by_routing(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/reservations/not-a-number/complete');

        $response->assertStatus(404);
    }

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
}
