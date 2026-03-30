<?php

namespace App\Http\Controllers;

use App\Services\ParkingSpotService;
use Illuminate\Http\JsonResponse;

class ParkingSpotController extends Controller
{
    public function __construct(
        private readonly ParkingSpotService $parkingSpotService,
    ) {
    }

    /**
     * Return all parking spots.
     */
    public function index(): JsonResponse
    {
        return response()->json($this->parkingSpotService->listAll());
    }
}
