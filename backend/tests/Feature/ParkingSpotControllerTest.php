<?php

namespace Tests\Feature;

use App\Models\ParkingSpot;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParkingSpotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_spots_requires_bearer_token(): void
    {
        $response = $this->getJson('/api/spots');
        $response->assertStatus(401);
    }

    public function test_get_spots_returns_all_spots(): void
    {
        $user = User::factory()->loginable()->create();
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        ParkingSpot::factory()->create(['spot_number' => '1']);
        ParkingSpot::factory()->create(['spot_number' => '2']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/spots');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['spot_number' => '1']);
        $response->assertJsonFragment(['spot_number' => '2']);
    }
}
