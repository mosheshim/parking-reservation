<?php

namespace App\Services;

use App\Models\User;
use App\Support\Base64Url;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AuthService
{
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

        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT',
        ];

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        $headerB64 = Base64Url::encode((string) json_encode($header, JSON_UNESCAPED_SLASHES));
        $payloadB64 = Base64Url::encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        $data = $headerB64.'.'.$payloadB64;
        $signature = hash_hmac('sha256', $data, $this->jwtSecret(), true);
        $signatureB64 = Base64Url::encode($signature);

        return $data.'.'.$signatureB64;
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
