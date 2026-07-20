<?php

namespace App\Modules\Epps\Services;

use App\Models\EppEntrega;
use App\Models\EppRegistro;
use App\Models\Mina;
use App\Models\Personal;
use App\Models\RQProsergeDetalle;
use App\Models\Usuario;
use App\Modules\Personal\Resources\PersonalIndexResource;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EppService
{
    private const KARDEX_MAX_ITEMS_PER_SHEET = 28;

    private const KARDEX_TEMPLATE_ORDER = [
        ['side' => 'anterior', 'label' => 'Casco', 'keywords' => ['CASCO']],
        ['side' => 'anterior', 'label' => 'Chaleco', 'keywords' => ['CHALECO']],
        ['side' => 'anterior', 'label' => 'Pantalon drill', 'keywords' => ['PANTALON DRILL', 'PANTALON']],
        ['side' => 'anterior', 'label' => 'Camisa dril', 'keywords' => ['CAMISA DRIL', 'CAMISA']],
        ['side' => 'anterior', 'label' => 'Zapato de seguridad dielectrico', 'keywords' => ['DIELECTRICO']],
        ['side' => 'anterior', 'label' => 'Zapatos de seguridad', 'keywords' => ['ZAPATO']],
        ['side' => 'anterior', 'label' => 'Respirador de silicona', 'keywords' => ['RESPIRADOR']],
        ['side' => 'anterior', 'label' => 'Chompa de lana', 'keywords' => ['CHOMPA']],
        ['side' => 'anterior', 'label' => 'Pantalon de lana', 'keywords' => ['LANA']],
        ['side' => 'anterior', 'label' => 'Candado de bloqueo', 'keywords' => ['CANDADO']],
        ['side' => 'anterior', 'label' => 'Cortaviento termico', 'keywords' => ['CORTAVIENTO']],
        ['side' => 'anterior', 'label' => 'Botas de soldador', 'keywords' => ['BOTA SOLDADOR', 'BOTAS SOLDADOR']],
        ['side' => 'anterior', 'label' => 'Camisa de supervisor', 'keywords' => ['SUPERVISOR']],
        ['side' => 'anterior', 'label' => 'Capotin para lluvia', 'keywords' => ['CAPOTIN']],
        ['side' => 'anterior', 'label' => 'Casaca termica', 'keywords' => ['CASACA']],
        ['side' => 'anterior', 'label' => 'Pantalon termico', 'keywords' => ['TERMICO']],
        ['side' => 'anterior', 'label' => 'Botas de agua', 'keywords' => ['BOTA AGUA', 'BOTAS AGUA']],
        ['side' => 'anterior', 'label' => 'Camisa de soldador', 'keywords' => ['CAMISA SOLDADOR']],
        ['side' => 'anterior', 'label' => 'Pantalon de soldador', 'keywords' => ['PANTALON SOLDADOR']],
        ['side' => 'anterior', 'label' => 'Uniforme antiacido', 'keywords' => ['ANTIACIDO']],
        ['side' => 'posterior', 'label' => 'Lentes claros', 'keywords' => ['LENTE CLARO', 'LENTES CLAROS']],
        ['side' => 'posterior', 'label' => 'Lentes oscuros', 'keywords' => ['LENTE OSCURO', 'LENTES OSCUROS']],
        ['side' => 'posterior', 'label' => 'Sobrelentes claros', 'keywords' => ['SOBRELENTE CLARO']],
        ['side' => 'posterior', 'label' => 'Sobrelentes oscuros', 'keywords' => ['SOBRELENTE OSCURO']],
        ['side' => 'posterior', 'label' => 'Tapones auditivos', 'keywords' => ['TAPON', 'TAPONES']],
        ['side' => 'posterior', 'label' => 'Mascarilla N95', 'keywords' => ['N95', 'MASCARILLA']],
        ['side' => 'posterior', 'label' => 'Filtro 2097', 'keywords' => ['FILTRO 2097']],
        ['side' => 'posterior', 'label' => 'Cartucho 60923', 'keywords' => ['CARTUCHO']],
        ['side' => 'posterior', 'label' => 'Barbiquejo', 'keywords' => ['BARBIQUEJO']],
        ['side' => 'posterior', 'label' => 'Tafilete', 'keywords' => ['TAFILETE']],
        ['side' => 'posterior', 'label' => 'Guantes de badana', 'keywords' => ['BADANA']],
        ['side' => 'posterior', 'label' => 'Guantes antigolpe', 'keywords' => ['ANTIGOLPE']],
        ['side' => 'posterior', 'label' => 'Orejeras', 'keywords' => ['OREJERA']],
        ['side' => 'posterior', 'label' => 'Guantes multiflex', 'keywords' => ['MULTIFLEX']],
        ['side' => 'posterior', 'label' => 'Guantes cut5', 'keywords' => ['CUT5']],
        ['side' => 'posterior', 'label' => 'Guantes showa', 'keywords' => ['SHOWA']],
        ['side' => 'posterior', 'label' => 'Guantes dielectricos', 'keywords' => ['GUANTE DIELECTRICO', 'GUANTES DIELECTRICOS']],
    ];

    public function pageData(array $filters = []): array
    {
        $search = trim((string) ($filters['q'] ?? ''));
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $minaId = trim((string) ($filters['mina_id'] ?? ''));
        $eppId = trim((string) ($filters['epp_id'] ?? ''));
        $tipoMovimiento = strtoupper(trim((string) ($filters['tipo_movimiento'] ?? '')));
        $fechaDesde = $this->normalizeDateFilter($filters['fecha_desde'] ?? null);
        $fechaHasta = $this->normalizeDateFilter($filters['fecha_hasta'] ?? null);
        $perPageOptions = [10, 25, 50, 100];
        $perPage = (int) ($filters['per_page'] ?? 10);
        $perPage = in_array($perPage, $perPageOptions, true) ? $perPage : 10;

        $catalogo = EppRegistro::query()
            ->orderBy('nombre')
            ->get();
        $minas = Mina::query()
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        $entregas = EppEntrega::query()
            ->with([
                'personal:id,nombre_completo,dni,numero_documento,puesto,estado',
                'personal.relacionesMina:id,personal_id,mina_id,activo',
                'personal.relacionesMina.mina:id,nombre',
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
                                ->orWhere('puesto', 'like', "%{$search}%")
                                ->orWhere('id', $search);
                        })
                        ->orWhereHas('epp', function ($eppQuery) use ($search): void {
                            $eppQuery
                                ->where('nombre', 'like', "%{$search}%")
                                ->orWhere('codigo', 'like', "%{$search}%");
                    });
                });
            })
            ->when($minaId !== '', function ($query) use ($minaId): void {
                $query->whereHas('personal.relacionesMina', function ($relation) use ($minaId): void {
                    $relation
                        ->where('mina_id', $minaId)
                        ->where(function ($active): void {
                            $active->where('activo', true)->orWhereNull('activo');
                        });
                });
            })
            ->when($eppId !== '', fn ($query) => $query->where('epp_id', $eppId))
            ->when($tipoMovimiento !== '', fn ($query) => $this->applyMovementTypeFilter($query, $tipoMovimiento))
            ->when($fechaDesde, fn ($query) => $query->whereDate(DB::raw('COALESCE(devuelto_at, fecha_entrega)'), '>=', $fechaDesde))
            ->when($fechaHasta, fn ($query) => $query->whereDate(DB::raw('COALESCE(devuelto_at, fecha_entrega)'), '<=', $fechaHasta))
            ->orderByRaw("FIELD(estado, 'ENTREGADO', 'CAMBIADO', 'USO_INCORRECTO', 'PERDIDA_OLVIDO', 'DEVUELTO')")
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
            'minas' => $minas,
            'entregas' => $entregas,
            'filters' => [
                'q' => $search,
                'estado' => $estado,
                'mina_id' => $minaId,
                'epp_id' => $eppId,
                'tipo_movimiento' => $tipoMovimiento,
                'fecha_desde' => $fechaDesde ?? '',
                'fecha_hasta' => $fechaHasta ?? '',
                'per_page' => $perPage,
            ],
            'perPageOptions' => $perPageOptions,
            'estadosEntrega' => [
                EppEntrega::ESTADO_ENTREGADO,
                EppEntrega::ESTADO_CAMBIADO,
                EppEntrega::ESTADO_USO_INCORRECTO,
                EppEntrega::ESTADO_PERDIDA_OLVIDO,
                EppEntrega::ESTADO_DEVUELTO,
            ],
            'tiposMovimiento' => [
                'ENTREGA' => 'Entrega',
                'CAMBIO' => 'Cambio',
                'USO_INCORRECTO' => 'Uso incorrecto',
                'PERDIDA_OLVIDO' => 'Perdida / olvido',
                'DEVOLUCION' => 'Devuelto por internamiento',
                'RENOVACION' => 'Renovacion',
            ],
        ];
    }

    public function searchPersonal(string $query, int $limit = 15): array
    {
        $query = trim($query);

        if (mb_strlen($query) < 2) {
            return [];
        }

        $terms = collect(preg_split('/\s+/', $query) ?: [])
            ->map(fn (string $term): string => trim($term))
            ->filter(fn (string $term): bool => $term !== '')
            ->take(6)
            ->values();

        if ($terms->isEmpty()) {
            return [];
        }

        $limit = max(5, min(25, $limit));
        $exactId = $terms->count() === 1 ? $terms->first() : null;

        return Personal::query()
            ->with([
                'puestoCatalogo:id,nombre',
                'contratoDatos',
                'contratosLaborales',
            ])
            ->where(function ($queryBuilder) use ($terms, $exactId): void {
                foreach ($terms as $term) {
                    $variants = $this->searchTermVariants($term);

                    $queryBuilder->where(function ($termQuery) use ($variants, $exactId): void {
                        foreach ($variants as $variant) {
                            $like = $this->likeTerm($variant);

                            $termQuery
                                ->orWhere('nombre_completo', 'like', $like)
                                ->orWhere('dni', 'like', $like)
                                ->orWhere('numero_documento', 'like', $like)
                                ->orWhere('puesto', 'like', $like)
                                ->orWhereHas('puestoCatalogo', function ($puestoQuery) use ($like): void {
                                    $puestoQuery->where('nombre', 'like', $like);
                                });
                        }

                        if ($exactId !== null) {
                            $termQuery->orWhere('id', $exactId);
                        }
                    });
                }
            })
            ->orderBy('nombre_completo')
            ->limit($limit)
            ->get()
            ->map(function (Personal $personal): array {
                $row = (new PersonalIndexResource($personal))->resolve();
                $puesto = (string) ($row['puesto'] ?? $personal->puesto ?: $personal->puestoCatalogo?->nombre ?: 'Sin puesto');
                $estadoLabel = (string) ($row['estado_label'] ?? $personal->estado ?: 'Sin estado');

                return [
                    'id' => (string) $personal->id,
                    'nombre' => (string) $personal->nombre_completo,
                    'documento' => $this->documentoPersonal($personal),
                    'puesto' => $puesto,
                    'estado' => $estadoLabel,
                    'estado_codigo' => (string) ($row['estado'] ?? $personal->estado ?: ''),
                    'estado_actual' => (string) ($row['estado_actual'] ?? ''),
                    'pendiente_contrato_firmado' => (bool) ($row['pendiente_contrato_firmado'] ?? false),
                    'contrato_firmado' => (bool) ($row['contrato_firmado'] ?? false),
                    'label' => trim(sprintf(
                        '%s - %s%s',
                        (string) $personal->nombre_completo,
                        $this->documentoPersonal($personal),
                        $puesto !== 'Sin puesto' ? ' - '.$puesto : ''
                    )),
                ];
            })
            ->all();
    }

    private function searchTermVariants(string $term): array
    {
        return collect([
            $term,
            Str::ascii($term),
            Str::upper($term),
            Str::upper(Str::ascii($term)),
        ])
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function likeTerm(string $term): string
    {
        return '%'.addcslashes($term, '\\%_').'%';
    }

    private function applyMovementTypeFilter($query, string $type): void
    {
        match ($type) {
            'ENTREGA' => $query
                ->where('estado', EppEntrega::ESTADO_ENTREGADO)
                ->where(function ($where): void {
                    $where->whereNull('motivo_cambio')->orWhere('motivo_cambio', '');
                }),
            'RENOVACION' => $query
                ->where('estado', EppEntrega::ESTADO_ENTREGADO)
                ->whereNotNull('motivo_cambio')
                ->where('motivo_cambio', '<>', ''),
            'CAMBIO' => $query->where('estado', EppEntrega::ESTADO_CAMBIADO),
            'USO_INCORRECTO' => $query->where('estado', EppEntrega::ESTADO_USO_INCORRECTO),
            'PERDIDA_OLVIDO' => $query->where('estado', EppEntrega::ESTADO_PERDIDA_OLVIDO),
            'DEVOLUCION' => $query->where('estado', EppEntrega::ESTADO_DEVUELTO),
            default => null,
        };
    }

    private function normalizeDateFilter(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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
            'talla' => (string) ($entrega->talla ?: ''),
            'color' => (string) ($entrega->color ?: ''),
            'atributos' => $entrega->atributos_json ?: [],
            'estado' => (string) $entrega->estado,
            'estado_label' => $this->deliveryStateLabel((string) $entrega->estado),
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

    private function deliveryStateLabel(string $estado): string
    {
        return match ($estado) {
            EppEntrega::ESTADO_ENTREGADO => 'Entregado',
            EppEntrega::ESTADO_CAMBIADO => 'Cambio de EPP',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
            default => ucwords(strtolower(str_replace('_', ' ', $estado))),
        };
    }

    public function personalKardex(string $personalId): array
    {
        $personal = Personal::query()
            ->with(['relacionesMina.mina:id,nombre'])
            ->findOrFail($personalId);

        $rows = $this->kardexDeliveries($personal->id)
            ->map(fn (EppEntrega $entrega): array => $this->presentKardexEntrega($entrega))
            ->values();
        $matrix = $this->buildKardexMatrix($rows);

        $activeRows = $rows->where('estado', EppEntrega::ESTADO_ENTREGADO);
        $overdueRows = $activeRows->filter(function (array $row): bool {
            $date = $row['fecha_vencimiento_iso'] ?? '';

            return $date !== '' && Carbon::parse($date)->lt(now()->startOfDay());
        });

        return [
            'worker' => [
                'id' => (string) $personal->id,
                'nombre' => (string) ($personal->nombre_completo ?: 'Trabajador sin nombre'),
                'documento' => $this->documentoPersonal($personal),
                'puesto' => (string) ($personal->puesto ?: 'Sin puesto'),
                'estado' => (string) ($personal->estado ?: 'Sin estado'),
                'minas' => $this->personalMineLabels($personal),
            ],
            'summary' => [
                'total' => $rows->count(),
                'activos' => $activeRows->count(),
                'cerrados' => $rows->whereIn('estado', [
                    EppEntrega::ESTADO_CAMBIADO,
                    EppEntrega::ESTADO_USO_INCORRECTO,
                    EppEntrega::ESTADO_PERDIDA_OLVIDO,
                    EppEntrega::ESTADO_DEVUELTO,
                ])->count(),
                'vencidos' => $overdueRows->count(),
                'items' => $rows->pluck('epp')->filter()->unique()->count(),
            ],
            'items' => $rows->all(),
            'kardex' => [
                'items' => $matrix['items']->map(fn (array $item): array => [
                    'key' => $item['key'],
                    'label' => $item['label'],
                    'sheet' => $item['side'],
                ])->values()->all(),
                'rows' => $matrix['rows']->values()->all(),
                'has_observations' => $matrix['has_observations'],
            ],
        ];
    }

    public function downloadPersonalKardex(string $personalId): StreamedResponse
    {
        $data = $this->personalKardex($personalId);
        $worker = $data['worker'];
        $rows = collect($data['items'])
            ->sortBy([
                ['fecha_entrega_iso', 'asc'],
                ['id', 'asc'],
            ])
            ->values();
        $matrix = $this->buildKardexMatrix($rows);
        $frontItems = $matrix['items']->where('side', 'anterior')->values();
        $backItems = $matrix['items']->where('side', 'posterior')->values();

        $spreadsheet = new Spreadsheet();
        $frontSheet = $spreadsheet->getActiveSheet();
        $frontSheet->setTitle('Anterior');
        $backSheet = $spreadsheet->createSheet();
        $backSheet->setTitle('Posterior');

        $this->writeKardexAnteriorSheet($frontSheet, $worker, $frontItems, $matrix['rows'], $matrix['has_observations']);
        $this->writeKardexPosteriorSheet($backSheet, $backItems, $matrix['rows'], $matrix['has_observations']);
        $spreadsheet->setActiveSheetIndex(0);

        $writer = new Xlsx($spreadsheet);
        $filename = 'SGC-FOR-59_kardex_epp_' . Str::slug((string) $worker['nombre'], '_') . '_' . now()->format('Ymd_His') . '.xlsx';

        return response()->streamDownload(function () use ($writer): void {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
        $attributes = $this->normalizeDeliveryAttributes($epp, $payload);

        return DB::transaction(function () use ($payload, $personal, $epp, $fechaEntrega, $vidaDias, $usuario, $attributes): EppEntrega {
            $data = [
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
            ];

            foreach ($this->deliveryAttributeColumns() as $column) {
                $data[$column] = $attributes[$column] ?? null;
            }

            return EppEntrega::query()->create($data);
        });
    }

    public function closeEntrega(string $id, array $payload, ?Usuario $usuario): EppEntrega
    {
        $entrega = EppEntrega::query()->findOrFail($id);

        if ($entrega->estado !== EppEntrega::ESTADO_ENTREGADO) {
            return $entrega;
        }

        $estado = strtoupper(trim((string) ($payload['estado'] ?? EppEntrega::ESTADO_DEVUELTO)));
        if (!in_array($estado, [
            EppEntrega::ESTADO_CAMBIADO,
            EppEntrega::ESTADO_DEVUELTO,
            EppEntrega::ESTADO_USO_INCORRECTO,
            EppEntrega::ESTADO_PERDIDA_OLVIDO,
        ], true)) {
            $estado = EppEntrega::ESTADO_DEVUELTO;
        }

        $fecha = Carbon::parse((string) ($payload['devuelto_at'] ?? now()->toDateString()))->startOfDay();

        $entrega->forceFill([
            'estado' => $estado,
            'motivo_cambio' => trim((string) ($payload['motivo_cambio'] ?? '')) ?: $this->defaultCloseReason($estado),
            'observacion' => trim((string) ($payload['observacion'] ?? $entrega->observacion ?? '')) ?: null,
            'devuelto_at' => $fecha->toDateString(),
            'cerrado_por_usuario_id' => $usuario?->id,
        ])->save();

        return $entrega->fresh(['personal', 'epp']);
    }

    public function replaceEntrega(string $id, array $payload, array $closePayload, ?Usuario $usuario): EppEntrega
    {
        return DB::transaction(function () use ($id, $payload, $closePayload, $usuario): EppEntrega {
            $entregaAnterior = EppEntrega::query()->lockForUpdate()->findOrFail($id);

            if ($entregaAnterior->estado !== EppEntrega::ESTADO_ENTREGADO) {
                throw new InvalidArgumentException('Esta entrega ya fue cerrada y no puede cambiarse otra vez.');
            }

            if ((string) $entregaAnterior->personal_id !== (string) ($payload['personal_id'] ?? '')) {
                throw new InvalidArgumentException('El cambio debe registrarse para el mismo trabajador.');
            }

            $nuevaEntrega = $this->deliver($payload, $usuario);
            $fecha = Carbon::parse((string) ($closePayload['devuelto_at'] ?? $payload['fecha_entrega'] ?? now()->toDateString()))->startOfDay();

            $entregaAnterior->forceFill([
                'estado' => EppEntrega::ESTADO_CAMBIADO,
                'motivo_cambio' => trim((string) ($closePayload['motivo_cambio'] ?? '')) ?: $this->defaultCloseReason(EppEntrega::ESTADO_CAMBIADO),
                'observacion' => trim((string) ($closePayload['observacion'] ?? $entregaAnterior->observacion ?? '')) ?: null,
                'devuelto_at' => $fecha->toDateString(),
                'cerrado_por_usuario_id' => $usuario?->id,
            ])->save();

            return $nuevaEntrega->fresh(['personal', 'epp']);
        });
    }

    public function updateEntrega(string $id, array $payload, ?Usuario $usuario): EppEntrega
    {
        return DB::transaction(function () use ($id, $payload, $usuario): EppEntrega {
            $entrega = EppEntrega::query()->with('epp')->findOrFail($id);

            $data = [];
            $selectedEpp = $entrega->epp;
            $shouldRecalculateExpiration = false;

            if (array_key_exists('epp_id', $payload) && filled($payload['epp_id']) && (string) $payload['epp_id'] !== (string) $entrega->epp_id) {
                $selectedEpp = EppRegistro::query()->findOrFail((string) $payload['epp_id']);
                $data['epp_id'] = $selectedEpp->id;
                $data['vida_util_dias_snapshot'] = max(1, (int) ($selectedEpp->vida_util_dias ?: $entrega->vida_util_dias_snapshot ?: 1));
                $shouldRecalculateExpiration = true;
            }

            if (array_key_exists('cantidad', $payload)) {
                $data['cantidad'] = max(1, (int) $payload['cantidad']);
            }

            if (
                array_key_exists('talla', $payload)
                || array_key_exists('color', $payload)
                || array_key_exists('atributos', $payload)
                || array_key_exists('epp_id', $payload)
            ) {
                $attributes = $this->normalizeDeliveryAttributes($selectedEpp ?: $entrega->epp, $payload);
                foreach ($this->deliveryAttributeColumns() as $column) {
                    $data[$column] = $attributes[$column] ?? null;
                }
            }

            $fechaEntrega = $entrega->fecha_entrega
                ? Carbon::parse($entrega->fecha_entrega)->startOfDay()
                : Carbon::now()->startOfDay();

            if (array_key_exists('fecha_entrega', $payload)) {
                $fechaEntrega = Carbon::parse((string) $payload['fecha_entrega'])->startOfDay();
                $data['fecha_entrega'] = $fechaEntrega->toDateString();
                $shouldRecalculateExpiration = true;
            }

            if (array_key_exists('fecha_vencimiento_calendario', $payload)) {
                $data['fecha_vencimiento_calendario'] = filled($payload['fecha_vencimiento_calendario'])
                    ? Carbon::parse((string) $payload['fecha_vencimiento_calendario'])->startOfDay()->toDateString()
                    : null;
            } elseif ($shouldRecalculateExpiration) {
                $vidaDias = (int) ($data['vida_util_dias_snapshot'] ?? $entrega->vida_util_dias_snapshot ?: $selectedEpp?->vida_util_dias ?: 1);
                $data['fecha_vencimiento_calendario'] = $fechaEntrega->copy()->addDays($vidaDias)->toDateString();
            }

            if (array_key_exists('motivo_cambio', $payload)) {
                $data['motivo_cambio'] = trim((string) $payload['motivo_cambio']) ?: null;
            }

            if (array_key_exists('observacion', $payload)) {
                $data['observacion'] = trim((string) $payload['observacion']) ?: null;
            }

            if ($data !== []) {
                $entrega->forceFill($data)->save();
            }

            return $entrega->fresh(['personal', 'epp']);
        });
    }

    public function destroyEntrega(string $id): void
    {
        $entrega = EppEntrega::query()->findOrFail($id);
        $entrega->delete();
    }

    public function destroyCatalog(string $id): void
    {
        $epp = EppRegistro::query()->findOrFail($id);
        $epp->forceFill([
            'estado' => EppRegistro::ESTADO_INACTIVO,
        ])->save();
    }

    private function deliveryAttributeColumns(): array
    {
        return collect(['talla', 'color', 'atributos_json'])
            ->filter(fn (string $column): bool => Schema::hasColumn('epp_entregas', $column))
            ->values()
            ->all();
    }

    private function normalizeDeliveryAttributes(?EppRegistro $epp, array $payload): array
    {
        if (! $epp) {
            return [
                'talla' => null,
                'color' => null,
                'atributos_json' => null,
            ];
        }

        $talla = null;
        if ((bool) $epp->requiere_talla) {
            $talla = $this->normalizeSelectedOption((string) ($payload['talla'] ?? ''), $epp->tallas ?: []);
        }

        $color = null;
        if ((bool) $epp->requiere_color) {
            $color = $this->normalizeSelectedOption((string) ($payload['color'] ?? ''), $epp->colores ?: []);
        }

        $attributes = [];
        $configuredAttributes = collect($epp->otros_atributos ?: [])
            ->map(function (array $attribute): array {
                return [
                    'nombre' => trim((string) ($attribute['nombre'] ?? '')),
                    'valores' => collect($attribute['valores'] ?? [])
                        ->map(fn ($value): string => mb_strtoupper(trim((string) $value), 'UTF-8'))
                        ->filter()
                        ->values()
                        ->all(),
                ];
            })
            ->filter(fn (array $attribute): bool => $attribute['nombre'] !== '' && $attribute['valores'] !== [])
            ->values();

        $postedAttributes = collect($payload['atributos'] ?? []);
        foreach ($configuredAttributes as $index => $configured) {
            $posted = collect($postedAttributes)->first(function ($attribute) use ($configured, $index): bool {
                $name = mb_strtoupper(trim((string) data_get($attribute, 'nombre', '')), 'UTF-8');
                $configuredName = mb_strtoupper($configured['nombre'], 'UTF-8');

                return $name === $configuredName || (string) data_get($attribute, 'index', '') === (string) $index;
            });

            $value = $this->normalizeSelectedOption((string) data_get($posted, 'valor', ''), $configured['valores']);
            if ($value !== null) {
                $attributes[] = [
                    'nombre' => $configured['nombre'],
                    'valor' => $value,
                ];
            }
        }

        return [
            'talla' => $talla,
            'color' => $color,
            'atributos_json' => $attributes !== [] ? $attributes : null,
        ];
    }

    private function normalizeSelectedOption(string $value, array $allowed): ?string
    {
        $value = mb_strtoupper(trim($value), 'UTF-8');
        if ($value === '') {
            return null;
        }

        $allowed = collect($allowed)
            ->map(fn ($option): string => mb_strtoupper(trim((string) $option), 'UTF-8'))
            ->filter()
            ->values();

        return $allowed->contains($value) ? $value : null;
    }

    public function presentEntrega(EppEntrega $entrega): array
    {
        $usage = $this->calculateUsage($entrega);
        $vidaDias = (int) ($entrega->vida_util_dias_snapshot ?: $entrega->epp?->vida_util_dias ?: 0);
        $fechaCierre = $entrega->devuelto_at ? Carbon::parse($entrega->devuelto_at)->format('d/m/Y') : null;
        $movimientoCierre = match ($entrega->estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
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
            'talla' => (string) ($entrega->talla ?: ''),
            'color' => (string) ($entrega->color ?: ''),
            'atributos' => $entrega->atributos_json ?: [],
            'fecha_cierre' => $fechaCierre,
            'movimiento_cierre' => $movimientoCierre,
            'cerrado_por' => $this->usuarioLabel($entrega->cerradoPor),
        ];
    }

    private function kardexDeliveries(string $personalId)
    {
        return EppEntrega::query()
            ->with([
                'personal:id,nombre_completo,dni,numero_documento,puesto,estado',
                'epp:id,codigo,nombre,categoria,vida_util_dias',
                'registradoPor:id,email,personal_id',
                'registradoPor.personal:id,nombre_completo',
                'cerradoPor:id,email,personal_id',
                'cerradoPor.personal:id,nombre_completo',
            ])
            ->where('personal_id', $personalId)
            ->orderByDesc('fecha_entrega')
            ->orderByDesc('created_at')
            ->get();
    }

    private function presentKardexEntrega(EppEntrega $entrega): array
    {
        $presented = $this->presentEntrega($entrega);
        $fechaEntrega = $entrega->fecha_entrega ? Carbon::parse($entrega->fecha_entrega) : null;
        $fechaVencimiento = $entrega->fecha_vencimiento_calendario ? Carbon::parse($entrega->fecha_vencimiento_calendario) : null;
        $fechaCierre = $entrega->devuelto_at ? Carbon::parse($entrega->devuelto_at) : null;
        $movimiento = match ($entrega->estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
            default => trim((string) $entrega->motivo_cambio) !== '' ? 'Renovacion' : 'Entrega',
        };

        return [
            'id' => (string) $entrega->id,
            'personal_id' => (string) $entrega->personal_id,
            'epp_id' => (string) $entrega->epp_id,
            'epp' => (string) ($entrega->epp?->nombre ?: 'EPP no encontrado'),
            'codigo' => (string) ($entrega->epp?->codigo ?: '-'),
            'categoria' => (string) ($entrega->epp?->categoria ?: 'EPP'),
            'cantidad' => (int) $entrega->cantidad,
            'talla' => (string) ($entrega->talla ?: ''),
            'color' => (string) ($entrega->color ?: ''),
            'atributos' => $entrega->atributos_json ?: [],
            'estado' => (string) $entrega->estado,
            'estado_label' => match ($entrega->estado) {
                EppEntrega::ESTADO_ENTREGADO => 'Entregado',
                EppEntrega::ESTADO_CAMBIADO => 'Cambiado',
                EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
                EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
                EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
                default => ucwords(strtolower((string) $entrega->estado)),
            },
            'movimiento' => $movimiento,
            'fecha_entrega' => $fechaEntrega ? $fechaEntrega->format('d/m/Y') : '-',
            'fecha_entrega_iso' => $fechaEntrega ? $fechaEntrega->toDateString() : '',
            'fecha_vencimiento' => $fechaVencimiento ? $fechaVencimiento->format('d/m/Y') : '-',
            'fecha_vencimiento_iso' => $fechaVencimiento ? $fechaVencimiento->toDateString() : '',
            'fecha_cierre' => $fechaCierre ? $fechaCierre->format('d/m/Y') : '-',
            'vida_dias' => (int) $presented['vida_dias'],
            'dias_uso_efectivo' => (int) $presented['dias_uso_efectivo'],
            'dias_restantes_uso' => (int) $presented['dias_restantes_uso'],
            'uso_porcentaje' => (int) $presented['uso_porcentaje'],
            'periodos_uso' => collect($presented['periodos_uso'] ?? [])
                ->take(3)
                ->map(fn (array $periodo): array => [
                    'parada' => (string) ($periodo['parada'] ?? 'Parada'),
                    'desde' => Carbon::parse((string) ($periodo['desde'] ?? now()->toDateString()))->format('d/m/Y'),
                    'hasta' => Carbon::parse((string) ($periodo['hasta'] ?? now()->toDateString()))->format('d/m/Y'),
                    'dias' => (int) ($periodo['dias'] ?? 0),
                ])
                ->values()
                ->all(),
            'observacion' => (string) ($entrega->observacion ?: ''),
            'motivo_cambio' => (string) ($entrega->motivo_cambio ?: ''),
            'registrado_por' => $this->usuarioLabel($entrega->registradoPor),
            'cerrado_por' => $this->usuarioLabel($entrega->cerradoPor),
        ];
    }

    private function buildKardexMatrix($rows): array
    {
        $items = collect($rows)
            ->map(function (array $row, int $index): array {
                $label = $this->cleanKardexText((string) ($row['epp'] ?? 'EPP'));
                $template = $this->matchKardexTemplate($label);

                return [
                    'key' => $this->kardexItemKey($row),
                    'label' => $label,
                    'side' => $template['side'],
                    'order' => $template['order'],
                    'original_order' => $index,
                ];
            })
            ->filter(fn (array $item): bool => $item['key'] !== '' && $item['label'] !== '')
            ->unique('key')
            ->sortBy([
                ['side', 'asc'],
                ['order', 'asc'],
                ['label', 'asc'],
            ])
            ->values();

        $front = $items->where('side', 'anterior')->values();
        $back = $items->where('side', 'posterior')->values();

        if ($front->count() > self::KARDEX_MAX_ITEMS_PER_SHEET) {
            $overflow = $front->slice(self::KARDEX_MAX_ITEMS_PER_SHEET)
                ->map(fn (array $item): array => array_merge($item, ['side' => 'posterior']));
            $front = $front->take(self::KARDEX_MAX_ITEMS_PER_SHEET)->values();
            $back = $back->concat($overflow)->values();
        }

        $items = $front->concat($back->take(self::KARDEX_MAX_ITEMS_PER_SHEET))->values();
        $hasObservations = collect($rows)->contains(fn (array $row): bool => $this->kardexObservation($row) !== '');
        $firstDeliveryDate = collect($rows)
            ->pluck('fecha_entrega_iso')
            ->filter()
            ->sort()
            ->first();
        $matrixRows = collect($rows)->values()->map(function (array $row, int $index) use ($firstDeliveryDate): array {
            $itemKey = $this->kardexItemKey($row);
            $code = $this->kardexMovementCode($row, $firstDeliveryDate !== '' && ($row['fecha_entrega_iso'] ?? '') === $firstDeliveryDate);

            return [
                'number' => $index + 1,
                'date' => (string) ($row['fecha_entrega'] ?? '-'),
                'item_key' => $itemKey,
                'codes' => [$itemKey => $code],
                'observation' => $this->kardexObservation($row),
            ];
        });

        return [
            'items' => $items,
            'rows' => $matrixRows,
            'has_observations' => $hasObservations,
        ];
    }

    private function matchKardexTemplate(string $label): array
    {
        $normalized = $this->normalizeKardexText($label);

        foreach (self::KARDEX_TEMPLATE_ORDER as $index => $template) {
            foreach ($template['keywords'] as $keyword) {
                if (str_contains($normalized, $this->normalizeKardexText($keyword))) {
                    return [
                        'side' => $template['side'],
                        'order' => $index,
                    ];
                }
            }
        }

        return [
            'side' => 'anterior',
            'order' => 1000,
        ];
    }

    private function writeKardexAnteriorSheet($sheet, array $worker, $items, $rows, bool $hasObservations): void
    {
        [$lastNames, $firstNames] = $this->splitKardexWorkerName((string) ($worker['nombre'] ?? ''));
        $dataRows = max(20, $rows->count());
        $dataStartRow = 14;
        $dataEndRow = $dataStartRow + $dataRows - 1;
        $notesRow = $dataEndRow + 1;
        $declarationRow = $notesRow + 1;
        $declarationEndRow = $declarationRow + 4;

        $this->prepareKardexSheet($sheet, $declarationEndRow);
        $this->writeKardexTopHeader($sheet, ': 1 de 2');
        $sheet->mergeCells('A5:B5');
        $sheet->mergeCells('C5:O5');
        $sheet->mergeCells('P5:T5');
        $sheet->mergeCells('U5:AE5');
        $sheet->setCellValue('A5', 'APELLIDOS:');
        $sheet->setCellValue('C5', $lastNames);
        $sheet->setCellValue('P5', 'NOMBRES:');
        $sheet->setCellValue('U5', $firstNames);
        $sheet->mergeCells('A6:B6');
        $sheet->mergeCells('C6:H6');
        $sheet->mergeCells('I6:N6');
        $sheet->mergeCells('O6:W6');
        $sheet->mergeCells('X6:AD6');
        $sheet->setCellValue('A6', 'DOCUMENTO DNI:');
        $sheet->setCellValue('C6', $worker['documento'] ?? '-');
        $sheet->setCellValue('I6', 'AREA DE DEPENDENCIA:');
        $sheet->setCellValue('O6', $worker['puesto'] ?? '-');
        $sheet->setCellValue('X6', 'FECHA DE APERTURA');
        $sheet->setCellValue('AE6', now()->format('d/m/Y'));

        $this->writeKardexLegendBlock($sheet, 8);
        $sheet->mergeCells('A11:AD11');
        $sheet->setCellValue('A11', 'ELEMENTO DE PROTECCION PERSONAL');
        $sheet->setCellValue('AE11', 'RECIBIDO');
        $this->writeKardexMatrixHeader($sheet, 12, $items);
        $this->writeKardexMatrixRows($sheet, $dataStartRow, $dataRows, $items, $rows);

        if ($hasObservations) {
            $sheet->mergeCells("A{$notesRow}:AD{$notesRow}");
            $sheet->setCellValue("A{$notesRow}", 'OBSERVACIONES: '.$this->joinedKardexObservations($rows));
        }

        $this->writeKardexDeclaration($sheet, $declarationRow, $declarationEndRow);
        $this->styleKardexSheet($sheet, 12, $dataStartRow, $dataEndRow, $declarationEndRow);
        $sheet->freezePane('C14');
    }

    private function writeKardexPosteriorSheet($sheet, $items, $rows, bool $hasObservations): void
    {
        $dataRows = max(20, $rows->count());
        $dataStartRow = 7;
        $dataEndRow = $dataStartRow + $dataRows - 1;
        $notesRow = $dataEndRow + 1;
        $declarationRow = $notesRow + 1;
        $declarationEndRow = $declarationRow + 5;

        $this->prepareKardexSheet($sheet, $declarationEndRow);
        $this->writeKardexTopHeader($sheet, ': 2 de 2');
        $sheet->mergeCells('A4:AD4');
        $sheet->setCellValue('A4', 'ELEMENTO DE PROTECCION PERSONAL');
        $this->writeKardexMatrixHeader($sheet, 5, $items);
        $this->writeKardexMatrixRows($sheet, $dataStartRow, $dataRows, $items, $rows);

        $sheet->mergeCells("A{$notesRow}:AE{$notesRow}");
        $sheet->setCellValue("A{$notesRow}", 'OBSERVACIONES:'.($hasObservations ? ' '.$this->joinedKardexObservations($rows) : ''));
        $this->writeKardexDeclaration($sheet, $declarationRow, $declarationEndRow);
        $this->styleKardexSheet($sheet, 5, $dataStartRow, $dataEndRow, $declarationEndRow);
        $sheet->freezePane('C7');
    }

    private function prepareKardexSheet($sheet, int $lastRow): void
    {
        $sheet->getColumnDimension('A')->setWidth(4.8);
        $sheet->getColumnDimension('B')->setWidth(12.8);
        for ($column = 3; $column <= 30; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(4.2);
        }
        $sheet->getColumnDimension('AE')->setWidth(13);

        for ($row = 1; $row <= $lastRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(15);
        }

        foreach ([1 => 14.45, 2 => 14.45, 3 => 15, 4 => 12, 5 => 18, 6 => 18, 7 => 6.95, 8 => 13.5, 9 => 13.5, 10 => 6.95, 11 => 15.75] as $row => $height) {
            if ($row <= $lastRow) {
                $sheet->getRowDimension($row)->setRowHeight($height);
            }
        }
    }

    private function writeKardexTopHeader($sheet, string $pageLabel): void
    {
        $sheet->mergeCells('A1:B3');
        $sheet->mergeCells('C1:AB3');
        $sheet->mergeCells('AD1:AE1');
        $sheet->mergeCells('AD2:AE2');
        $sheet->mergeCells('AD3:AE3');

        $sheet->setCellValue('C1', 'FORMATO DE ENTREGA DE EPPS');
        $sheet->setCellValue('AC1', 'Código');
        $sheet->setCellValue('AD1', 'SGC-FOR-59');
        $sheet->setCellValue('AC2', 'Versión');
        $sheet->setCellValue('AD2', '0');
        $sheet->setCellValue('AC3', 'Página');
        $sheet->setCellValue('AD3', $pageLabel);

        $logoPath = public_path('img/LogoProserge.png');
        if (is_file($logoPath)) {
            $drawing = new Drawing();
            $drawing->setName('Logo Proserge');
            $drawing->setDescription('Logo Proserge');
            $drawing->setPath($logoPath);
            $drawing->setCoordinates('A1');
            $drawing->setOffsetX(4);
            $drawing->setOffsetY(3);
            $drawing->setWidth(132);
            $drawing->setWorksheet($sheet);
        } else {
            $sheet->setCellValue('A1', 'P&S PROSERGE SRL.');
        }
    }

    private function writeKardexLegendBlock($sheet, int $row): void
    {
        $sheet->mergeCells("A{$row}:B".($row + 1));
        $sheet->mergeCells("E{$row}:H".($row + 1));
        $sheet->mergeCells("K{$row}:N".($row + 1));
        $sheet->mergeCells("Q{$row}:T".($row + 1));
        $sheet->mergeCells("W{$row}:Z".($row + 1));
        $sheet->mergeCells("AC{$row}:AE".($row + 1));
        $sheet->setCellValue("A{$row}", 'TIPO DE ENTREGA');
        $sheet->setCellValue("D{$row}", 'N');
        $sheet->setCellValue("E{$row}", 'Nuevo / 1era Entrega');
        $sheet->setCellValue("J{$row}", 'C');
        $sheet->setCellValue("K{$row}", 'Cambio / Deterioro');
        $sheet->setCellValue("P{$row}", 'I');
        $sheet->setCellValue("Q{$row}", 'Uso Incorrecto');
        $sheet->setCellValue("V{$row}", 'P');
        $sheet->setCellValue("W{$row}", 'Perdida / Olvido');
        $sheet->setCellValue("AB{$row}", 'D');
        $sheet->setCellValue("AC{$row}", 'Devuelto por internamiento');
    }

    private function writeKardexMatrixHeader($sheet, int $headerRow, $items): void
    {
        $sheet->setCellValue("A{$headerRow}", 'Nro');
        $sheet->setCellValue("B{$headerRow}", 'FECHA');
        $sheet->mergeCells("B{$headerRow}:B".($headerRow + 1));
        $sheet->mergeCells("AE{$headerRow}:AE".($headerRow + 1));
        $sheet->setCellValue("AE{$headerRow}", 'FIRMA');

        if ($items->isEmpty()) {
            $sheet->setCellValue("C{$headerRow}", 'Sin EPP registrado');
            return;
        }

        foreach ($items->values() as $index => $item) {
            $column = Coordinate::stringFromColumnIndex(3 + $index);
            $sheet->setCellValue("{$column}{$headerRow}", $item['label']);
        }
    }

    private function writeKardexMatrixRows($sheet, int $startRow, int $dataRows, $items, $rows): void
    {
        for ($offset = 0; $offset < $dataRows; $offset++) {
            $rowNumber = $startRow + $offset;
            $row = $rows->get($offset);
            $sheet->setCellValue("A{$rowNumber}", $offset + 1);
            $sheet->setCellValue("B{$rowNumber}", $row['date'] ?? 'Dia/Mes/Ano');

            foreach ($items->values() as $index => $item) {
                $code = $row['codes'][$item['key']] ?? '';
                if ($code !== '') {
                    $sheet->getCell([3 + $index, $rowNumber])->setValue($code);
                }
            }
        }
    }

    private function writeKardexDeclaration($sheet, int $startRow, int $endRow): void
    {
        $sheet->mergeCells("A{$startRow}:AD{$endRow}");
        $sheet->setCellValue("A{$startRow}", 'DECLARO HABER RECIBIDO LOS ELEMENTOS DE PROTECCION PERSONAL AQUI SENALADOS, ASI COMO LAS INSTRUCCIONES PARA SU CORRECTO USO Y ACEPTO EL COMPROMISO QUE SE SOLICITA DE: a. Utilizar el elemento durante la jornada de trabajo en las areas cuya obligatoriedad de uso se encuentra senalizado. b. Consultar cualquier duda sobre su correcta utilizacion, cuidando de su perfecto estado y conservacion. c. Solicitar un cambio de equipo en caso de deterioro o por haber cumplido el tiempo de vida util del mismo internando este en almacen. d. Solicitar un nuevo equipo en caso de perdida, olvido o mal uso debiendo asumir responsabilidad economica por la reposicion de este, de acuerdo al valor actual prorrateado entre la vida util restante. e. Asimismo declara haberlos recibido con cargo de devolucion cuando sea solicitado por la empresa.');
    }

    private function styleKardexSheet($sheet, int $headerRow, int $dataStartRow, int $dataEndRow, int $lastRow): void
    {
        $sheet->getStyle("A1:AE{$lastRow}")->getFont()->setName('Arial')->setSize(8);
        $sheet->getStyle("A1:AE{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A1:AE{$lastRow}")->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);

        $sheet->getStyle('A1:AE3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFFFF');
        $sheet->getStyle('C1:AB3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C00000');
        $sheet->getStyle('C1:AB3')->getFont()
            ->setName('Arial Black')
            ->setBold(true)
            ->setSize(18)
            ->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('AC1:AE3')->getFont()->setName('Arial Narrow')->setBold(true)->setSize(9);
        $sheet->getStyle('AC1:AC3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getStyle('AD1:AE3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);

        foreach ([
            'A5:B5',
            'P5:T5',
            'A6:B6',
            'I6:N6',
            'X6:AD6',
            'A8:B9',
            'D8:D9',
            'J8:J9',
            'P8:P9',
            'V8:V9',
            "A{$headerRow}:AE".($headerRow + 1),
        ] as $range) {
            $sheet->getStyle($range)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7E6E6');
        }

        $sheet->getStyle('A5:AE11')->getFont()->setBold(true);
        $sheet->getStyle('E8:H9')->getFont()->setBold(false);
        $sheet->getStyle('K8:N9')->getFont()->setBold(false);
        $sheet->getStyle('Q8:T9')->getFont()->setBold(false);
        $sheet->getStyle('W8:Z9')->getFont()->setBold(false);
        $sheet->getStyle('A11:AE11')->getFont()->setBold(true)->setSize(9);

        $sheet->getStyle("A{$headerRow}:AE".($headerRow + 1))->getFont()->setBold(true);
        $sheet->getStyle("C{$headerRow}:AD{$headerRow}")->getAlignment()->setTextRotation(90);
        $sheet->getStyle("A{$dataStartRow}:AE{$dataEndRow}")->getFont()->setSize(9);
        $sheet->getStyle("B{$dataStartRow}:B{$dataEndRow}")->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle("A{$lastRow}:AD{$lastRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        $sheet->getRowDimension($headerRow)->setRowHeight(100.5);
        $sheet->getRowDimension($headerRow + 1)->setRowHeight(4.5);
    }

    private function joinedKardexObservations($rows): string
    {
        return $rows
            ->pluck('observation')
            ->filter()
            ->unique()
            ->implode(' | ');
    }

    private function splitKardexWorkerName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 2) {
            return [trim($name), ''];
        }

        return [
            implode(' ', array_slice($parts, 0, 2)),
            implode(' ', array_slice($parts, 2)),
        ];
    }

    private function normalizeKardexText(string $value): string
    {
        $normalized = Str::of($value)->ascii()->upper()->toString();

        return preg_replace('/[^A-Z0-9]+/', ' ', $normalized) ?: '';
    }

    private function defaultCloseReason(string $estado): string
    {
        return match ($estado) {
            EppEntrega::ESTADO_CAMBIADO => 'Cambio de EPP',
            EppEntrega::ESTADO_USO_INCORRECTO => 'Uso incorrecto',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'Perdida / olvido',
            EppEntrega::ESTADO_DEVUELTO => 'Devuelto por internamiento',
            default => 'Entrega de EPP',
        };
    }

    private function writeKardexLegend($sheet, int $lastColumnIndex): void
    {
        $lastColumn = Coordinate::stringFromColumnIndex($lastColumnIndex);
        if ($lastColumnIndex >= 2) {
            $sheet->mergeCells('A9:B9');
        }
        if ($lastColumnIndex >= 3) {
            $sheet->mergeCells("C9:{$lastColumn}9");
        }
        $sheet->setCellValue('A9', 'TIPO DE ENTREGA');
        if ($lastColumnIndex >= 3) {
            $sheet->setCellValue('C9', 'N = Nuevo / 1era entrega    C = Cambio / deterioro    I = Uso incorrecto    P = Perdida / olvido    D = Devuelto por internamiento');
        }

        $sheet->getStyle("A9:{$lastColumn}9")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F7F7');
    }

    private function kardexItemKey(array $row): string
    {
        $id = trim((string) ($row['epp_id'] ?? ''));
        if ($id !== '') {
            return $id;
        }

        return Str::slug((string) ($row['codigo'] ?? $row['epp'] ?? ''), '_');
    }

    private function cleanKardexText(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value)) ?: 'EPP';
    }

    private function kardexMovementCode(array $row, bool $isFirstDelivery): string
    {
        return match ((string) ($row['estado'] ?? '')) {
            EppEntrega::ESTADO_CAMBIADO => 'C',
            EppEntrega::ESTADO_USO_INCORRECTO => 'I',
            EppEntrega::ESTADO_PERDIDA_OLVIDO => 'P',
            EppEntrega::ESTADO_DEVUELTO => 'D',
            default => $isFirstDelivery && trim((string) ($row['motivo_cambio'] ?? '')) === '' ? 'N' : 'C',
        };
    }

    private function kardexObservation(array $row): string
    {
        return trim(implode(' | ', array_filter([
            (string) ($row['motivo_cambio'] ?? ''),
            (string) ($row['observacion'] ?? ''),
            (($row['fecha_cierre'] ?? '-') !== '-') ? 'Cierre: '.$row['fecha_cierre'] : '',
        ])));
    }

    private function personalMineLabels(Personal $personal): string
    {
        $relations = $personal->relationLoaded('relacionesMina') ? $personal->relacionesMina : collect();

        return $relations
            ->filter(fn ($relation): bool => (bool) ($relation->activo ?? true))
            ->pluck('mina.nombre')
            ->filter()
            ->unique()
            ->values()
            ->implode(', ');
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
            'otros_atributos' => $this->normalizeOtrosAtributos($payload['otros_atributos'] ?? []),
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

    private function normalizeOtrosAtributos(array $atributos): ?array
    {
        $result = [];

        foreach ($atributos as $atributo) {
            $nombre = mb_strtoupper(trim((string) ($atributo['nombre'] ?? '')), 'UTF-8');
            $valoresRaw = (string) ($atributo['valores'] ?? '');

            if ($nombre === '' || trim($valoresRaw) === '') {
                continue;
            }

            $valores = collect(preg_split('/[\r\n,;]+/', $valoresRaw) ?: [])
                ->map(fn (string $item): string => mb_strtoupper(trim($item), 'UTF-8'))
                ->filter()
                ->unique()
                ->values()
                ->all();

            if ($valores === []) {
                continue;
            }

            $result[] = [
                'nombre' => $nombre,
                'valores' => $valores,
            ];
        }

        return $result !== [] ? $result : null;
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

