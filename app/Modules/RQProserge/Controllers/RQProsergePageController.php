<?php

namespace App\Modules\RQProserge\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\Notificaciones\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RQProsergePageController extends WebPageController
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(): View
    {
        $data = $this->getDemoData();
        return view('rq-proserge.index', compact('data'));
    }

    public function show(string $id): View
    {
        $item = $this->getDemoItem($id);
        $disponibles = $this->getDemoDisponibles();
        return view('rq-proserge.show', compact('item', 'disponibles'));
    }

    public function create(): View
    {
        return view('rq-proserge.create');
    }

    public function edit(string $id): View
    {
        $item = $this->getDemoItem($id);
        return view('rq-proserge.edit', compact('item'));
    }

    public function store(Request $request)
    {
        return redirect()->route('rq-proserge.index')->with('success', 'RQ creado correctamente');
    }

    public function update(Request $request, string $id)
    {
        $estado = strtoupper((string) $request->input('estado', ''));

        if ($estado === 'PARCIAL') {
            $this->notificationService->emit('rq_proserge_parcial', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'rq_proserge',
                'entity_id' => $id,
                'title' => 'RQ Proserge parcialmente atendido',
                'message' => sprintf('El RQ Proserge %s quedo en estado parcial.', $id),
                'dedupe_key' => 'rq_proserge_parcial:' . $id . ':' . now()->format('YmdHi'),
            ]);
        }

        if (in_array($estado, ['COMPLETADO', 'ATENDIDO'], true)) {
            $this->notificationService->emit('rq_proserge_completado', [
                'actor_user_id' => session('user.id'),
                'entity_type' => 'rq_proserge',
                'entity_id' => $id,
                'title' => 'RQ Proserge completado',
                'message' => sprintf('El RQ Proserge %s fue completado.', $id),
                'dedupe_key' => 'rq_proserge_completado:' . $id,
            ]);
        }

        return redirect()->route('rq-proserge.show', $id)->with('success', 'RQ actualizado correctamente');
    }

    public function asignar(Request $request, string $id)
    {
        return back()->with('success', 'Personal asignado correctamente');
    }

    public function desasignar(string $id)
    {
        return back()->with('success', 'Personal desasignado correctamente');
    }

    private function getDemoData(): array
    {
        return [
            'data' => [
                [
                    'id' => '1',
                    'rq_mina_id' => '2',
                    'destino_tipo' => 'MINA',
                    'destino_nombre' => 'Boroo - Operacion Planta',
                    'personal_solicitado' => 5,
                    'personal_asignado' => 3,
                    'estado' => 'PENDIENTE',
                    'personal_parada' => [
                        ['nombre' => 'Carlos Mendoza', 'puesto' => 'Tecnico Electricista', 'cargo_parada' => 'Lider de cuadrilla'],
                        ['nombre' => 'Mario Quispe', 'puesto' => 'Auxiliar de mina', 'cargo_parada' => 'Apoyo en maniobras'],
                        ['nombre' => 'Luz Huamani', 'puesto' => 'Auxiliar de mina', 'cargo_parada' => 'Control de herramientas'],
                    ],
                ],
                [
                    'id' => '2',
                    'rq_mina_id' => '3',
                    'destino_tipo' => 'TALLER',
                    'destino_nombre' => 'Chinalco - Tienda',
                    'personal_solicitado' => 2,
                    'personal_asignado' => 2,
                    'estado' => 'ATENDIDO',
                    'personal_parada' => [
                        ['nombre' => 'Rosa Caceres', 'puesto' => 'Operador PC', 'cargo_parada' => 'Operador principal'],
                        ['nombre' => 'Jorge Tejada', 'puesto' => 'Operador PC', 'cargo_parada' => 'Operador de relevo'],
                    ],
                ],
                [
                    'id' => '3',
                    'rq_mina_id' => '4',
                    'destino_tipo' => 'OFICINA',
                    'destino_nombre' => 'Marcobre - Seccion Beta',
                    'personal_solicitado' => 1,
                    'personal_asignado' => 0,
                    'estado' => 'PENDIENTE',
                    'personal_parada' => [],
                ],
            ]
        ];
    }

    private function getDemoItem(string $id): ?array
    {
        $items = $this->getDemoData()['data'];
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return [
            'id' => $id,
            'rq_mina_id' => '-',
            'destino_tipo' => 'MINA',
            'destino_nombre' => 'Demo',
            'personal_solicitado' => 3,
            'personal_asignado' => 0,
            'estado' => 'PENDIENTE',
            'personal_parada' => [],
        ];
    }

    private function getDemoDisponibles(): array
    {
        return [
            ['id' => '1', 'nombre' => 'Carlos Mendoza', 'puesto' => 'Operador'],
            ['id' => '2', 'nombre' => 'María Quispe', 'puesto' => 'Técnico'],
        ];
    }
}
