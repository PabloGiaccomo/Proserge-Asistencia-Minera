<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
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

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $usuario = Usuario::query()
            ->with(['rol:id,nombre,permisos', 'personal:id,nombre_completo'])
            ->where('email', $request->email)
            ->first();

        if ($usuario && Hash::check($request->password, $usuario->password)) {
            $estado = Schema::hasColumn('usuarios', 'estado')
                ? strtoupper((string) $usuario->estado)
                : 'ACTIVO';

            if ($estado !== 'ACTIVO') {
                return back()
                    ->with('error', 'Tu usuario se encuentra inactivo.')
                    ->withInput($request->except('password'));
            }

            $token = Str::random(80);

            session([
                'auth_token' => $token,
                'user' => [
                    'id' => $usuario->id,
                    'email' => $usuario->email,
                    'name' => $usuario->personal?->nombre_completo ?? $usuario->email,
                    'rol' => $usuario->rol?->nombre ?? 'Usuario',
                    'permissions' => PermissionMatrix::normalizeForRole($usuario->rol?->nombre, $usuario->rol?->permisos ?? []),
                ],
                'user_id' => $usuario->id,
            ]);

            return redirect()->route('inicio')->with('success', 'Bienvenido');
        }

        // Mantener acceso de desarrollo mientras existan ambientes sin usuarios cargados.
        if ($request->email === 'admin@proserge.com' && $request->password === 'admin123') {
            $token = Str::random(80);
            
            session([
                'auth_token' => $token,
                'user' => [
                    'id' => '00000000-0000-0000-0000-000000000001',
                    'email' => 'admin@proserge.com',
                    'name' => 'Administrador',
                    'rol' => 'ADMIN',
                    'permissions' => ['*'],
                ],
                'user_id' => '00000000-0000-0000-0000-000000000001',
            ]);

            return redirect()->route('inicio')->with('success', 'Bienvenido');
        }

        return back()->with('error', 'Credenciales incorrectas.')->withInput($request->except('password'));
    }

    public function logout()
    {
        session()->forget(['auth_token', 'user', 'user_id']);
        
        return redirect()->route('login');
    }
}
