<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Modules\Auth\Services\LoginAttemptLimiter;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LoginPageController extends Controller
{
    public function showLoginForm()
    {
        if (session('auth_token')) {
            return redirect()->route('inicio');
        }
        
        return view('auth.login');
    }

    public function login(Request $request, LoginAttemptLimiter $attempts)
    {
        $payload = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $seconds = $attempts->retryAfter($payload['email'], $request->ip());
        if ($seconds > 0) {
            return back()
                ->with('error', 'Demasiados intentos. Intenta de nuevo en ' . max($seconds, 1) . ' segundos.')
                ->withInput($request->except('password'));
        }

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,estado,permisos', 'rolesAdicionales:id,nombre,estado,permisos', 'personal:id,nombre_completo'])
            ->where('email', $payload['email'])
            ->first();

        if ($usuario && Hash::check($payload['password'], $usuario->password)) {
            $estado = Schema::hasColumn('usuarios', 'estado')
                ? strtoupper((string) $usuario->estado)
                : 'ACTIVO';

            if ($estado !== 'ACTIVO') {
                $attempts->failed($payload['email'], $request->ip());

                return back()
                    ->with('error', 'Tu usuario se encuentra inactivo.')
                    ->withInput($request->except('password'));
            }

            $token = Str::random(80);

            $roles = collect([$usuario->rol])
                ->merge($usuario->rolesAdicionales)
                ->filter()
                ->unique('id')
                ->values();

            session([
                'auth_token' => $token,
                'user' => [
                    'id' => $usuario->id,
                    'email' => $usuario->email,
                    'name' => $usuario->personal?->nombre_completo ?? $usuario->email,
                    'rol' => $usuario->rol?->nombre ?? 'Usuario',
                    'roles' => $roles->map(fn ($rol) => $rol->nombre)->values()->all(),
                    'permissions' => PermissionMatrix::normalizeForRoles($roles->all()),
                ],
                'user_id' => $usuario->id,
            ]);

            $request->session()->regenerate();
            $attempts->succeeded($payload['email'], $request->ip());

            return redirect()->route('inicio')->with('success', 'Bienvenido');
        }

        $attempts->failed($payload['email'], $request->ip());

        return back()->with('error', 'Credenciales incorrectas.')->withInput($request->except('password'));
    }

    public function logout(Request $request)
    {
        session()->forget(['auth_token', 'user', 'user_id']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        
        return redirect()->route('login');
    }
}
