<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UsuarioPageController extends Controller
{
    private function getAllUsers(): array
    {
        return [
            ['id' => 'usr-001', 'name' => 'Administrador', 'dni' => '12345678', 'email' => 'admin@proserge.com', 'rol' => 'ADMIN', 'estado' => 'ACTIVO', 'scope_mina' => ['Mina 1', 'Mina 2', 'Mina 3', 'Taller', 'Oficina']],
            ['id' => 'usr-002', 'name' => 'Juan Pérez', 'dni' => '23456789', 'email' => 'juan.perez@proserge.com', 'rol' => 'SUPERVISOR', 'estado' => 'ACTIVO', 'scope_mina' => ['Mina 1', 'Mina 2']],
            ['id' => 'usr-003', 'name' => 'María García', 'dni' => '34567890', 'email' => 'maria.garcia@proserge.com', 'rol' => 'USUARIO', 'estado' => 'ACTIVO', 'scope_mina' => ['Mina 1']],
            ['id' => 'usr-004', 'name' => 'Carlos López', 'dni' => '45678901', 'email' => 'carlos.lopez@proserge.com', 'rol' => 'USUARIO', 'estado' => 'INACTIVO', 'scope_mina' => []],
            ['id' => 'usr-005', 'name' => 'Ana Torres', 'dni' => '56789012', 'email' => 'ana.torres@proserge.com', 'rol' => 'SUPERVISOR', 'estado' => 'ACTIVO', 'scope_mina' => ['Mina 2', 'Mina 3', 'Taller']],
        ];
    }

    public function index(): View
    {
        $data = $this->getAllUsers();
        return view('seguridad.usuarios.index', compact('data'));
    }

    public function show($id): View
    {
        $users = $this->getAllUsers();
        $item = collect($users)->firstWhere('id', $id);
        
        if (!$item) {
            abort(404);
        }
        
        return view('seguridad.usuarios.show', compact('item'));
    }

    public function update(Request $request, $id): View
    {
        return redirect()->route('usuarios.show', $id)->with('success', 'Usuario actualizado correctamente');
    }

    public function editarScope($usuarioId): View
    {
        $users = $this->getAllUsers();
        $usuario = collect($users)->firstWhere('id', $usuarioId);
        
        if (!$usuario) {
            abort(404);
        }
        
        $scopes = $usuario['scope_mina'] ?? [];
        
        $todasLasMinas = [
            ['id' => 'mina-001', 'nombre' => 'Mina 1'],
            ['id' => 'mina-002', 'nombre' => 'Mina 2'],
            ['id' => 'mina-003', 'nombre' => 'Mina 3'],
            ['id' => 'taller-001', 'nombre' => 'Taller'],
            ['id' => 'oficina-001', 'nombre' => 'Oficina'],
        ];
        
        return view('seguridad.usuarios.scope', compact('usuario', 'scopes', 'todasLasMinas'));
    }

    public function syncScope(Request $request, $usuarioId)
    {
        return redirect()->route('usuarios.show', $usuarioId)->with('success', 'Scope actualizado correctamente');
    }
}