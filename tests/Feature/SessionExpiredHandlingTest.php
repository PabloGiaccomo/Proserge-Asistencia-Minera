<?php

namespace Tests\Feature;

use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionExpiredHandlingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->post('/__test/session-expired-token', function () {
            throw new TokenMismatchException('CSRF token expired.');
        });

        Route::middleware(['web', 'web.auth'])->get('/__test/orphan-web-session', function () {
            return response()->json(['ok' => true]);
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

    public function test_session_with_missing_database_user_redirects_to_login(): void
    {
        $response = $this
            ->withSession($this->orphanSession())
            ->get('/__test/orphan-web-session');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas(
            'session_expired',
            'Tu sesion ya no es valida. Inicia sesion nuevamente para continuar.'
        );
    }

    public function test_ajax_session_with_missing_database_user_returns_401(): void
    {
        $response = $this
            ->withSession($this->orphanSession())
            ->withHeader('Accept', 'application/json')
            ->get('/__test/orphan-web-session');

        $response->assertStatus(401)->assertJson([
            'ok' => false,
            'error' => 'SESSION_USER_NOT_FOUND',
            'message' => 'Tu sesion ya no es valida. Inicia sesion nuevamente para continuar.',
            'redirect' => route('login'),
        ]);
    }

    private function orphanSession(): array
    {
        $userId = (string) Str::uuid();

        return [
            'auth_token' => 'orphan-session-token',
            'user_id' => $userId,
            'user' => [
                'id' => $userId,
                'email' => 'missing-user@test.local',
                'permissions' => ['*'],
            ],
        ];
    }
}
