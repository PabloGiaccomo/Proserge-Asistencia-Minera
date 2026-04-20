<?php

namespace App\Modules\Perfil\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PerfilPageController extends Controller
{
    public function index(): View
    {
        $user = session('user', []);
        
        $perfil = [
            'id' => $user['id'] ?? 'No disponible',
            'nombre' => $user['name'] ?? 'No disponible',
            'email' => $user['email'] ?? 'No disponible',
            'rol' => $user['rol'] ?? 'No disponible',
            'estado' => $user['estado'] ?? 'Activo',
        ];
        
        $evaluacionesResumen = [
            'total' => 0,
            'promedio' => 'No disponible',
            'ultima' => 'No disponible',
        ];
        
        $minasHabilitadas = $user['scopes'] ?? [];
        
        return view('perfil.index', compact('perfil', 'evaluacionesResumen', 'minasHabilitadas'));
    }
}