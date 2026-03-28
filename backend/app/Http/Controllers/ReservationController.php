<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ReservationController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {
    }

    /**
     * Create a new reservation for the authenticated user.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'spot_id' => ['required', 'integer', 'min:1'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date'],
        ]);

        $user = Auth::user();
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $reservation = $this->reservationService->create(
                $user,
                (int) $payload['spot_id'],
                (string) $payload['start_time'],
                (string) $payload['end_time'],
            );
        } catch (RuntimeException $e) {
            Log::debug('Reservation conflict', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        }

        return response()->json([
            'id' => $reservation->id,
            'user_id' => $reservation->user_id,
            'spot_id' => $reservation->spot_id,
            'start_time' => $reservation->start_time,
            'end_time' => $reservation->end_time,
            'status' => $reservation->status,
        ], 201);
    }

    /**
     * Mark a reservation as completed.
     */
    public function complete(int $id): JsonResponse
    {
        $reservation = $this->reservationService->complete($id);

        return response()->json([
            'id' => $reservation->id,
            'user_id' => $reservation->user_id,
            'spot_id' => $reservation->spot_id,
            'start_time' => $reservation->start_time,
            'end_time' => $reservation->end_time,
            'status' => $reservation->status,
        ]);
    }
}
