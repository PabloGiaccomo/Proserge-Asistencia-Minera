<?php

namespace App\Modules\Evaluaciones\Services;

use App\Models\AsistenciaHistorica;
use App\Models\EvaluacionDesempeno;
use App\Models\EvaluacionesImportLote;
use App\Models\EvaluacionResidente;
use App\Models\EvaluacionSupervisor;
use App\Models\Personal;
use App\Models\Usuario;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class EvaluacionesHistoricalImportService
{
    public function __construct(
        private readonly EvaluacionesHistoricalWorkbookReader $reader,
        private readonly EvaluacionesHistoricalReconciler $reconciler,
        private readonly EvaluacionesHistoricalMapper $mapper,
        private readonly PromedioDesempenoService $promedios,
    ) {}

    public function isOpen(): bool
    {
        return (bool) config('evaluaciones.historical_import_enabled')
            && ! EvaluacionesImportLote::query()->where('confirmation_key', 'HISTORICAL')->exists();
    }

    public function previewSupervisors(Usuario $actor, string $path): array
    {
        return $this->preview($actor, ['control' => $path], true);
    }

    public function confirmSupervisors(Usuario $actor, string $loteId, string $expectedHash): array
    {
        return $this->confirm($actor, $loteId, $expectedHash, true);
    }

    public function preview(Usuario $actor, array $paths, bool $supervisorsOnly = false): array
    {
        if (! $this->authorized($actor)) {
            return $this->error('EVAL_HIST_FORBIDDEN', 'No autorizado.');
        }
        if (! $this->isOpen()) {
            return $this->error('EVAL_HIST_CLOSED', 'La carga historica esta cerrada.');
        }
        if ($supervisorsOnly ? (count($paths) !== 1 || ! isset($paths['control'])) : (count($paths) !== 2 || ! isset($paths['control'], $paths['consolidado']))) {
            return $this->error('EVAL_HIST_FILES', 'Se requieren ambos libros historicos.');
        }
        foreach ($paths as $path) {
            if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
                return $this->error('EVAL_HIST_FILES', 'No se pudo leer uno de los libros.');
            }
        }

        $id = (string) Str::uuid();
        $base = "evaluaciones-historicas/{$id}";
        $disk = Storage::disk('local');
        foreach ($paths as $source => $path) {
            if (! $disk->put("{$base}/{$source}.xlsx", file_get_contents($path))) {
                throw new RuntimeException('No se pudo guardar el libro historico en el disco privado.');
            }
        }

        try {
            $storedPaths = $this->paths($base, $supervisorsOnly);
            $hash = $this->fileHash($storedPaths);
            $manifest = $this->buildManifest($storedPaths, $supervisorsOnly);
            $manifestPath = "{$base}/manifest.json";
            $encoded = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
            if (! $disk->put($manifestPath, $encoded)) {
                throw new RuntimeException('No se pudo guardar la revision historica.');
            }

            $counts = $this->counts($manifest);
            EvaluacionesImportLote::query()->create([
                'id' => $id,
                'hash' => $hash,
                'estado' => 'PREVIEW',
                'manifest_path' => $manifestPath,
                'resumen' => $counts,
                'created_by_usuario_id' => $actor->id,
            ]);

            return ['ok' => true, 'lote_id' => $id, 'hash' => $hash, 'counts' => $counts, 'manifest' => $manifest];
        } catch (\Throwable $exception) {
            $disk->deleteDirectory($base);
            throw $exception;
        }
    }

    public function confirm(Usuario $actor, string $loteId, string $expectedHash, bool $supervisorsOnly = false): array
    {
        if (! $this->authorized($actor)) {
            return $this->error('EVAL_HIST_FORBIDDEN', 'No autorizado.');
        }
        if (! $this->isOpen()) {
            return $this->error('EVAL_HIST_CLOSED', 'La carga historica esta cerrada.');
        }

        try {
            return DB::transaction(function () use ($actor, $loteId, $expectedHash, $supervisorsOnly): array {
                $batch = EvaluacionesImportLote::query()->whereKey($loteId)->lockForUpdate()->first();
                if (! $batch || $batch->estado !== 'PREVIEW' || ! hash_equals((string) $batch->hash, $expectedHash)) {
                    return $this->error('EVAL_HIST_STALE', 'La revision ya no corresponde al lote actual.');
                }
                if (! $this->isOpen()) {
                    return $this->error('EVAL_HIST_CLOSED', 'La carga historica esta cerrada.');
                }

                $base = "evaluaciones-historicas/{$batch->id}";
                $stored = Storage::disk('local')->get((string) $batch->manifest_path);
                if (! is_string($stored)) {
                    return $this->error('EVAL_HIST_STALE', 'No se encontro el reporte revisado.');
                }
                $previous = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
                if ($supervisorsOnly && (($previous['scope'] ?? null) !== 'SUPERVISORS'
                    || collect($previous['proposed'] ?? [])->contains(fn ($row) => ($row['type'] ?? null) !== 'supervisor'))) {
                    return $this->error('EVAL_HIST_SCOPE', 'Esta revision anterior incluye otros tipos. Revisa nuevamente solo el archivo de supervisores.');
                }
                $scopeIsSupervisors = ($previous['scope'] ?? null) === 'SUPERVISORS';
                $paths = $this->paths($base, $scopeIsSupervisors);
                if (! hash_equals($expectedHash, $this->fileHash($paths))) {
                    return $this->error('EVAL_HIST_CHANGED', 'Los archivos cambiaron desde la revision.');
                }
                $workerIds = collect($previous['proposed'] ?? [])->pluck('trabajador_id')->filter()->unique()->sort()->values();
                foreach ($workerIds as $workerId) {
                    Personal::query()->whereKey($workerId)->lockForUpdate()->firstOrFail();
                }
                $manifest = $this->buildManifest($paths, $scopeIsSupervisors);
                if ($this->manifestHash($previous) !== $this->manifestHash($manifest)) {
                    return $this->error('EVAL_HIST_STALE', 'Los datos cambiaron. Revisa los libros nuevamente.');
                }
                if (! empty($manifest['blocked'])) {
                    return $this->error('EVAL_HIST_BLOCKED', 'Corrige todas las filas observadas antes de confirmar.');
                }
                if (empty($manifest['proposed'])) {
                    return $this->error('EVAL_HIST_EMPTY', 'No hay cambios propuestos para confirmar.');
                }

                $batch->fill([
                    'confirmation_key' => 'HISTORICAL',
                    'estado' => 'CONFIRMADO',
                    'confirmed_by_usuario_id' => $actor->id,
                    'confirmed_at' => now(),
                ])->save();

                $affected = [];
                foreach ($manifest['proposed'] as $entry) {
                    $attributes = [
                        ...$entry['payload'],
                        'id' => (string) Str::uuid(),
                        'import_lote_id' => $batch->id,
                    ];
                    match ($entry['type']) {
                        'attendance' => AsistenciaHistorica::query()->create([
                            ...$attributes, 'lote_id' => $batch->id,
                        ]),
                        'daily' => EvaluacionDesempeno::query()->create($attributes),
                        'supervisor' => EvaluacionSupervisor::query()->create($attributes),
                        'resident' => EvaluacionResidente::query()->create($attributes),
                    };
                    if ($entry['type'] === 'daily') {
                        $affected[$entry['trabajador_id']] = true;
                    }
                }

                foreach (array_keys($affected) as $workerId) {
                    $this->promedios->refreshForTrabajador($workerId);
                }

                return ['ok' => true, 'lote_id' => $batch->id, 'counts' => $this->counts($manifest)];
            }, 1);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                return $this->error('EVAL_HIST_CONFLICT', 'Otro lote o registro historico ya fue confirmado.');
            }
            throw $exception;
        }
    }

    private function authorized(Usuario $actor): bool
    {
        return PermissionMatrix::userCanDirect($actor, 'evaluaciones', 'administrar');
    }

    private function paths(string $base, bool $supervisorsOnly = false): array
    {
        $disk = Storage::disk('local');

        if ($supervisorsOnly) {
            return ['control' => $disk->path("{$base}/control.xlsx")];
        }

        return [
            'control' => $disk->path("{$base}/control.xlsx"),
            'consolidado' => $disk->path("{$base}/consolidado.xlsx"),
        ];
    }

    private function fileHash(array $paths): string
    {
        foreach ($paths as $path) {
            if (! is_file($path)) {
                throw new RuntimeException('Falta uno de los libros historicos privados.');
            }
        }

        return hash('sha256', implode('|', array_map('hash_file', array_fill(0, count($paths), 'sha256'), array_values($paths))));
    }

    private function buildManifest(array $paths, bool $supervisorsOnly = false): array
    {
        $manifest = $this->mapper->map($this->reconciler->reconcile($this->reader->read($paths, $supervisorsOnly)));

        return $supervisorsOnly ? [...$manifest, 'scope' => 'SUPERVISORS'] : $manifest;
    }

    private function manifestHash(array $manifest): string
    {
        return hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    private function counts(array $manifest): array
    {
        return [
            'proposed' => count($manifest['proposed']),
            'unchanged' => count($manifest['unchanged']),
            'blocked' => count($manifest['blocked']),
            'warnings' => count($manifest['warnings']),
        ];
    }

    private function error(string $code, string $message): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message];
    }
}
