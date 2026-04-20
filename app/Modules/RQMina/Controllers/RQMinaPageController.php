<?php

namespace App\Modules\RQMina\Controllers;

use App\Http\Controllers\WebPageController;
use App\Modules\RQMina\Services\RQMinaService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class RQMinaPageController extends WebPageController
{
    public function __construct(private readonly RQMinaService $service)
    {
    }

    public function index(Request $request): View
    {
        $filters = $request->only(['mina', 'estado', 'creador', 'fecha_desde', 'fecha_hasta']);
        $allItems = $this->getDemoData($request);

        $filtered = array_values(array_filter($allItems, function (array $item) use ($filters): bool {
            if (!empty($filters['mina']) && ($item['mina'] ?? '') !== $filters['mina']) {
                return false;
            }

            if (!empty($filters['estado']) && ($item['estado'] ?? '') !== $filters['estado']) {
                return false;
            }

            if (!empty($filters['creador']) && ($item['creador'] ?? '') !== $filters['creador']) {
                return false;
            }

            if (!empty($filters['fecha_desde']) && ($item['fecha_inicio'] ?? '') < $filters['fecha_desde']) {
                return false;
            }

            if (!empty($filters['fecha_hasta']) && ($item['fecha_fin'] ?? '') > $filters['fecha_hasta']) {
                return false;
            }

            return true;
        }));

        $data = [
            'items' => $filtered,
            'minaOptions' => $this->extractUniqueValues($allItems, 'mina'),
            'estadoOptions' => ['borrador', 'enviado'],
            'creadores' => $this->extractUniqueValues($allItems, 'creador'),
        ];
        
        return view('rq-mina.index', compact('data'));
    }

    public function show(Request $request, string $id): View
    {
        $items = $this->getDemoData($request);
        $item = null;
        foreach ($items as $rqItem) {
            if (($rqItem['id'] ?? '') === $id) {
                $item = $rqItem;
                break;
            }
        }

        if (!$item) {
            $item = [
                'id' => $id,
                'mina' => '-',
                'area' => '-',
                'fecha_inicio' => null,
                'fecha_fin' => null,
                'creador' => '-',
                'estado' => 'borrador',
                'detalle' => [],
                'observaciones' => null,
            ];
        }

        $item['personal_parada'] = $this->getPersonalParadaForRQMina($id);

        return view('rq-mina.show', compact('item'));
    }

    public function create(Request $request): View
    {
        $items = $this->getDemoData($request);
        $minas = $this->extractUniqueValues($items, 'mina');
        $copyFrom = (string) $request->query('copy_from', '');
        $copyData = null;

        if ($copyFrom !== '') {
            foreach ($items as $item) {
                if (($item['id'] ?? '') === $copyFrom) {
                    $copyData = $item;
                    break;
                }
            }
        }

        $formMode = 'create';
        $formAction = route('rq-mina.store');
        $formMethod = 'POST';
        $submitLabel = 'Guardar como Borrador';

        return view('rq-mina.create', compact('minas', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function edit(Request $request, string $id): View
    {
        $items = $this->getDemoData($request);
        $item = null;
        foreach ($items as $rqItem) {
            if (($rqItem['id'] ?? '') === $id) {
                $item = $rqItem;
                break;
            }
        }

        if (!$item) {
            $item = [
                'id' => $id,
                'mina' => '',
                'area' => '',
                'fecha_inicio' => '',
                'fecha_fin' => '',
                'observaciones' => '',
                'detalle' => [['puesto' => '', 'cantidad' => 1]],
            ];
        }

        $minas = $this->extractUniqueValues($items, 'mina');
        $copyData = $item;
        $formMode = 'edit';
        $formAction = route('rq-mina.update', $id);
        $formMethod = 'PUT';
        $submitLabel = 'Guardar Cambios';

        return view('rq-mina.create', compact('minas', 'copyData', 'formMode', 'formAction', 'formMethod', 'submitLabel'));
    }

    public function store(Request $request)
    {
        return redirect()->route('rq-mina.index')->with('success', 'RQ creado correctamente');
    }

    public function update(Request $request, string $id)
    {
        return redirect()->route('rq-mina.show', $id)->with('success', 'RQ actualizado correctamente');
    }

    public function enviar(Request $request, string $id): RedirectResponse
    {
        $items = $this->getDemoData($request);
        $updated = false;

        foreach ($items as &$item) {
            if (($item['id'] ?? '') !== $id) {
                continue;
            }

            if (($item['estado'] ?? '') !== 'borrador') {
                return redirect()
                    ->route('rq-mina.index')
                    ->with('error', 'Solo se puede enviar un RQ en estado borrador.');
            }

            $item['estado'] = 'enviado';
            $updated = true;
            break;
        }

        if (!$updated) {
            return redirect()->route('rq-mina.index')->with('error', 'RQ no encontrado.');
        }

        $request->session()->put('rq_mina_demo_data', $items);

        return redirect()->route('rq-mina.index')->with('success', 'RQ enviado correctamente.');
    }

    private function getDemoData(Request $request): array
    {
        $sessionItems = $request->session()->get('rq_mina_demo_data');
        if (is_array($sessionItems)) {
            foreach ($sessionItems as &$item) {
                if (($item['estado'] ?? '') !== 'borrador' && ($item['estado'] ?? '') !== 'enviado') {
                    $item['estado'] = 'enviado';
                }
            }
            return $sessionItems;
        }

        $items = [
            [
                'id' => '1',
                'mina' => 'Cerro Verde',
                'area' => 'C2',
                'fecha_inicio' => '2026-04-20',
                'fecha_fin' => '2026-04-30',
                'creador' => 'Juan Pérez',
                'estado' => 'borrador',
                'detalle' => [
                    ['puesto' => 'Mecánico', 'cantidad' => 2],
                    ['puesto' => 'Operador', 'cantidad' => 3],
                ],
                'observaciones' => 'Pedido de camiones para mantenimiento',
            ],
            [
                'id' => '2',
                'mina' => 'Boroo',
                'area' => 'Operación Planta',
                'fecha_inicio' => '2026-04-15',
                'fecha_fin' => '2026-04-25',
                'creador' => 'María García',
                'estado' => 'enviado',
                'detalle' => [
                    ['puesto' => 'Técnico Electricista', 'cantidad' => 1],
                    ['puesto' => 'Auxiliar de mina', 'cantidad' => 4],
                ],
                'observaciones' => 'Reemplazo por vacaciones',
            ],
            [
                'id' => '3',
                'mina' => 'Chinalco',
                'area' => 'Tienda',
                'fecha_inicio' => '2026-04-10',
                'fecha_fin' => '2026-04-20',
                'creador' => 'Luis Cóndor',
                'estado' => 'enviado',
                'detalle' => [
                    ['puesto' => 'Operador PC', 'cantidad' => 2],
                ],
                'observaciones' => 'Solicitud urgente',
            ],
            [
                'id' => '4',
                'mina' => 'Marcobre',
                'area' => 'Sección Beta',
                'fecha_inicio' => '2026-04-22',
                'fecha_fin' => '2026-05-02',
                'creador' => 'Ana Torres',
                'estado' => 'borrador',
                'detalle' => [
                    ['puesto' => 'Geólogo', 'cantidad' => 1],
                ],
                'observaciones' => 'Sin presupuesto disponible',
            ],
        ];

        $request->session()->put('rq_mina_demo_data', $items);

        return $items;
    }

    private function getDemoItem(string $id): ?array
    {
        $items = [
            ['id' => '1', 'fecha_inicio' => '2026-04-10', 'mina' => ['nombre' => 'Mina 1'], 'area' => 'Explosivos', 'estado' => 'PENDIENTE'],
            ['id' => '2', 'fecha_inicio' => '2026-04-11', 'mina' => ['nombre' => 'Mina 2'], 'area' => 'Transporte', 'estado' => 'ATENDIDO'],
            ['id' => '3', 'fecha_inicio' => '2026-04-12', 'mina' => ['nombre' => 'Mina 1'], 'area' => 'Mantenimiento', 'estado' => 'CERRADO'],
        ];

        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }
        return ['id' => $id, 'fecha_inicio' => '2026-04-10', 'mina' => ['nombre' => 'Mina 1'], 'area' => 'Demo', 'estado' => 'PENDIENTE'];
    }

    private function getPersonalParadaForRQMina(string $rqMinaId): array
    {
        $rqProserge = [
            [
                'id' => 'RP-101',
                'rq_mina_id' => '2',
                'personal_parada' => [
                    ['nombre' => 'Carlos Mendoza', 'puesto' => 'Tecnico Electricista', 'cargo_parada' => 'Lider de cuadrilla'],
                    ['nombre' => 'Mario Quispe', 'puesto' => 'Auxiliar de mina', 'cargo_parada' => 'Apoyo en maniobras'],
                    ['nombre' => 'Luz Huamani', 'puesto' => 'Auxiliar de mina', 'cargo_parada' => 'Control de herramientas'],
                ],
            ],
            [
                'id' => 'RP-102',
                'rq_mina_id' => '3',
                'personal_parada' => [
                    ['nombre' => 'Rosa Caceres', 'puesto' => 'Operador PC', 'cargo_parada' => 'Operador principal'],
                    ['nombre' => 'Jorge Tejada', 'puesto' => 'Operador PC', 'cargo_parada' => 'Operador de relevo'],
                ],
            ],
        ];

        foreach ($rqProserge as $registro) {
            if (($registro['rq_mina_id'] ?? '') === $rqMinaId) {
                return $registro['personal_parada'] ?? [];
            }
        }

        return [];
    }

    private function extractUniqueValues(array $items, string $field): array
    {
        $values = [];
        foreach ($items as $item) {
            if (!empty($item[$field])) {
                $values[] = $item[$field];
            }
        }

        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }
}