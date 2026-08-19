<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class SessionExpiredHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/__test/session-expired-token', function () {
            throw new TokenMismatchException('CSRF token expired.');
        });
    }

    public function test_expired_web_session_redirects_to_login_with_clear_message(): void
    {
        $response = $this->post('/__test/session-expired-token');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('session_expired', 'Tu sesion caduco por inactividad. Recarga la pagina e inicia sesion nuevamente.');
    }

    public function test_expired_ajax_session_returns_json_instruction(): void
    {
        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/__test/session-expired-token');

        $response->assertStatus(419);
        $response->assertJson([
            'ok' => false,
            'error' => 'SESSION_EXPIRED',
            'message' => 'Tu sesion caduco por inactividad. Recarga la pagina e inicia sesion nuevamente.',
            'redirect' => route('login'),
        ]);
    }
}
