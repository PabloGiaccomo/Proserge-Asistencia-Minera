<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class OficinaPageController extends Controller
{
    public function index(): View
    {
        $data = [
            ['id' => 'oficina-001', 'nombre' => 'Oficina Central Lima', 'ubicacion' => 'Lima', 'estado' => 'ACTIVO'],
            ['id' => 'oficina-002', 'nombre' => 'Oficina Cerro de Pasco', 'ubicacion' => 'Cerro de Pasco', 'estado' => 'ACTIVO'],
            ['id' => 'oficina-003', 'nombre' => 'Oficina Ancash', 'ubicacion' => 'Ancash', 'estado' => 'ACTIVO'],
        ];
        
        return view('catalogos.oficinas.index', compact('data'));
    }

    public function show($id): View
    {
        $item = ['id' => $id, 'nombre' => 'Oficina ' . $id, 'ubicacion' => 'Por definir', 'estado' => 'ACTIVO'];
        
        return view('catalogos.oficinas.show', compact('item'));
    }
}