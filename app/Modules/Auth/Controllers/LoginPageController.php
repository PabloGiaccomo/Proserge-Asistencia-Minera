<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

    public function login(Request $request)
    {
        $payload = $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $throttleKey = Str::lower((string) $payload['email']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return back()
                ->with('error', 'Demasiados intentos. Intenta de nuevo en ' . max($seconds, 1) . ' segundos.')
                ->withInput($request->except('password'));
        }

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'rolesAdicionales:id,nombre,permisos', 'personal:id,nombre_completo'])
            ->where('email', $payload['email'])
            ->first();

        if ($usuario && Hash::check($payload['password'], $usuario->password)) {
            $estado = Schema::hasColumn('usuarios', 'estado')
                ? strtoupper((string) $usuario->estado)
                : 'ACTIVO';

            if ($estado !== 'ACTIVO') {
                RateLimiter::hit($throttleKey, 300);

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
            RateLimiter::clear($throttleKey);

            return redirect()->route('inicio')->with('success', 'Bienvenido');
        }

        RateLimiter::hit($throttleKey, 300);

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
