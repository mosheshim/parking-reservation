<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\AuthService;
use App\Support\Base64Url;
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

        $user = User::factory()->loginable()->create();

        $service = app(AuthService::class);
        $result = $service->login($user->email, 'wrong-password');

        $this->assertSame([], $result);
    }

    public function test_login_returns_jwt_and_user_payload_when_credentials_are_valid(): void
    {
        config(['app.key' => 'base64:'.base64_encode('test-secret')]);

        $user = User::factory()->loginable()->create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
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

        $header = json_decode(Base64Url::decode($headerB64), true);
        $payload = json_decode(Base64Url::decode($payloadB64), true);

        $this->assertSame('HS256', $header['alg'] ?? null);
        $this->assertSame('JWT', $header['typ'] ?? null);

        $this->assertSame($user->id, $payload['sub'] ?? null);
        $this->assertSame($user->email, $payload['email'] ?? null);
        $this->assertSame($user->name, $payload['name'] ?? null);
        $this->assertIsInt($payload['iat'] ?? null);
        $this->assertIsInt($payload['exp'] ?? null);
        $this->assertGreaterThan($payload['iat'], $payload['exp']);

        $expectedSig = hash_hmac('sha256', $headerB64.'.'.$payloadB64, 'test-secret', true);
        $this->assertSame(Base64Url::encode($expectedSig), $sigB64);
    }
}
