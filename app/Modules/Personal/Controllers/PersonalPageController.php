<?php

namespace App\Modules\Personal\Controllers;

use App\Http\Controllers\WebPageController;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PersonalPageController extends WebPageController
{
    public function home(): View
    {
        return view('personal.home');
    }

    public function index(Request $request): View
    {
        $search = $request->get('search', '');
        $trabajadores = $this->getSampleWorkers($search, $request->all());
        
        return view('personal.index', compact('trabajadores'));
    }

    public function show(string $id): View
    {
        return view('personal.show', compact('id'));
    }

    public function create(): View
    {
        return view('personal.create');
    }

    public function store(Request $request): View
    {
        return redirect()->route('personal.index')->with('success', 'Trabajador creado correctamente');
    }

    public function edit(string $id): View
    {
        $workers = $this->getSampleWorkers('', []);
        $trabajador = collect($workers)->firstWhere('id', $id);
        
        if (!$trabajador) {
            abort(404);
        }
        
        return view('personal.edit', compact('trabajador'));
    }

    public function update(Request $request, string $id): View
    {
        return redirect()->route('personal.index')->with('success', 'Trabajador actualizado correctamente');
    }

    private function getSampleWorkers(string $search, array $filters): array
    {
        // Static demo data - no DB needed
        static $workers = null;
        
        if ($workers === null) {
            $workers = [
                [
                    'id' => '1', 
                    'nombre' => 'Carlos Alberto Mendoza Sánchez', 
                    'puesto' => 'Operador de Equipos Pesados', 
                    'dni' => '74856231', 
                    'telefono' => '951234567',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Mina 1', 'Mina 2'],
                    'minas_estado' => ['Mina 1' => 'habilitado', 'Mina 2' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2022-03-15',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '2', 
                    'nombre' => 'María Elena Quispe Flores', 
                    'puesto' => 'Supervisor de Turno', 
                    'dni' => '61245874', 
                    'telefono' => '952345678',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => true, 
                    'activo' => true, 
                    'minas' => ['Mina 1'],
                    'minas_estado' => ['Mina 1' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2021-08-01',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '3', 
                    'nombre' => 'Juan Pedro Huamán Torres', 
                    'puesto' => 'Técnico de Mantenimiento', 
                    'dni' => '89562341', 
                    'telefono' => '953456789',
                    'tipo_contrato' => 'Fijo', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Taller'],
                    'minas_estado' => ['Taller' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2023-01-10',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '4', 
                    'nombre' => 'Rosa Luz García Rivera', 
                    'puesto' => 'Asistente Administrativa', 
                    'dni' => '74589123', 
                    'telefono' => '954567890',
                    'tipo_contrato' => 'Régimen', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Oficina'],
                    'minas_estado' => ['Oficina' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2022-06-20',
                        'vacaciones' => ['inicio' => '2025-12-20', 'fin' => '2026-01-05'],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '5', 
                    'nombre' => 'Pedro Miguel Asto Yupanqui', 
                    'puesto' => 'Conductor de Camión', 
                    'dni' => '70125489', 
                    'telefono' => '955678901',
                    'tipo_contrato' => 'Intermitente', 
                    'supervisor' => false, 
                    'activo' => false, 
                    'minas' => ['Mina 3'],
                    'minas_estado' => ['Mina 3' => 'habilitado'],
                    'estado_actual' => 'inactivo',
                    'fechas' => [
                        'ingreso' => '2023-07-01',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '6', 
                    'nombre' => 'Luis Fernando Cóndor Huanca', 
                    'puesto' => 'Jefe de Seguridad', 
                    'dni' => '45678231', 
                    'telefono' => '956789012',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => true, 
                    'activo' => true, 
                    'minas' => ['Mina 1', 'Mina 2', 'Taller'],
                    'minas_estado' => ['Mina 1' => 'habilitado', 'Mina 2' => 'habilitado', 'Taller' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2020-02-15',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '7', 
                    'nombre' => 'Ana María Lucero Pérez', 
                    'puesto' => 'Enfermera Industrial', 
                    'dni' => '82365412', 
                    'telefono' => '957890123',
                    'tipo_contrato' => 'Fijo', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Mina 1'],
                    'minas_estado' => ['Mina 1' => 'habilitado'],
                    'estado_actual' => 'enfermo',
                    'fechas' => [
                        'ingreso' => '2022-09-01',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => '2025-04-10', 'fin' => '2025-04-20'],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '8', 
                    'nombre' => 'Roberto Carlos Mendoza', 
                    'puesto' => 'Mecánico', 
                    'dni' => '98745123', 
                    'telefono' => '958901234',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Taller'],
                    'minas_estado' => ['Taller' => 'habilitado'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2021-04-15',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '9', 
                    'nombre' => 'Sánchez Pablo Paredes', 
                    'puesto' => 'Geólogo', 
                    'dni' => '12345678', 
                    'telefono' => '959012345',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Mina 2'],
                    'minas_estado' => ['Mina 2' => 'proceso'],
                    'estado_actual' => 'trabajando',
                    'fechas' => [
                        'ingreso' => '2024-01-08',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '10', 
                    'nombre' => 'Diana Lucía Flores Mamani', 
                    'puesto' => 'Contadora', 
                    'dni' => '56782345', 
                    'telefono' => '950123456',
                    'tipo_contrato' => 'Régimen', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Oficina'],
                    'minas_estado' => ['Oficina' => 'habilitado'],
                    'estado_actual' => 'vacaciones',
                    'fechas' => [
                        'ingreso' => '2021-11-01',
                        'vacaciones' => ['inicio' => '2025-04-01', 'fin' => '2025-04-30'],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => null, 'fin' => null],
                    ]
                ],
                [
                    'id' => '11', 
                    'nombre' => 'Walter Hugo Luna Reyes', 
                    'puesto' => 'Operador de Camión', 
                    'dni' => '85236974', 
                    'telefono' => '951234578',
                    'tipo_contrato' => 'Indeterminado', 
                    'supervisor' => false, 
                    'activo' => true, 
                    'minas' => ['Mina 2', 'Mina 3'],
                    'minas_estado' => ['Mina 2' => 'habilitado', 'Mina 3' => 'habilitado'],
                    'estado_actual' => 'parada',
                    'fechas' => [
                        'ingreso' => '2023-05-20',
                        'vacaciones' => ['inicio' => null, 'fin' => null],
                        'enfermo' => ['inicio' => null, 'fin' => null],
                        'parada' => ['inicio' => '2025-04-14', 'fin' => '2025-04-18'],
                    ]
                ],
            ];
        }

        // Simple filter without complex string operations
        $filtered = $workers;
        
        if ($search) {
            $searchLower = strtolower($search);
            $filtered = array_filter($filtered, fn($w) => 
                str_contains(strtolower($w['nombre']), $searchLower) || 
                str_contains($w['dni'], $search)
            );
        }

        if (isset($filters['estado']) && $filters['estado']) {
            $isActive = $filters['estado'] === 'activo';
            $filtered = array_filter($filtered, fn($w) => $w['activo'] === $isActive);
        }

        if (isset($filters['tipo']) && $filters['tipo']) {
            $isSupervisor = $filters['tipo'] === 'supervisor';
            $filtered = array_filter($filtered, fn($w) => $w['supervisor'] === $isSupervisor);
        }

        return array_values($filtered);
    }

}
