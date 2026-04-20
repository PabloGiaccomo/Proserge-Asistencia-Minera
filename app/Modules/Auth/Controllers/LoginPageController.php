<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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

        // Hardcoded admin for testing (no database needed)
        if ($request->email === 'admin@proserge.com' && $request->password === 'admin123') {
            $token = Str::random(80);
            
            session([
                'auth_token' => $token,
                'user' => [
                    'id' => '00000000-0000-0000-0000-000000000001',
                    'email' => 'admin@proserge.com',
                    'name' => 'Administrador',
                    'rol' => 'ADMIN',
                ],
                'user_id' => '00000000-0000-0000-0000-000000000001',
            ]);

            return redirect()->route('inicio')->with('success', 'Bienvenido');
        }

        return back()->with('error', 'Credenciales incorrectas. Usa admin@proserge.com / admin123')->withInput($request->except('password'));
    }

    public function logout()
    {
        session()->forget(['auth_token', 'user', 'user_id']);
        
        return redirect()->route('login');
    }
}