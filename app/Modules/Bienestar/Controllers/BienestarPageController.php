<?php

namespace App\Modules\Bienestar\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BienestarPageController extends Controller
{
    public function index(Request $request): View
    {
        $user = [
            'name' => session('user.name') ?? 'Usuario',
            'email' => session('user.email') ?? '',
            'rol' => session('user.rol') ?? 'USUARIO'
        ];
        $tipo = $request->get('tipo', '');
        
        return view('bienestar.index', compact('user', 'tipo'));
    }

    public function show(string $id): View
    {
        $user = [
            'name' => session('user.name') ?? 'Usuario',
            'email' => session('user.email') ?? '',
            'rol' => session('user.rol') ?? 'USUARIO'
        ];
        
        return view('bienestar.show', compact('id', 'user'));
    }
}