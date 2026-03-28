<?php

namespace App\Services;

use App\Exceptions\InvalidJwtTokenException;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Throwable;

class AuthService
{
    private const JWT_ALGORITHM = 'HS256';

    /**
     * Authenticate a user by email/password and return a signed JWT + user payload.
     *
     * @return array{token: string, token_type: string, user: array{id: int, name: string, email: string}} Empty array is returned if authentication fails.
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            return [];
        }

        $token = $this->createJwt($user);

        return [
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ];
    }

    /**
     * Create a compact JWS (JWT) string signed with HS256.
     */
    private function createJwt(User $user): string
    {
        $now = time();
        $ttlSeconds = (int) env('JWT_TTL_SECONDS', 3600);

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        return JWT::encode($payload, $this->jwtSecret(), self::JWT_ALGORITHM);
    }

    /**
     * Decode and validate a JWT issued by this service.
     *
     * This exists to share validation logic with middleware/tests.
     *
     * @throws RuntimeException When the JWT signing key is misconfigured (system/config issue).
     * @throws InvalidJwtTokenException When the provided token is invalid or expired (client issue).
     *
     * @return array<string, mixed>
     */
    public function decodeToken(string $token): array
    {
        // If the key is invalid, it means something in the system is not configured correctly.
        try {
            $key = new Key($this->jwtSecret(), self::JWT_ALGORITHM);
        } catch (Throwable $e) {
            throw new RuntimeException('JWT signing key is misconfigured.', previous: $e);
        }

        // If decode has failed, it means the token is invalid.
        try {
            $decoded = JWT::decode($token, $key);
        } catch (Throwable $e) {
            throw new InvalidJwtTokenException(previous: $e);
        }

        return (array) $decoded;
    }

    /**
     * Get the secret used to sign the token.
     *
     * @throws RuntimeException When APP_KEY is prefixed with "base64:" but cannot be decoded.
     */
    private function jwtSecret(): string
    {
        $secret = (string) config('app.key');

        // If the secret is not base64 encoded, return it as is. This is just a safety check.
        // This shouldn't happen because Laravel stores the APP_KEY as a base64 encoded value with a "base64:" prefix.
        if (!str_starts_with($secret, 'base64:')) {
            return $secret;
        }

        $decoded = base64_decode(substr($secret, 7), true);

        if ($decoded === false) {
            throw new RuntimeException('Invalid APP_KEY: base64 decoding failed.');
        }

        return $decoded;
    }

}
