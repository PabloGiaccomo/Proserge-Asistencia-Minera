<?php

namespace App\Modules\MiAsistencia\Controllers;

use App\Http\Controllers\WebPageController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MiAsistenciaPageController extends WebPageController
{
    public function index(Request $request): View
    {
        $user = $this->getUser();
        $fecha = $request->get('fecha', date('Y-m-d'));
        
        return view('mi-asistencia.index', compact('user', 'fecha'));
    }

    public function show(string $id): View
    {
        $user = $this->getUser();
        
        return view('mi-asistencia.show', compact('id', 'user'));
    }
}
