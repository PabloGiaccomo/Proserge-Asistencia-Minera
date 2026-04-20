<?php

namespace App\Modules\Evaluaciones\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EvaluacionDesempenoPageController extends Controller
{
    public function index(): View
    {
        $user = [
            'name' => session('user.name') ?? 'Usuario',
            'email' => session('user.email') ?? '',
            'rol' => session('user.rol') ?? 'USUARIO'
        ];
        
        $data = [
            ['id' => 'eval-001', 'periodo' => '2024-01', 'puntaje' => 85, 'estado' => 'COMPLETADO'],
            ['id' => 'eval-002', 'periodo' => '2024-02', 'puntaje' => 78, 'estado' => 'COMPLETADO'],
            ['id' => 'eval-003', 'periodo' => '2024-03', 'puntaje' => 92, 'estado' => 'EN_PROCESO'],
        ];
        
        return view('evaluaciones.desempeno.index', compact('data'));
    }

    public function show(string $id): View
    {
        $item = [
            'id' => $id,
            'periodo' => '2024-01',
            'puntaje' => 85,
            'observaciones' => 'Buen desempeño general',
            'estado' => 'COMPLETADO'
        ];
        
        return view('evaluaciones.desempeno.show', compact('item'));
    }

    public function create(): View
    {
        return view('evaluaciones.desempeno.create');
    }

    public function edit(string $id): View
    {
        $item = [
            'id' => $id,
            'periodo' => '2024-01',
            'puntaje' => 85,
            'observaciones' => 'Buen desempeño general',
            'estado' => 'COMPLETADO'
        ];
        
        return view('evaluaciones.desempeno.edit', compact('item'));
    }

    public function promedios(): View
    {
        $data = [
            ['area' => 'Operación', 'promedio' => 85],
            ['area' => 'Seguridad', 'promedio' => 92],
            ['area' => 'Productividad', 'promedio' => 78],
        ];
        
        return view('evaluaciones.desempeno.promedios', compact('data'));
    }

    public function comparacion(): View
    {
        $data = [
            ['area' => 'Operación', 'promedio' => 85, 'meta' => 80],
            ['area' => 'Seguridad', 'promedio' => 92, 'meta' => 85],
            ['area' => 'Productividad', 'promedio' => 78, 'meta' => 80],
        ];
        
        return view('evaluaciones.desempeno.comparacion', compact('data'));
    }

    public function store(Request $request)
    {
        return redirect()->route('evaluaciones.desempeno.index')->with('success', 'Evaluación guardada');
    }

    public function update(Request $request, string $id)
    {
        return redirect()->route('evaluaciones.desempeno.show', $id)->with('success', 'Evaluación actualizada');
    }
}