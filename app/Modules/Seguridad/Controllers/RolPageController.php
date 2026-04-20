<?php

namespace App\Modules\Seguridad\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class RolPageController extends Controller
{
    public function index(): View
    {
        $data = [
            ['id' => 'rol-001', 'nombre' => 'ADMIN', 'descripcion' => 'Administrador con acceso total', 'estado' => 'ACTIVO'],
            ['id' => 'rol-002', 'nombre' => 'SUPERVISOR', 'descripcion' => 'Supervisor con gestión de personal', 'estado' => 'ACTIVO'],
            ['id' => 'rol-003', 'nombre' => 'USUARIO', 'descripcion' => 'Usuario regular', 'estado' => 'ACTIVO'],
            ['id' => 'rol-004', 'nombre' => 'AUDITOR', 'descripcion' => 'Auditor de solo lectura', 'estado' => 'ACTIVO'],
        ];
        
        return view('seguridad.roles.index', compact('data'));
    }

    public function show($id): View
    {
        $item = ['id' => $id, 'nombre' => 'Rol Demo', 'descripcion' => 'Descripción del rol', 'estado' => 'ACTIVO'];
        
        return view('seguridad.roles.show', compact('item'));
    }
}