<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_200_and_token_for_valid_credentials(): void
    {
        $user = User::factory()->loginable()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'token',
            'token_type',
            'user' => ['id', 'name', 'email'],
        ]);

        $this->assertSame($user->email, $response->json('user.email'));
    }

    public function test_login_returns_401_for_invalid_credentials(): void
    {
        $user = User::factory()->loginable()->create();

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        $response->assertJson([
            'message' => 'Invalid credentials',
        ]);
    }

    public function test_login_returns_422_when_request_is_invalid(): void
    {
        $response = $this->postJson('/api/login', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_returns_500_when_auth_service_throws_runtime_exception(): void
    {
        $this->mock(AuthService::class, function ($mock): void {
            $mock->shouldReceive('login')
                ->once()
                ->andThrow(new RuntimeException('Invalid APP_KEY'));
        });

        $response = $this->postJson('/api/login', [
            'email' => 'user@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(500);
        $response->assertJson([
            'message' => 'Server configuration error. Please contact support.',
        ]);
    }
}
