<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

class AuthLoginRateLimitTest extends TestCase
{
    public function test_web_and_api_share_the_attempt_limit_without_leaking_credentials(): void
    {
        $this->freezeTime();
        $email = Str::uuid().'@audit.test';
        $payload = ['email' => $email, 'password' => 'invalid-test-password'];
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->from('/login')->post('/login', $payload)->assertRedirect('/login');
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/auth/login', [...$payload, 'email' => strtoupper($email)])
                ->assertUnauthorized();
        }

        $this->postJson('/api/v1/auth/login', $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '300')
            ->assertJsonPath('data', null);

        $this->postJson('/api/v1/auth/login', [...$payload, 'email' => Str::uuid().'@audit.test'])
            ->assertUnauthorized();
        $this->travel(301)->seconds();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnauthorized();
    }
}
