<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuthToken;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use App\Shared\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $payload = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos'])
            ->where('email', $payload['email'])
            ->first();

        if (!$usuario || !Hash::check($payload['password'], $usuario->password)) {
            return ApiResponse::error(
                message: 'Credenciales invalidas',
                code: 'AUTH_INVALID_CREDENTIALS',
                status: 401,
            );
        }

        $plainToken = Str::random(80);

        AuthToken::query()->create([
            'id' => (string) Str::uuid(),
            'usuario_id' => $usuario->id,
            'token_hash' => hash('sha256', $plainToken),
            'expires_at' => now()->addHours(12),
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return ApiResponse::success(
            data: [
                'access_token' => $plainToken,
                'token_type' => 'Bearer',
                'expires_at' => now()->addHours(12)->toIso8601String(),
                'usuario' => $this->buildUserPayload($usuario),
            ],
            message: 'Login exitoso',
            code: 'AUTH_LOGIN_OK',
        );
    }

    public function me(Request $request)
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();

        return ApiResponse::success(
            data: $this->buildUserPayload($usuario),
            message: 'Perfil obtenido',
            code: 'AUTH_ME_OK',
        );
    }

    private function buildUserPayload(Usuario $usuario): array
    {
        $usuario->loadMissing(['rol:id,nombre,permisos', 'scopesMina:usuario_id,mina_id']);

        return [
            'id' => $usuario->id,
            'email' => $usuario->email,
            'rol' => [
                'id' => $usuario->rol?->id,
                'nombre' => $usuario->rol?->nombre,
                'permisos' => PermissionMatrix::normalizeForRole($usuario->rol?->nombre, $usuario->rol?->permisos ?? []),
            ],
            'scope_minas' => $usuario->scopesMina->pluck('mina_id')->values(),
        ];
    }
}
