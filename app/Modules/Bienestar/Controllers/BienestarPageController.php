<?php

namespace App\Modules\Bienestar\Controllers;

use App\Models\Personal;
use App\Models\PersonalBloqueo;
use App\Models\Usuario;
use App\Http\Controllers\Controller;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Support\Rbac\PermissionMatrix;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BienestarPageController extends Controller
{
    public function __construct(private readonly OperationalNotificationService $operationalNotifications)
    {
    }

    public function index(Request $request): View
    {
        $this->assertWellbeingPermission('ver');

        $search = trim((string) $request->query('search', ''));
        $from = $this->resolveDate($request->query('fecha_inicio'), Carbon::today()->toDateString());
        $to = $this->resolveDate($request->query('fecha_fin'), Carbon::today()->addDays(14)->toDateString());

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $baseQuery = PersonalBloqueo::query()
            ->with(['personal:id,dni,nombre_completo', 'bloqueadoPor.personal:id,nombre_completo'])
            ->where('estado', 'ACTIVO')
            ->where('visible_para_planner', true)
            ->whereDate('fecha_inicio', '<=', $to)
            ->whereDate('fecha_fin', '>=', $from)
            ->whereHas('personal', function ($q) use ($search): void {
                $q->where('estado', 'ACTIVO');

                if ($search !== '') {
                    $needle = '%' . mb_strtolower($search) . '%';
                    $q->where(function ($sub) use ($needle): void {
                        $sub->whereRaw('LOWER(nombre_completo) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(dni) LIKE ?', [$needle]);
                    });
                }
            });

        $bloqueos = (clone $baseQuery)
            ->orderBy('fecha_inicio')
            ->orderBy('fecha_fin')
            ->get();

        $trabajadoresBloqueados = $bloqueos
            ->groupBy('personal_id')
            ->map(function (Collection $items): array {
                /** @var PersonalBloqueo $first */
                $first = $items->first();

                $motivos = $items
                    ->map(function (PersonalBloqueo $bloqueo): array {
                        return [
                            'tipo' => $bloqueo->tipoLabel(),
                            'motivo' => (string) $bloqueo->motivo,
                        ];
                    })
                    ->unique(function (array $item): string {
                        return mb_strtolower($item['tipo'] . '|' . $item['motivo']);
                    })
                    ->values()
                    ->all();

                $registradoPor = $items
                    ->map(function (PersonalBloqueo $bloqueo): ?string {
                        return $bloqueo->bloqueadoPor?->personal?->nombre_completo
                            ?? $bloqueo->bloqueadoPor?->email;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                return [
                    'personal_id' => $first->personal_id,
                    'nombre' => $first->personal->nombre_completo ?? 'Sin nombre',
                    'dni' => $first->personal->dni ?? '-',
                    'motivos' => $motivos,
                    'registrado_por' => $registradoPor,
                ];
            })
            ->values();

        $hoy = Carbon::today()->toDateString();
        $resumenActivosHoy = PersonalBloqueo::query()
            ->where('estado', 'ACTIVO')
            ->where('visible_para_planner', true)
            ->whereDate('fecha_inicio', '<=', $hoy)
            ->whereDate('fecha_fin', '>=', $hoy);

        $resumen = [
            'total_activos_hoy' => (clone $resumenActivosHoy)->count(),
            'descanso_medico_hoy' => (clone $resumenActivosHoy)->where('tipo', 'descanso_medico')->count(),
            'vacaciones_hoy' => (clone $resumenActivosHoy)->where('tipo', 'vacaciones')->count(),
            'gestacion_hoy' => (clone $resumenActivosHoy)->where('tipo', 'gestacion')->count(),
            'restriccion_hoy' => (clone $resumenActivosHoy)->where('tipo', 'restriccion_temporal')->count(),
            'trabajadores_no_disponibles_periodo' => $bloqueos->pluck('personal_id')->unique()->count(),
            'bloqueos_en_periodo' => $bloqueos->count(),
        ];

        return view('bienestar.index', [
            'bloqueos' => $bloqueos,
            'trabajadoresBloqueados' => $trabajadoresBloqueados,
            'resumen' => $resumen,
            'filters' => [
                'search' => $search,
                'fecha_inicio' => $from,
                'fecha_fin' => $to,
            ],
        ]);
    }

    public function show(string $id): View
    {
        $this->assertWellbeingPermission('ver');

        $trabajador = Personal::query()
            ->with('fichaColaborador')
            ->select(['id', 'dni', 'nombre_completo', 'puesto', 'estado'])
            ->findOrFail($id);

        $soloCalendario = request()->boolean('solo_calendario');

        $monthInput = (string) request()->query('mes', Carbon::today()->format('Y-m'));
        $monthStart = $this->resolveMonthStart($monthInput);
        $monthEnd = $monthStart->copy()->endOfMonth();

        $bloqueos = PersonalBloqueo::query()
            ->with(['bloqueadoPor.personal:id,nombre_completo'])
            ->where('personal_id', $trabajador->id)
            ->where('estado', 'ACTIVO')
            ->whereDate('fecha_inicio', '<=', $monthEnd->toDateString())
            ->whereDate('fecha_fin', '>=', $monthStart->toDateString())
            ->orderBy('fecha_inicio')
            ->get();

        $calendar = $this->buildCalendar($monthStart, $bloqueos);

        return view('bienestar.show', [
            'trabajador' => $trabajador,
            'bloqueos' => $bloqueos,
            'calendar' => $calendar,
            'soloCalendario' => $soloCalendario,
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => ucfirst($monthStart->locale('es')->translatedFormat('F Y')),
            'prevMonth' => $monthStart->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $monthStart->copy()->addMonth()->format('Y-m'),
            'isMujer' => $this->isFemalePersonal($trabajador),
        ]);
    }

    public function storeBloqueo(Request $request, string $id)
    {
        $this->assertWellbeingPermission('crear');

        $trabajador = Personal::query()->with('fichaColaborador')->findOrFail($id);

        $payload = $request->validate([
            'tipo' => ['required', 'string', 'max:40'],
            'otro_tipo' => ['nullable', 'string', 'max:40'],
            'motivo' => ['required', 'string', 'max:191'],
            'detalle' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ]);

        $tipo = trim((string) $payload['tipo']);
        if ($tipo === 'otro') {
            $otro = trim((string) ($payload['otro_tipo'] ?? ''));
            if ($otro === '') {
                return back()->withErrors(['otro_tipo' => 'Debes indicar el tipo cuando seleccionas "Otro".'])->withInput();
            }
            $tipo = Str::of($otro)->lower()->replace(' ', '_')->value();
        }

        if ($tipo === 'gestacion' && !$this->isFemalePersonal($trabajador)) {
            return back()->withErrors(['tipo' => 'Solo se puede registrar gestacion para trabajadoras con sexo femenino en la ficha.'])->withInput();
        }

        $usuarioId = (string) (session('user.id') ?? session('user_id') ?? '');
        if ($usuarioId === '' || !Usuario::query()->where('id', $usuarioId)->exists()) {
            $usuarioId = (string) (Usuario::query()->value('id') ?? '');
        }

        if ($usuarioId === '') {
            return back()->withErrors(['tipo' => 'No existe un usuario válido para registrar el bloqueo.'])->withInput();
        }

        $bloqueo = PersonalBloqueo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $trabajador->id,
            'tipo' => $tipo,
            'motivo' => $payload['motivo'],
            'detalle' => $payload['detalle'] ?? null,
            'fecha_inicio' => Carbon::parse($payload['fecha_inicio'])->toDateString(),
            'fecha_fin' => Carbon::parse($payload['fecha_fin'])->toDateString(),
            'bloqueado_por_id' => $usuarioId,
            'estado' => 'ACTIVO',
            'visible_para_planner' => true,
        ]);
        $usuario = Usuario::query()->find($usuarioId);
        if ($usuario) {
            $this->operationalNotifications->bienestarBloqueoRegistrado($bloqueo->fresh(['personal']) ?: $bloqueo, $usuario);
        }

        return redirect()
            ->route('bienestar.show', ['id' => $trabajador->id, 'mes' => Carbon::parse($payload['fecha_inicio'])->format('Y-m')])
            ->with('success', 'Bloqueo registrado correctamente.');
    }

    public function createBloqueo(): View
    {
        $this->assertWellbeingPermission('crear');

        $trabajadores = Personal::query()
            ->with('fichaColaborador')
            ->select(['id', 'dni', 'nombre_completo', 'estado'])
            ->orderBy('nombre_completo')
            ->get();

        return view('bienestar.create-bloqueo', [
            'trabajadores' => $trabajadores,
        ]);
    }

    public function storeBloqueoGeneral(Request $request)
    {
        $this->assertWellbeingPermission('crear');

        $payload = $request->validate([
            'personal_id' => ['required', 'string', 'exists:personal,id'],
            'tipo' => ['required', 'string', 'max:40'],
            'otro_tipo' => ['nullable', 'string', 'max:40'],
            'motivo' => ['required', 'string', 'max:191'],
            'detalle' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ]);

        $tipo = trim((string) $payload['tipo']);
        if ($tipo === 'otro') {
            $otro = trim((string) ($payload['otro_tipo'] ?? ''));
            if ($otro === '') {
                return back()->withErrors(['otro_tipo' => 'Debes indicar el tipo cuando seleccionas "Otro".'])->withInput();
            }
            $tipo = Str::of($otro)->lower()->replace(' ', '_')->value();
        }

        $trabajador = Personal::query()->with('fichaColaborador')->findOrFail($payload['personal_id']);
        if ($tipo === 'gestacion' && !$this->isFemalePersonal($trabajador)) {
            return back()->withErrors(['tipo' => 'Solo se puede registrar gestacion para trabajadoras con sexo femenino en la ficha.'])->withInput();
        }

        $usuarioId = (string) (session('user.id') ?? session('user_id') ?? '');
        if ($usuarioId === '' || !Usuario::query()->where('id', $usuarioId)->exists()) {
            $usuarioId = (string) (Usuario::query()->value('id') ?? '');
        }

        if ($usuarioId === '') {
            return back()->withErrors(['tipo' => 'No existe un usuario válido para registrar el bloqueo.'])->withInput();
        }

        $bloqueo = PersonalBloqueo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $trabajador->id,
            'tipo' => $tipo,
            'motivo' => $payload['motivo'],
            'detalle' => $payload['detalle'] ?? null,
            'fecha_inicio' => Carbon::parse($payload['fecha_inicio'])->toDateString(),
            'fecha_fin' => Carbon::parse($payload['fecha_fin'])->toDateString(),
            'bloqueado_por_id' => $usuarioId,
            'estado' => 'ACTIVO',
            'visible_para_planner' => true,
        ]);
        $usuario = Usuario::query()->find($usuarioId);
        if ($usuario) {
            $this->operationalNotifications->bienestarBloqueoRegistrado($bloqueo->fresh(['personal']) ?: $bloqueo, $usuario);
        }

        return redirect()
            ->route('bienestar.show', ['id' => $trabajador->id, 'mes' => Carbon::parse($payload['fecha_inicio'])->format('Y-m')])
            ->with('success', 'Bloqueo registrado correctamente.');
    }

    public function editBloqueo(string $bloqueoId): View
    {
        $this->assertWellbeingPermission(['editar', 'actualizar']);

        $bloqueo = PersonalBloqueo::query()
            ->with(['personal:id,dni,nombre_completo'])
            ->findOrFail($bloqueoId);

        return view('bienestar.edit-bloqueo', [
            'bloqueo' => $bloqueo,
            'trabajador' => $bloqueo->personal,
        ]);
    }

    public function updateBloqueo(Request $request, string $bloqueoId)
    {
        $this->assertWellbeingPermission(['editar', 'actualizar']);

        $bloqueo = PersonalBloqueo::query()->with('personal.fichaColaborador')->findOrFail($bloqueoId);

        $payload = $request->validate([
            'tipo' => ['required', 'string', 'max:40'],
            'otro_tipo' => ['nullable', 'string', 'max:40'],
            'motivo' => ['required', 'string', 'max:191'],
            'detalle' => ['nullable', 'string'],
            'fecha_inicio' => ['required', 'date'],
            'fecha_fin' => ['required', 'date', 'after_or_equal:fecha_inicio'],
        ]);

        $tipo = trim((string) $payload['tipo']);
        if ($tipo === 'otro') {
            $otro = trim((string) ($payload['otro_tipo'] ?? ''));
            if ($otro === '') {
                return back()->withErrors(['otro_tipo' => 'Debes indicar el tipo cuando seleccionas "Otro".'])->withInput();
            }
            $tipo = Str::of($otro)->lower()->replace(' ', '_')->value();
        }

        if ($tipo === 'gestacion' && (!$bloqueo->personal || !$this->isFemalePersonal($bloqueo->personal))) {
            return back()->withErrors(['tipo' => 'Solo se puede registrar gestacion para trabajadoras con sexo femenino en la ficha.'])->withInput();
        }

        $bloqueo->update([
            'tipo' => $tipo,
            'motivo' => $payload['motivo'],
            'detalle' => $payload['detalle'] ?? null,
            'fecha_inicio' => Carbon::parse($payload['fecha_inicio'])->toDateString(),
            'fecha_fin' => Carbon::parse($payload['fecha_fin'])->toDateString(),
        ]);

        return redirect()
            ->route('bienestar.index')
            ->with('success', 'Bloqueo actualizado correctamente.');
    }

    public function anularBloqueo(string $bloqueoId)
    {
        $this->assertWellbeingPermission(['eliminar', 'anular']);

        $bloqueo = PersonalBloqueo::query()->with('personal')->findOrFail($bloqueoId);

        $bloqueo->update([
            'estado' => 'ANULADO',
        ]);
        $usuarioId = (string) (session('user.id') ?? session('user_id') ?? '');
        $usuario = $usuarioId !== '' ? Usuario::query()->find($usuarioId) : null;
        if ($usuario) {
            $this->operationalNotifications->bienestarBloqueoAnulado($bloqueo->fresh(['personal']) ?: $bloqueo, $usuario);
        }

        return back()->with('success', 'Bloqueo anulado correctamente.');
    }

    private function resolveDate(?string $value, string $default): string
    {
        if (!$value) {
            return $default;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return $default;
        }
    }

    private function assertWellbeingPermission(string|array $actions): void
    {
        abort_unless(
            PermissionMatrix::allowsAny(session('user.permissions', []), 'bienestar', is_array($actions) ? $actions : [$actions]),
            403,
            'No tienes permiso para realizar esta accion.',
        );
    }

    private function resolveMonthStart(string $monthInput): Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth();
        } catch (\Throwable) {
            return Carbon::today()->startOfMonth();
        }
    }

    private function isFemalePersonal(Personal $personal): bool
    {
        $personal->loadMissing('fichaColaborador');
        $data = is_array($personal->fichaColaborador?->datos_json ?? null)
            ? $personal->fichaColaborador->datos_json
            : [];

        $sexo = Str::lower(trim((string) ($data['sexo'] ?? '')));

        return $sexo !== '' && (str_starts_with($sexo, 'f') || in_array($sexo, ['mujer', 'femenino'], true));
    }

    private function buildCalendar(Carbon $monthStart, Collection $bloqueos): array
    {
        $start = $monthStart->copy()->startOfMonth();
        $end = $monthStart->copy()->endOfMonth();
        $firstWeekDay = (int) $start->isoWeekday();
        $days = [];

        for ($i = 1; $i < $firstWeekDay; $i++) {
            $days[] = null;
        }

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $dayString = $cursor->toDateString();
            $matches = $bloqueos->filter(function (PersonalBloqueo $bloqueo) use ($dayString): bool {
                $inicio = optional($bloqueo->fecha_inicio)->toDateString();
                $fin = optional($bloqueo->fecha_fin)->toDateString();

                return $inicio !== null && $fin !== null && $inicio <= $dayString && $fin >= $dayString;
            })->values();

            $primary = $matches
                ->sortBy(function (PersonalBloqueo $bloqueo): int {
                    return match ((string) $bloqueo->tipo) {
                        'gestacion' => 1,
                        'descanso_medico' => 1,
                        'inhabilitado' => 2,
                        'restriccion_temporal' => 3,
                        'vacaciones' => 4,
                        default => 5,
                    };
                })
                ->first();

            $tipoKey = $primary ? (string) $primary->tipo : null;

            $days[] = [
                'date' => $dayString,
                'day' => (int) $cursor->day,
                'has_bloqueo' => $matches->isNotEmpty(),
                'bloqueos' => $matches,
                'tipo_key' => $tipoKey,
            ];
        }

        while (count($days) % 7 !== 0) {
            $days[] = null;
        }

        return array_chunk($days, 7);
    }
}
