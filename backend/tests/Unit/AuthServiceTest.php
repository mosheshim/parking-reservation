<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_empty_array_when_user_does_not_exist(): void
    {
        config(['app.key' => 'base64:'.base64_encode('test-secret')]);

        $service = app(AuthService::class);
        $result = $service->login('missing@example.com', 'password');

        $this->assertSame([], $result);
    }

    public function test_login_returns_empty_array_when_password_is_invalid(): void
    {
        config(['app.key' => 'base64:'.base64_encode('test-secret')]);

        $user = User::factory()->create([
            'email' => 'user@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $service = app(AuthService::class);
        $result = $service->login($user->email, 'wrong-password');

        $this->assertSame([], $result);
    }

    public function test_login_returns_jwt_and_user_payload_when_credentials_are_valid(): void
    {
        config(['app.key' => 'base64:'.base64_encode('test-secret')]);

        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => bcrypt('correct-password'),
        ]);

        $service = app(AuthService::class);
        $result = $service->login($user->email, 'correct-password');

        $this->assertIsArray($result);
        $this->assertSame('Bearer', $result['token_type'] ?? null);
        $this->assertSame($user->id, $result['user']['id'] ?? null);
        $this->assertSame($user->name, $result['user']['name'] ?? null);
        $this->assertSame($user->email, $result['user']['email'] ?? null);

        $token = $result['token'] ?? null;
        $this->assertIsString($token);

        [$headerB64, $payloadB64, $sigB64] = explode('.', $token);

        $header = json_decode($this->base64UrlDecode($headerB64), true);
        $payload = json_decode($this->base64UrlDecode($payloadB64), true);

        $this->assertSame('HS256', $header['alg'] ?? null);
        $this->assertSame('JWT', $header['typ'] ?? null);

        $this->assertSame($user->id, $payload['sub'] ?? null);
        $this->assertSame($user->email, $payload['email'] ?? null);
        $this->assertSame($user->name, $payload['name'] ?? null);
        $this->assertIsInt($payload['iat'] ?? null);
        $this->assertIsInt($payload['exp'] ?? null);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);

        $expectedSig = hash_hmac('sha256', $headerB64.'.'.$payloadB64, 'test-secret', true);
        $this->assertSame($this->base64UrlEncode($expectedSig), $sigB64);
    }

    /**
     * This is a helper function to decode a base64Url encoded string.
     *
     * @param string $data
     * @return string
     */
    private function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($data, true);
    }

    /**
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
