<?php

namespace App\Modules\Auth\Services;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class LoginAttemptLimiter
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 300;

    public function retryAfter(string $email, ?string $ip): int
    {
        $key = $this->key($email, $ip);

        return RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)
            ? max(RateLimiter::availableIn($key), 1)
            : 0;
    }

    public function failed(string $email, ?string $ip): void
    {
        RateLimiter::hit($this->key($email, $ip), self::DECAY_SECONDS);
    }

    public function succeeded(string $email, ?string $ip): void
    {
        RateLimiter::clear($this->key($email, $ip));
    }

    private function key(string $email, ?string $ip): string
    {
        return 'auth-login:'.hash('sha256', Str::lower(trim($email)).'|'.$ip);
    }
}
