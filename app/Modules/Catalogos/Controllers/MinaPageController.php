<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class MinaPageController extends Controller
{
    public function index(): View
    {
        $data = [
            ['id' => 'mina-001', 'nombre' => 'Mina 1', 'ubicacion' => 'Cerro de Pasco', 'estado' => 'ACTIVO'],
            ['id' => 'mina-002', 'nombre' => 'Mina 2', 'ubicacion' => 'Lima', 'estado' => 'ACTIVO'],
            ['id' => 'mina-003', 'nombre' => 'Mina 3', 'ubicacion' => 'Ancash', 'estado' => 'ACTIVO'],
            ['id' => 'mina-004', 'nombre' => 'Mina 4', 'ubicacion' => 'Ica', 'estado' => 'ACTIVO'],
            ['id' => 'mina-005', 'nombre' => 'Mina 5', 'ubicacion' => 'Arequipa', 'estado' => 'INACTIVO'],
        ];
        
        return view('catalogos.minas.index', compact('data'));
    }

    public function show($id): View
    {
        $item = ['id' => $id, 'nombre' => 'Mina ' . $id, 'ubicacion' => 'Por definir', 'estado' => 'ACTIVO'];
        
        return view('catalogos.minas.show', compact('item'));
    }
}