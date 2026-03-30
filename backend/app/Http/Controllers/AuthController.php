<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
    }

    /**
     * Authenticate a user using email/password and return a signed JWT.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1'],
        ]);

        try {
            $result = $this->authService->login($credentials['email'], $credentials['password']);
        } catch (RuntimeException $e) {
            Log::error('Error logging user', ['error' => $e]);
            return response()->json([
                'message' => 'Server configuration error. Please contact support.',
            ], 500);
        }

        if ($result === []) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        return response()->json($result);
    }
}
