<?php

namespace App\Modules\Asistencia\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AsistenciaPageController extends Controller
{
    public function index(): View
    {
        return view('asistencia.index');
    }

    public function grupos(): View
    {
        return view('asistencia.index', ['data' => []]);
    }

    public function show(string $grupoId): View
    {
        return view('asistencia.show', ['grupo' => null]);
    }

    public function marcar(string $grupoId): View
    {
        return view('asistencia.marcar', ['grupo' => null]);
    }

    public function masivo(string $grupoId): View
    {
        return view('asistencia.masivo', ['grupo' => null]);
    }

    public function resumen(): View
    {
        return view('asistencia.resumen');
    }

    public function mina(): View
    {
        return view('asistencia.mina');
    }

    public function parada(): View
    {
        return view('asistencia.parada');
    }

    public function supervisor(): View
    {
        return view('asistencia.supervisor');
    }

    public function personal(): View
    {
        return view('asistencia.personal');
    }

    public function alertas(): View
    {
        return view('asistencia.alertas');
    }

    public function marcarPost(Request $request, string $grupoId)
    {
        return redirect()->route('asistencia.show', $grupoId)->with('success', 'Asistencia marcada');
    }

    public function marcarMasivoPost(Request $request, string $grupoId)
    {
        return redirect()->route('asistencia.show', $grupoId)->with('success', 'Asistencia marcada');
    }

    public function cerrar(string $grupoId)
    {
        return back()->with('success', 'Asistencia cerrada');
    }

    public function reabrir(string $grupoId)
    {
        return back()->with('success', 'Asistencia reopenida');
    }
}