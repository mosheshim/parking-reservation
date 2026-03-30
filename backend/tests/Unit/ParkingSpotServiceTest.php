<?php

namespace Tests\Unit;

use App\Models\ParkingSpot;
use App\Services\ParkingSpotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParkingSpotServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_all_returns_spots_ordered_by_id(): void
    {
        ParkingSpot::factory()->create(['spot_number' => 'B']);
        ParkingSpot::factory()->create(['spot_number' => 'A']);

        $service = app(ParkingSpotService::class);
        $result = $service->listAll();

        $this->assertCount(2, $result);
        $this->assertSame('B', $result[0]['spot_number'] ?? null);
        $this->assertSame('A', $result[1]['spot_number'] ?? null);
    }
}
