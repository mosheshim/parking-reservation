<?php

namespace App\Http\Controllers;

use App\Exceptions\ReservationTimeConflictException;
use App\Exceptions\ReservationTimeOutOfRangeException;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
            'spot_id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'exists:parking_spots,id'],
            'start_time' => ['required', 'date'],
            'end_time' => ['required', 'date', 'after:start_time', 'after:now'],
        ]);

        $user = Auth::user();
        if (!$user instanceof User) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        try {
            $reservation = $this->reservationService->createReservation(
                $user,
                (int) $payload['spot_id'],
                Carbon::parse((string) $payload['start_time'])->utc(),
                Carbon::parse((string) $payload['end_time'])->utc(),
            );
        } catch (ReservationTimeConflictException $e) {
            Log::debug('Reservation conflict', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 409);
        } catch (ReservationTimeOutOfRangeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
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
    public function complete(Request $request): Response
    {
        $payload = validator(['id' => $request->route('id'),], [
            'id' => ['required', 'integer', 'min:1', 'max:'.PHP_INT_MAX, 'exists:reservations,id'],
        ])->validate();

        $this->reservationService->complete((int) $payload['id']);

        return response()->noContent();
    }
}
