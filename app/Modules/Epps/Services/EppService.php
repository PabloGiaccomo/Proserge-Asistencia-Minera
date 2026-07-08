<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\EppRegistro;
use App\Models\Personal;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class EppService
{
    public function pageData(array $filters = []): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 50, 100], true) ? $perPage : 20;

        $catalogo = EppRegistro::query()
            ->orderBy('nombre')
            ->get();

        $entregas = EppEntrega::query()
            ->with([
                'personal:id,nombre_completo,dni,numero_documento,puesto,estado',
                'epp:id,codigo,nombre,categoria,vida_util_dias,estado',
                'registradoPor:id,email,personal_id',
                'registradoPor.personal:id,nombre_completo',
                'cerradoPor:id,email,personal_id',
                'cerradoPor.personal:id,nombre_completo',
            ])
            ->when($estado !== '', fn ($query) => $query->where('estado', $estado))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($subQuery) use ($search): void {
                    $subQuery
                        ->whereHas('personal', function ($personalQuery) use ($search): void {
                            $personalQuery
                                ->where('nombre_completo', 'like', "%{$search}%")
                                ->orWhere('dni', 'like', "%{$search}%")
                                ->orWhere('numero_documento', 'like', "%{$search}%")
                                ->orWhere('puesto', 'like', "%{$search}%");
                        })
                        ->orWhereHas('epp', function ($eppQuery) use ($search): void {
                            $eppQuery
                                ->where('nombre', 'like', "%{$search}%")
                                ->orWhere('codigo', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByRaw("FIELD(estado, 'ENTREGADO', 'CAMBIADO', 'DEVUELTO')")
            ->latest('fecha_entrega')
            ->paginate($perPage)
            ->withQueryString();

        /** @var LengthAwarePaginator $entregas */
        $entregas->getCollection()->transform(
            fn (EppEntrega $entrega): array => $this->presentEntrega($entrega)
        );

        return [
            'catalogo' => $catalogo,
            'eppsActivos' => $catalogo->where('estado', EppRegistro::ESTADO_ACTIVO)->values(),
            'entregas' => $entregas,
            'filters' => [
                'q' => $search,
                'estado' => $estado,
                'per_page' => $perPage,
            ],
            'estadosEntrega' => [
                EppEntrega::ESTADO_ENTREGADO,
                EppEntrega::ESTADO_CAMBIADO,
                EppEntrega::ESTADO_DEVUELTO,
            ],
        ];
    }

    public function searchPersonal(string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return Personal::query()
            ->select(['id', 'nombre_completo', 'dni', 'numero_documento', 'puesto', 'estado'])
            ->where(function ($personalQuery) use ($query): void {
                $personalQuery
                    ->where('nombre_completo', 'like', "%{$query}%")
                    ->orWhere('dni', 'like', "%{$query}%")
                    ->orWhere('numero_documento', 'like', "%{$query}%")
                    ->orWhere('puesto', 'like', "%{$query}%");
            })
            ->orderBy('nombre_completo')
            ->limit(15)
            ->get()
            ->map(fn (Personal $personal): array => [
                'id' => (string) $personal->id,
                'nombre' => (string) $personal->nombre_completo,
                'documento' => $this->documentoPersonal($personal),
                'puesto' => (string) ($personal->puesto ?: 'Sin puesto'),
                'estado' => (string) ($personal->estado ?: 'Sin estado'),
                'label' => trim(sprintf(
                    '%s - %s%s',
                    (string) $personal->nombre_completo,
                    $this->documentoPersonal($personal),
                    $personal->puesto ? ' - '.$personal->puesto : ''
                )),
            ])
            ->all();
    }

    public function lastDeliverySummary(string $personalId, string $eppId): ?array
    {
        $personalId = trim($personalId);
        $eppId = trim($eppId);

        if ($personalId === '' || $eppId === '') {
            return null;
        }

        $entrega = EppEntrega::query()
            ->with([
                'personal:id,nombre_completo,dni,numero_documento,puesto',
                'epp:id,codigo,nombre,vida_util_dias',
                'registradoPor:id,email,personal_id',
                'registradoPor.personal:id,nombre_completo',
                'cerradoPor:id,email,personal_id',
                'cerradoPor.personal:id,nombre_completo',
            ])
            ->where('personal_id', $personalId)
            ->where('epp_id', $eppId)
            ->orderByDesc('fecha_entrega')
            ->orderByDesc('created_at')
            ->first();

        if (!$entrega) {
            return null;
        }

        $presented = $this->presentEntrega($entrega);

        return [
            'id' => (string) $entrega->id,
            'trabajador' => (string) ($entrega->personal?->nombre_completo ?: 'Trabajador no encontrado'),
            'documento' => $entrega->personal ? $this->documentoPersonal($entrega->personal) : '-',
            'puesto' => (string) ($entrega->personal?->puesto ?: 'Sin puesto'),
            'epp' => (string) ($entrega->epp?->nombre ?: 'EPP no encontrado'),
            'codigo' => (string) ($entrega->epp?->codigo ?: '-'),
            'cantidad' => (int) $entrega->cantidad,
            'estado' => (string) $entrega->estado,
            'fecha_entrega' => $entrega->fecha_entrega ? $entrega->fecha_entrega->format('d/m/Y') : '-',
            'fecha_vencimiento_calendario' => $entrega->fecha_vencimiento_calendario ? $entrega->fecha_vencimiento_calendario->format('d/m/Y') : '-',
            'fecha_cierre' => $entrega->devuelto_at ? $entrega->devuelto_at->format('d/m/Y') : null,
            'vida_dias' => (int) $presented['vida_dias'],
            'dias_uso_efectivo' => (int) $presented['dias_uso_efectivo'],
            'dias_restantes_uso' => (int) $presented['dias_restantes_uso'],
            'uso_porcentaje' => (int) $presented['uso_porcentaje'],
            'periodos_uso' => collect($presented['periodos_uso'])
                ->take(3)
                ->map(fn (array $periodo): array => [
                    'parada' => $periodo['parada'],
                    'desde' => Carbon::parse($periodo['desde'])->format('d/m/Y'),
                    'hasta' => Carbon::parse($periodo['hasta'])->format('d/m/Y'),
                    'dias' => (int) $periodo['dias'],
                ])
                ->values()
                ->all(),
            'observacion' => (string) ($entrega->observacion ?: ''),
            'motivo_cambio' => (string) ($entrega->motivo_cambio ?: ''),
            'registrado_por' => $this->usuarioLabel($entrega->registradoPor),
            'cerrado_por' => $this->usuarioLabel($entrega->cerradoPor),
        ];
    }

    public function storeCatalog(array $payload): EppRegistro
    {
        $data = $this->normalizeCatalogPayload($payload);

        return DB::transaction(function () use ($data): EppRegistro {
            $epp = EppRegistro::query()->firstOrNew(['codigo' => $data['codigo']]);

            if (!$epp->exists) {
                $epp->id = (string) Str::uuid();
            }

            return $this->saveCatalog($epp, $data);
        });
    }

    public function updateCatalog(string $id, array $payload): EppRegistro
    {
        $data = $this->normalizeCatalogPayload($payload);

        return DB::transaction(function () use ($id, $data): EppRegistro {
            $epp = EppRegistro::query()->findOrFail($id);
            $duplicado = EppRegistro::query()
                ->where('codigo', $data['codigo'])
                ->where('id', '!=', $epp->id)
                ->exists();

            if ($duplicado) {
                throw new InvalidArgumentException('Ya existe otro EPP con ese nombre.');
            }

            return $this->saveCatalog($epp, $data);
        });
    }

    public function deliver(array $payload, ?Usuario $usuario): EppEntrega
    {
        $personal = Personal::query()->findOrFail((string) ($payload['personal_id'] ?? ''));
        $epp = EppRegistro::query()->findOrFail((string) ($payload['epp_id'] ?? ''));
        $fechaEntrega = Carbon::parse((string) ($payload['fecha_entrega'] ?? now()->toDateString()))->startOfDay();
        $vidaDias = max(1, (int) ($epp->vida_util_dias ?: ($payload['vida_util_dias'] ?? 1)));

        return DB::transaction(function () use ($payload, $personal, $epp, $fechaEntrega, $vidaDias, $usuario): EppEntrega {
            return EppEntrega::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'epp_id' => $epp->id,
                'cantidad' => max(1, (int) ($payload['cantidad'] ?? 1)),
                'fecha_entrega' => $fechaEntrega->toDateString(),
                'fecha_vencimiento_calendario' => $fechaEntrega->copy()->addDays($vidaDias)->toDateString(),
                'vida_util_dias_snapshot' => $vidaDias,
                'estado' => EppEntrega::ESTADO_ENTREGADO,
                'observacion' => trim((string) ($payload['observacion'] ?? '')) ?: null,
                'registrado_por_usuario_id' => $usuario?->id,
            ]);
        });
    }

    public function closeEntrega(string $id, array $payload, ?Usuario $usuario): EppEntrega
    {
        $entrega = EppEntrega::query()->findOrFail($id);

        if ($entrega->estado !== EppEntrega::ESTADO_ENTREGADO) {
            return $entrega;
        }

        $estado = strtoupper(trim((string) ($payload['estado'] ?? EppEntrega::ESTADO_DEVUELTO)));
        if (!in_array($estado, [EppEntrega::ESTADO_CAMBIADO, EppEntrega::ESTADO_DEVUELTO], true)) {
            $estado = EppEntrega::ESTADO_DEVUELTO;
        }

        $fecha = Carbon::parse((string) ($payload['devuelto_at'] ?? now()->toDateString()))->startOfDay();

        $entrega->forceFill([
            'estado' => $estado,
            'motivo_cambio' => trim((string) ($payload['motivo_cambio'] ?? '')) ?: ($estado === EppEntrega::ESTADO_CAMBIADO ? 'Cambio de EPP' : 'Entrega de EPP'),
            'observacion' => trim((string) ($payload['observacion'] ?? $entrega->observacion ?? '')) ?: null,
            'devuelto_at' => $fecha->toDateString(),
            'cerrado_por_usuario_id' => $usuario?->id,
        ])->save();

        return $entrega->fresh(['personal', 'epp']);
    }

    public function presentEntrega(EppEntrega $entrega): array
    {
        $usage = $this->calculateUsage($entrega);
        $vidaDias = (int) ($entrega->vida_util_dias_snapshot ?: $entrega->epp?->vida_util_dias ?: 0);
        $fechaCierre = $entrega->devuelto_at ? Carbon::parse($entrega->devuelto_at)->format('d/m/Y') : null;
        $movimientoCierre = match ($entrega->estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto',
            default => null,
        };

        return [
            'model' => $entrega,
            'personal' => $entrega->personal,
            'epp' => $entrega->epp,
            'documento' => $entrega->personal ? $this->documentoPersonal($entrega->personal) : '-',
            'vida_dias' => $vidaDias,
            'dias_uso_efectivo' => $usage['dias'],
            'dias_restantes_uso' => max(0, $vidaDias - $usage['dias']),
            'periodos_uso' => $usage['periodos'],
            'uso_porcentaje' => $vidaDias > 0 ? min(100, (int) round(($usage['dias'] / $vidaDias) * 100)) : 0,
            'fecha_cierre' => $fechaCierre,
            'movimiento_cierre' => $movimientoCierre,
            'cerrado_por' => $this->usuarioLabel($entrega->cerradoPor),
        ];
    }

    private function calculateUsage(EppEntrega $entrega): array
    {
        if (!$entrega->personal_id || !$entrega->fecha_entrega) {
            return ['dias' => 0, 'periodos' => []];
        }

        $inicioUso = $entrega->fecha_entrega instanceof Carbon
            ? $entrega->fecha_entrega->copy()->startOfDay()
            : Carbon::parse($entrega->fecha_entrega)->startOfDay();

        $finUso = $entrega->devuelto_at
            ? Carbon::parse($entrega->devuelto_at)->startOfDay()
            : now()->startOfDay();

        if ($finUso->lt($inicioUso)) {
            return ['dias' => 0, 'periodos' => []];
        }

        $detalles = RQProsergeDetalle::query()
            ->with(['rqProserge.mina:id,nombre', 'rqProserge.rqMina:id,mina_id,destino_nombre,area,fecha_inicio,fecha_fin'])
            ->where('personal_id', $entrega->personal_id)
            ->get();

        $periodos = [];

        foreach ($detalles as $detalle) {
            $range = $this->assignmentRange($detalle);
            if (!$range) {
                continue;
            }

            [$inicio, $fin] = $range;

            if ($fin->lt($inicioUso) || $inicio->gt($finUso)) {
                continue;
            }

            $desde = $inicio->greaterThan($inicioUso) ? $inicio : $inicioUso->copy();
            $hasta = $fin->lessThan($finUso) ? $fin : $finUso->copy();
            $dias = (int) $desde->diffInDays($hasta) + 1;

            if ($dias <= 0) {
                continue;
            }

            $rqMina = $detalle->rqProserge?->rqMina;
            $minaNombre = $detalle->rqProserge?->mina?->nombre;

            $periodos[] = [
                'desde' => $desde->toDateString(),
                'hasta' => $hasta->toDateString(),
                'dias' => $dias,
                'parada' => trim((string) ($rqMina?->destino_nombre ?: $rqMina?->area ?: $minaNombre ?: 'Parada')),
                'key' => implode('|', [
                    (string) ($detalle->rq_proserge_id ?? ''),
                    (string) ($rqMina?->id ?? ''),
                    $desde->toDateString(),
                    $hasta->toDateString(),
                ]),
            ];
        }

        $periodos = collect($periodos)
            ->unique('key')
            ->sortBy('desde')
            ->values()
            ->all();

        return [
            'dias' => $this->sumMergedDays($periodos),
            'periodos' => $periodos,
        ];
    }

    private function assignmentRange(RQProsergeDetalle $detalle): ?array
    {
        $rqMina = $detalle->rqProserge?->rqMina;
        $inicio = $detalle->fecha_inicio ?: $rqMina?->fecha_inicio;
        $fin = $detalle->fecha_fin ?: $rqMina?->fecha_fin ?: $inicio;

        if (!$inicio || !$fin) {
            return null;
        }

        return [
            Carbon::parse($inicio)->startOfDay(),
            Carbon::parse($fin)->startOfDay(),
        ];
    }

    private function sumMergedDays(array $periodos): int
    {
        if ($periodos === []) {
            return 0;
        }

        $intervals = collect($periodos)
            ->map(fn (array $periodo): array => [
                'desde' => Carbon::parse($periodo['desde'])->startOfDay(),
                'hasta' => Carbon::parse($periodo['hasta'])->startOfDay(),
            ])
            ->sortBy(fn (array $periodo): int => $periodo['desde']->timestamp)
            ->values();

        $merged = [];
        foreach ($intervals as $interval) {
            if ($merged === []) {
                $merged[] = $interval;
                continue;
            }

            $lastIndex = count($merged) - 1;
            if ($interval['desde']->lte($merged[$lastIndex]['hasta']->copy()->addDay())) {
                if ($interval['hasta']->gt($merged[$lastIndex]['hasta'])) {
                    $merged[$lastIndex]['hasta'] = $interval['hasta'];
                }
                continue;
            }

            $merged[] = $interval;
        }

        return collect($merged)->sum(fn (array $interval): int => (int) $interval['desde']->diffInDays($interval['hasta']) + 1);
    }

    private function codigoFromNombre(string $nombre): string
    {
        $codigo = Str::of($nombre)->ascii()->slug('_')->upper()->limit(110, '')->toString();

        return $codigo !== '' ? $codigo : 'EPP_'.Str::upper(Str::random(8));
    }

    private function normalizeCatalogPayload(array $payload): array
    {
        $nombre = mb_strtoupper(trim((string) ($payload['nombre'] ?? '')), 'UTF-8');

        if ($nombre === '') {
            throw new InvalidArgumentException('Indica el nombre del EPP.');
        }

        $estado = strtoupper((string) ($payload['estado'] ?? EppRegistro::ESTADO_ACTIVO));
        if (!in_array($estado, [EppRegistro::ESTADO_ACTIVO, EppRegistro::ESTADO_INACTIVO], true)) {
            $estado = EppRegistro::ESTADO_ACTIVO;
        }

        $requiereTalla = (bool) ($payload['requiere_talla'] ?? false);
        $requiereColor = (bool) ($payload['requiere_color'] ?? false);

        return [
            'codigo' => $this->codigoFromNombre($nombre),
            'nombre' => $nombre,
            'categoria' => 'EPP',
            'stock' => 0,
            'vida_util_dias' => max(1, (int) ($payload['vida_util_dias'] ?? 1)),
            'requiere_talla' => $requiereTalla,
            'tallas' => $requiereTalla ? $this->normalizeOptions((string) ($payload['tallas'] ?? '')) : null,
            'requiere_color' => $requiereColor,
            'colores' => $requiereColor ? $this->normalizeOptions((string) ($payload['colores'] ?? '')) : null,
            'estado' => $estado,
        ];
    }

    private function saveCatalog(EppRegistro $epp, array $data): EppRegistro
    {
        $epp->forceFill($data)->save();

        return $epp->refresh();
    }

    private function normalizeOptions(string $value): array
    {
        return collect(preg_split('/[\r\n,;]+/', $value) ?: [])
            ->map(fn (string $item): string => mb_strtoupper(trim($item), 'UTF-8'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function documentoPersonal(Personal $personal): string
    {
        return (string) ($personal->numero_documento ?: $personal->dni ?: '-');
    }

    private function usuarioLabel(?Usuario $usuario): string
    {
        if (!$usuario) {
            return 'No registrado';
        }

        return (string) ($usuario->personal?->nombre_completo ?: $usuario->email ?: 'No registrado');
    }
}
