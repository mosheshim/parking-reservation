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
        $service = app(AuthService::class);
        $result = $service->login('missing@example.com', 'password');

        $this->assertSame([], $result);
    }

    public function test_login_returns_empty_array_when_password_is_invalid(): void
    {
        $user = User::factory()->loginable()->create();

        $service = app(AuthService::class);
        $result = $service->login($user->email, 'wrong-password');

        $this->assertSame([], $result);
    }

    public function test_login_returns_jwt_and_user_payload_when_credentials_are_valid(): void
    {
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

        $decoded = $service->decodeToken($token);

        $this->assertSame($user->id, $decoded['sub'] ?? null);
        $this->assertSame($user->email, $decoded['email'] ?? null);
        $this->assertSame($user->name, $decoded['name'] ?? null);
        $this->assertIsInt($decoded['iat'] ?? null);
        $this->assertIsInt($decoded['exp'] ?? null);
        $this->assertGreaterThan($decoded['iat'], $decoded['exp']);
    }
}
