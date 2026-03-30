<?php

namespace Tests\Feature;

use App\Models\ParkingSpot;
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
        ParkingSpot::factory()->create(['spot_number' => '1']);
        ParkingSpot::factory()->create(['spot_number' => '2']);

        $response = $this->withValidJwt()->getJson('/api/spots');

        $response->assertOk();
        $response->assertJsonCount(2);
        $response->assertJsonFragment(['spot_number' => '1']);
        $response->assertJsonFragment(['spot_number' => '2']);
    }
}
