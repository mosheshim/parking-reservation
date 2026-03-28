<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\AuthService;
use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class JwtAuth
{
    public function __construct(
        private readonly AuthService $authService,
    ) {
    }

    /**
     * Authenticate requests using the Bearer token issued by AuthService.
     *
     * This exists because the project issues a JWT on login, but does not rely on Laravel Sanctum/Passport.
     * The middleware validates the signature/expiry and sets the authenticated user for the request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->bearerToken($request);

        if ($token === null) {
            return response()->json(['message' => 'Missing Bearer token'], 401);
        }

        try {
            $payload = $this->authService->decodeToken($token);
        } catch (ExpiredException | SignatureInvalidException | UnexpectedValueException) {
            return response()->json(['message' => 'Invalid or expired token'], 401);
        }

        $userId = $payload['sub'] ?? null;
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return response()->json(['message' => 'Invalid token payload'], 401);
        }

        $user = User::query()->find((int) $userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 401);
        }

        Auth::setUser($user);

        return $next($request);
    }

    /**
     * Extract a Bearer token from the Authorization header.
     */
    private function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization');
        if (!is_string($header) || $header === '') {
            return null;
        }

        if (!preg_match('/^Bearer\s+(?<token>.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim((string) ($matches['token'] ?? ''));
        return $token !== '' ? $token : null;
    }
}
