<?php

namespace App\Modules\Catalogos\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class TallerPageController extends Controller
{
    public function index(): View
    {
        $data = [
            ['id' => 'taller-001', 'nombre' => 'Taller Mecánico Central', 'ubicacion' => 'Lima', 'estado' => 'ACTIVO'],
            ['id' => 'taller-002', 'nombre' => 'Taller de Soldadura', 'ubicacion' => 'Lima', 'estado' => 'ACTIVO'],
            ['id' => 'taller-003', 'nombre' => 'Taller Eléctrico', 'ubicacion' => 'Cerro de Pasco', 'estado' => 'ACTIVO'],
        ];
        
        return view('catalogos.talleres.index', compact('data'));
    }

    public function show($id): View
    {
        $item = ['id' => $id, 'nombre' => 'Taller ' . $id, 'ubicacion' => 'Por definir', 'estado' => 'ACTIVO'];
        
        return view('catalogos.talleres.show', compact('item'));
    }
}