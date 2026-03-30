<?php

namespace Tests;

use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Create a valid Authorization header value for a loginable user.
     */
    protected function authorizationHeaderForUser(User $user): string
    {
        $auth = app(AuthService::class);
        $token = $auth->login($user->email, 'correct-password')['token'];

        return 'Bearer '.$token;
    }

    /**
     * Return the current test instance pre-configured with a valid Bearer token.
     *
     * This exists to keep feature tests focused on the behavior under test rather than repeated JWT setup.
     */
    protected function withValidJwt(?User $user = null): static
    {
        $user ??= User::factory()->loginable()->create();

        return $this->withHeader('Authorization', $this->authorizationHeaderForUser($user));
    }
}
