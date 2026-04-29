<?php

namespace App\Modules\Perfil\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use Illuminate\View\View;

class PerfilPageController extends Controller
{
    public function index(): View
    {
        $user = session('user', []);
        $userId = (string) ($user['id'] ?? '');

        $usuario = null;
        if ($userId !== '') {
            $usuario = Usuario::query()
                ->with([
                    'rol:id,nombre',
                    'personal:id,nombre_completo',
                    'personal.minas:id,nombre',
                    'scopesMina.mina:id,nombre',
                ])
                ->find($userId);
        }
        
        $perfil = [
            'id' => $usuario?->id ?? ($user['id'] ?? 'No disponible'),
            'nombre' => $usuario?->personal?->nombre_completo ?? ($user['name'] ?? 'No disponible'),
            'email' => $usuario?->email ?? ($user['email'] ?? 'No disponible'),
            'rol' => $usuario?->rol?->nombre ?? ($user['rol'] ?? 'No disponible'),
            'estado' => $usuario?->estado ?? ($user['estado'] ?? 'Activo'),
        ];
        
        $evaluacionesResumen = [
            'total' => 0,
            'promedio' => 'No disponible',
            'ultima' => 'No disponible',
        ];
        
        $minasDesdePersonal = $usuario?->personal?->minas
            ?->pluck('nombre')
            ->filter(fn ($nombre) => is_string($nombre) && trim($nombre) !== '')
            ->values()
            ->all() ?? [];

        $minasDesdeScopeUsuario = $usuario?->scopesMina
            ?->map(fn ($scope) => $scope->mina?->nombre)
            ->filter(fn ($nombre) => is_string($nombre) && trim($nombre) !== '')
            ->values()
            ->all() ?? [];

        $minasHabilitadas = collect(array_merge($minasDesdePersonal, $minasDesdeScopeUsuario))
            ->map(fn ($nombre) => trim((string) $nombre))
            ->filter(fn ($nombre) => $nombre !== '')
            ->unique()
            ->values()
            ->all();
        
        return view('perfil.index', compact('perfil', 'evaluacionesResumen', 'minasHabilitadas'));
    }
}
