<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ParaderoPageController extends Controller
{
    public function index(): View
    {
        $data = [
            ['id' => 'paradero-001', 'nombre' => 'Paradero Principal Mina 1', 'ubicacion' => ' Km 45', 'estado' => 'ACTIVO'],
            ['id' => 'paradero-002', 'nombre' => 'Paradero Secundario Mina 1', 'ubicacion' => ' Km 50', 'estado' => 'ACTIVO'],
            ['id' => 'paradero-003', 'nombre' => 'Paradero Taller Central', 'ubicacion' => 'Lima', 'estado' => 'ACTIVO'],
        ];
        
        return view('catalogos.paraderos.index', compact('data'));
    }

    public function show($id): View
    {
        $item = ['id' => $id, 'nombre' => 'Paradero Demo', 'ubicacion' => 'Por definir', 'estado' => 'ACTIVO'];
        
        return view('catalogos.paraderos.show', compact('item'));
    }
}