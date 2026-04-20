<?php

namespace App\Modules\ManPower\Controllers;

use App\Http\Controllers\WebPageController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ManPowerPageController extends WebPageController
{
    public function index(): View
    {
        return view('man-power.index');
    }

    public function paradas(): View
    {
        $data = ['data' => []];
        return view('man-power.paradas', compact('data'));
    }

    public function paradaDetalle(string $rqMinaId): View
    {
        $parada = null;
        return view('man-power.parada-detalle', compact('parada'));
    }

    public function grupos(): View
    {
        $grupos = ['data' => []];
        return view('man-power.grupos', compact('grupos'));
    }

    public function grupoDetalle(string $id): View
    {
        $grupo = null;
        return view('man-power.grupo-detalle', compact('grupo'));
    }

    public function crearGrupo(): View
    {
        return view('man-power.grupo-crear');
    }

    public function storeGrupo(Request $request)
    {
        return redirect()->route('man-power.grupos')->with('success', 'Grupo creado correctamente');
    }

    public function updateGrupo(Request $request, string $id)
    {
        return redirect()->route('man-power.grupo-detalle', $id)->with('success', 'Grupo actualizado correctamente');
    }

    public function quitarPersonal(Request $request, string $id)
    {
        return back()->with('success', 'Personal removido correctamente');
    }
}
