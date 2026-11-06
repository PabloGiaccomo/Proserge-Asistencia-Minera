<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalIngreso;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaAccess;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PersonalIngresoBatchReviewService
{
    public function __construct(private readonly PersonalIngresoService $ingresos) {}

    public function preview(Usuario $actor, array $selected): array
    {
        $this->authorize($actor);
        $ids = array_column($selected, 'ingreso_id');
        $sources = PersonalIngreso::query()->with(['archivos', 'personalExistente:id,nombre_completo'])
            ->whereIn('id', $ids)->whereNotNull('submitted_at')->get()->keyBy('id');
        abort_unless($sources->count() === count($ids), 404);
        $duplicates = $sources->filter(fn ($row) => $this->purpose($row) === 'ALTA')
            ->countBy(fn ($row) => PersonalNormalizer::documentNumber($row->numero_documento));
        $id = (string) Str::uuid();
        $revision = bin2hex(random_bytes(32));

        DB::transaction(function () use ($actor, $selected, $sources, $duplicates, $id, $revision): void {
            DB::table('personal_ingreso_lotes')->insert([
                'id' => $id, 'usuario_id' => $actor->id, 'revision' => $revision, 'expires_at' => now()->addHours(2),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($selected as $order => $selection) {
                $source = $sources[$selection['ingreso_id']];
                $purpose = $this->purpose($source);
                $start = $purpose === 'ALTA' ? ($selection['fecha_inicio_contrato'] ?? null) : null;
                $end = $purpose === 'ALTA' ? ($selection['fecha_fin_contrato'] ?? null) : null;
                $problem = $purpose === 'ALTA' && $duplicates->get(PersonalNormalizer::documentNumber($source->numero_documento), 0) > 1
                    ? 'Hay varias fichas con este documento. Revisa cuál corresponde.'
                    : $this->reviewProblem($source, $actor, $start);
                $this->rows($id)->insert([
                    'id' => (string) Str::uuid(), 'lote_id' => $id, 'ingreso_id' => $source->id, 'orden' => $order,
                    'proposito' => $purpose, 'source_hash' => $this->sourceHash($source, $actor),
                    'fecha_inicio_contrato' => $start, 'fecha_fin_contrato' => $end,
                    'estado' => $problem ? 'REVISAR' : 'LISTA', 'mensaje' => $problem,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return $this->results($actor, $id);
    }

    public function recent(Usuario $actor): array
    {
        $permissions = PersonalFichaAccess::permissionsFor($actor);
        if (! PermissionMatrix::allowsDirect($permissions, 'personal_ingresos', 'aprobar')) {
            return [];
        }

        return DB::table('personal_ingreso_lotes as lotes')
            ->where('lotes.usuario_id', $actor->id)
            ->orderByDesc('lotes.created_at')->orderByDesc('lotes.id')->limit(10)
            ->select(['lotes.id', 'lotes.created_at', 'lotes.expires_at'])
            ->selectSub(fn ($query) => $query->from('personal_ingreso_lote_filas')
                ->whereColumn('lote_id', 'lotes.id')->selectRaw('COUNT(*)'), 'total')
            ->selectSub(fn ($query) => $query->from('personal_ingreso_lote_filas')
                ->whereColumn('lote_id', 'lotes.id')->where('estado', 'ACEPTADA')->selectRaw('COUNT(*)'), 'aceptadas')
            ->get()->map(fn ($lot) => [
                'id' => $lot->id,
                'fecha' => Carbon::parse($lot->created_at)->setTimezone(config('app.display_timezone', 'America/Lima'))->format('d/m/Y H:i'),
                'vencido' => $lot->expires_at <= now()->toDateTimeString(),
                'total' => (int) $lot->total, 'aceptadas' => (int) $lot->aceptadas,
            ])->all();
    }

    public function results(Usuario $actor, string $id): array
    {
        $lot = $this->lot($actor, $id);
        $rows = $this->rows($id)->orderBy('orden')->get();
        $sources = PersonalIngreso::query()->with('personalExistente:id,nombre_completo')
            ->whereIn('id', $rows->pluck('ingreso_id'))->get()->keyBy('id');

        return [
            'id' => $id, 'revision' => $lot->revision, 'expires_at' => $lot->expires_at,
            'vencido' => $lot->expires_at <= now()->toDateTimeString(),
            'filas' => $rows->map(function ($row) use ($sources): array {
                $source = $sources->get($row->ingreso_id);
                $data = $source?->datos_json ?? [];
                $name = trim(implode(' ', [$data['apellido_paterno'] ?? '', $data['apellido_materno'] ?? '', $data['nombres'] ?? '']));

                return [
                    'id' => $row->id, 'ingreso_id' => $row->ingreso_id, 'proposito' => $row->proposito,
                    'trabajador' => $source?->personalExistente?->nombre_completo ?? ($name ?: 'Ficha sin nombre'),
                    'fecha_inicio_contrato' => $row->fecha_inicio_contrato, 'fecha_fin_contrato' => $row->fecha_fin_contrato,
                    'estado' => $row->estado, 'mensaje' => $row->mensaje, 'contrato_id' => $row->contrato_id,
                ];
            })->all(),
        ];
    }

    public function confirm(Usuario $actor, string $id, string $revision, array $selected): array
    {
        $lot = $this->lot($actor, $id);
        abort_unless(hash_equals($lot->revision, $revision), 409, 'Abre la vista previa actual antes de confirmar.');
        abort_unless($this->rows($id)->whereIn('id', $selected)->count() === count($selected), 404);
        $results = [];
        foreach ($selected as $row) {
            $results[] = $this->confirmOne($actor, $id, $row);
        }

        return $results;
    }

    public function reviewDetails(Usuario $actor, string $id, string $rowId): array
    {
        $lot = $this->lot($actor, $id);
        abort_if($lot->expires_at <= now()->toDateTimeString(), 410, 'La revisión venció. Prepara otro lote.');
        $row = $this->rows($id)->where('id', $rowId)->first();
        abort_unless($row, 404);
        abort_unless($row->estado === 'LISTA', 409, 'Consulta el resultado de esta fila antes de continuar.');
        $source = PersonalIngreso::query()->with('archivos')->findOrFail($row->ingreso_id);
        abort_unless(hash_equals($row->source_hash, $this->sourceHash($source, $actor)), 409, 'La ficha cambió. Prepara una nueva revisión.');
        $problem = $this->reviewProblem($source, $actor, $row->fecha_inicio_contrato);
        abort_if($problem !== null, 409, $problem);
        $catalog = PersonalFichaCatalog::fields();
        $families = [];
        if ($row->proposito === 'ACTUALIZACION') {
            $comparison = app(PersonalIngresoRevisionService::class)->compare($source->id, $actor);
            $fields = collect($comparison['changes'])->map(fn ($change, $key) => [
                'campo' => $catalog[$key]['label'] ?? $key,
                'antes' => $change['before'], 'propuesto' => $change['after'],
            ])->values()->all();
            $families = collect($comparison['family_changes'] ?? [])->map(
                fn ($family) => $this->familyDetails($family['after'], $family['message']),
            )->values()->all();
        } else {
            $data = $this->ingresos->dataForForm($source);
            if (! PersonalFichaAccess::canSensitive($actor)) {
                $data = PersonalFichaAccess::redact($data);
            }
            $fields = collect($catalog)->filter(fn ($definition, $key) => array_key_exists($key, $data) && filled($data[$key]))
                ->map(fn ($definition, $key) => [
                    'campo' => $definition['label'], 'antes' => null, 'propuesto' => $data[$key],
                ])->values()->all();
            $families = collect($source->familiares_json ?? [])
                ->filter(fn ($family) => is_array($family) && filled($family['nombres_apellidos'] ?? null))
                ->map(fn ($family) => $this->familyDetails($family, 'Familiar declarado en el alta'))
                ->values()->all();
        }

        return ['proposito' => $row->proposito, 'campos' => $fields, 'familiares' => $families];
    }

    private function familyDetails(array $family, string $message): array
    {
        $labels = [
            'nombres_apellidos' => 'Apellidos y nombres', 'parentesco' => 'Parentesco',
            'fecha_nacimiento' => 'Fecha de nacimiento', 'tipo_documento' => 'Tipo de documento',
            'numero_documento' => 'Número de documento', 'telefono' => 'Teléfono',
            'vive_con_trabajador' => 'Vive con el trabajador', 'estudia' => 'Estudia',
            'contacto_emergencia' => 'Contacto de emergencia',
        ];
        $fields = [];
        foreach ($labels as $key => $label) {
            $value = $family[$key] ?? '';
            $boolean = in_array($key, ['vive_con_trabajador', 'estudia', 'contacto_emergencia'], true);
            $fields[] = [
                'campo' => $label,
                'propuesto' => $boolean ? (filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'Sí' : 'No')
                    : (is_scalar($value) ? (string) $value : ''),
            ];
        }

        return ['mensaje' => $message, 'campos' => $fields];
    }

    private function confirmOne(Usuario $actor, string $id, string $rowId): array
    {
        $query = $this->rows($id)->where('id', $rowId);
        $newFiles = [];
        try {
            return DB::transaction(function () use ($actor, $id, $query, &$newFiles): array {
                $lot = $this->lot($actor, $id, lock: true);
                $row = (clone $query)->lockForUpdate()->first();
                if ($row->estado !== 'LISTA') {
                    return $this->status($row);
                }
                abort_if($lot->expires_at <= now()->toDateTimeString(), 410, 'El lote venció. Prepara una nueva revisión.');
                $source = PersonalIngreso::query()->with('archivos')
                    ->when($row->proposito === 'ALTA', fn ($builder) => $builder->lockForUpdate())->find($row->ingreso_id);
                $problem = ! $source || $row->source_hash !== $this->sourceHash($source, $actor)
                    ? 'La ficha cambió después de la revisión. Abre la ficha para revisarla de nuevo.'
                    : $this->reviewProblem($source, $actor, $row->fecha_inicio_contrato, currentRead: true);
                if ($problem) {
                    (clone $query)->where('estado', 'LISTA')->update(['estado' => 'REVISAR', 'mensaje' => $problem, 'updated_at' => now()]);

                    return $this->status($query->first());
                }

                $contractId = null;
                if ($row->proposito === 'ACTUALIZACION') {
                    $reviews = app(PersonalIngresoRevisionService::class);
                    $comparison = $reviews->compare($source->id, $actor);
                    abort_unless(hash_equals($row->source_hash, $comparison['review_hash']), 409, 'La ficha cambió.');
                    $person = $reviews->acceptUpdate($source->id, $actor, $comparison['revision'], $comparison['base_hash'], $comparison['review_hash']);
                } else {
                    $person = $this->ingresos->acceptNewFromBatch($source, $actor, [
                        'fecha_inicio_contrato' => $row->fecha_inicio_contrato, 'fecha_fin_contrato' => $row->fecha_fin_contrato,
                    ], $newFiles);
                    $contractId = $person->contratosLaborales->firstWhere('estado', PersonalContrato::ESTADO_PREPARACION)?->id;
                    abort_unless($contractId, 500, 'No se pudo registrar el periodo preparado.');
                }
                $query->update([
                    'estado' => 'ACEPTADA', 'mensaje' => $contractId ? 'Ficha agregada. Falta adjuntar el contrato firmado.' : 'Ficha actualizada.',
                    'personal_id' => $person->id, 'contrato_id' => $contractId, 'updated_at' => now(),
                ]);

                return $this->status($query->first());
            });
        } catch (\Throwable $exception) {
            $this->removeUnreferenced($newFiles);
            $conflict = $exception instanceof HttpExceptionInterface
                && $exception->getStatusCode() === 409;
            if ($conflict || $exception instanceof ValidationException || $exception instanceof UniqueConstraintViolationException) {
                $message = $exception instanceof ValidationException ? (string) collect($exception->errors())->flatten()->first()
                    : 'La ficha o el documento cambió. Revisa este ingreso individualmente.';
                (clone $query)->where('estado', 'LISTA')->update(['estado' => 'REVISAR', 'mensaje' => Str::limit($message, 250), 'updated_at' => now()]);
            } elseif ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                throw $exception;
            } else {
                Log::warning('personal.ingresos_lote_fallo', ['lote_id' => $id, 'fila_id' => $rowId, 'exception' => $exception::class]);
                (clone $query)->where('estado', 'LISTA')->update([
                    'mensaje' => 'No se pudo completar. Consulta el resultado y vuelve a intentar esta fila.', 'updated_at' => now(),
                ]);
            }

            return $this->status($query->first());
        }
    }

    private function reviewProblem(PersonalIngreso $source, Usuario $actor, ?string $start, bool $currentRead = false): ?string
    {
        $eligibleStates = $this->purpose($source) === 'ACTUALIZACION'
            ? [PersonalIngreso::ESTADO_RECIBIDA]
            : [PersonalIngreso::ESTADO_RECIBIDA, PersonalIngreso::ESTADO_FALTA_REVISION];
        if (! in_array($source->estado, $eligibleStates, true)) {
            return 'Revisa esta ficha individualmente por su estado actual.';
        }
        if ($this->purpose($source) === 'ACTUALIZACION') {
            $review = app(PersonalIngresoRevisionService::class)->compare($source->id, $actor);

            return ! $review['completeness']['complete'] || $review['conflict'] || $review['completeness']['sensitive_pending']
                ? 'Revisa los cambios y requisitos de esta actualización.' : null;
        }
        $data = $source->datos_json ?? [];
        $document = PersonalNormalizer::documentNumber($source->numero_documento);
        if ($source->personal_existente_id || $source->personal_creado_id || Personal::query()
            ->where(fn ($query) => $query->where('dni', $document)->orWhere('numero_documento', $document))->exists()) {
            return 'El documento corresponde a un trabajador existente. Revisa la ficha individualmente.';
        }
        if (! $start) {
            return 'Indica la fecha de inicio del contrato.';
        }
        $required = ['nombres', 'apellido_paterno', 'apellido_materno', 'puesto', 'correo', 'telefono'];
        foreach ($required as $key) {
            if (blank($data[$key] ?? null)) {
                return 'Hay datos obligatorios pendientes. Abre la ficha para completarlos.';
            }
        }
        if (! PersonalNormalizer::isValidDocument($source->tipo_documento, $document)
            || $document !== PersonalNormalizer::documentNumber($data['numero_documento'] ?? '')
            || ! filter_var($data['correo'], FILTER_VALIDATE_EMAIL)
            || ! PersonalPuesto::query()->where('nombre', $data['puesto'])->where('activo', true)->exists()) {
            return 'Verifica el documento, el correo y el puesto de la ficha.';
        }
        $paths = $source->archivos->pluck('path')->filter();
        if (filled($source->huella_path)) {
            $paths->push($source->huella_path);
        }
        $sensitive = filled($source->firma_base64) || filled($source->huella_path) || $source->archivos->contains('tipo', 'huella')
            || collect(PersonalFichaAccess::onlySensitive($data))->contains(fn ($value) => filled($value));
        if ($sensitive && ! PersonalFichaAccess::canSensitive($actor, $currentRead)) {
            return 'Esta ficha requiere revisión por RR. HH. con permiso para datos sensibles.';
        }
        if ($paths->contains(fn ($path) => ! Storage::disk('local')->exists($path))) {
            return 'Falta un archivo de la ficha. Revisa los documentos individualmente.';
        }

        return null;
    }

    private function sourceHash(PersonalIngreso $source, Usuario $actor): string
    {
        if ($this->purpose($source) === 'ACTUALIZACION' && $source->estado === PersonalIngreso::ESTADO_RECIBIDA) {
            return app(PersonalIngresoRevisionService::class)->compare($source->id, $actor)['review_hash'];
        }

        return hash_hmac('sha256', json_encode([
            $source->getRawOriginal(),
            $source->archivos->sortBy('id')->map(fn ($file) => $file->getRawOriginal())->values()->all(),
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function purpose(PersonalIngreso $source): string
    {
        return $source->proposito === 'ACTUALIZACION' ? 'ACTUALIZACION' : 'ALTA';
    }

    private function lot(Usuario $actor, string $id, bool $lock = false): object
    {
        $this->authorize($actor);
        $lot = DB::table('personal_ingreso_lotes')->where('id', $id)->where('usuario_id', $actor->id)
            ->when($lock, fn ($query) => $query->lockForUpdate())->first();
        abort_unless($lot, 404);
        if ($lock) {
            $this->authorize($actor, currentRead: true);
        }

        return $lot;
    }

    private function authorize(Usuario $actor, bool $currentRead = false): void
    {
        $permissions = PersonalFichaAccess::permissionsFor($actor, $currentRead);
        abort_unless(PermissionMatrix::allowsDirect($permissions, 'personal_ingresos', 'ver')
            && PermissionMatrix::allowsDirect($permissions, 'personal_ingresos', 'aprobar'), 403);
    }

    private function rows(string $id): Builder
    {
        return DB::table('personal_ingreso_lote_filas')->where('lote_id', $id);
    }

    private function status(object $row): array
    {
        return ['id' => $row->id, 'estado' => $row->estado, 'mensaje' => $row->mensaje];
    }

    private function removeUnreferenced(array $paths): void
    {
        foreach ($paths as $path) {
            if (! PersonalFicha::query()->where('huella_path', $path)->exists()
                && ! PersonalFichaArchivo::query()->where('path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }
}
