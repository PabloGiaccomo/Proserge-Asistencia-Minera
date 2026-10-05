<?php

namespace App\Modules\Personal\Services;

use App\Models\ExamenMinero;
use App\Models\ExamenMineroAlias;
use App\Models\ExamenMineroAsociacion;
use App\Models\ExamenMineroCatalogoCambio;
use App\Models\Mina;
use App\Models\MinaRequisito;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MiningExamCatalogOrganizationService
{
    public function __construct(private MiningExamIdentityResolver $identity, private MiningExamCatalogAuditService $audit) {}

    public function report(array $filters = []): array
    {
        $report = $this->audit->report();
        $report['schema_ready'] = $this->identity->schemaReady();
        $report['asociaciones'] = $report['schema_ready'] ? ExamenMineroAsociacion::query()->orderBy('id')->get()->toArray() : [];
        $report['aliases'] = $report['schema_ready'] ? ExamenMineroAlias::query()->orderBy('id')->get()->toArray() : [];
        $report['cambios'] = $report['schema_ready'] ? ExamenMineroCatalogoCambio::query()->latest()->limit(20)->get()->toArray() : [];
        $report['minas'] = Mina::query()->activeOperational()->orderBy('nombre')->get(['id', 'nombre'])->toArray();

        return $report;
    }

    public function preview(array $payload, Usuario $actor): array
    {
        $this->assertReady();
        $payload = Validator::make($payload, [
            'accion' => ['required', 'in:asociar,renombrar,pendiente'],
            'principal_id' => ['required', 'uuid'], 'variante_ids' => ['nullable', 'array', 'max:100'],
            'variante_ids.*' => ['required', 'uuid', 'distinct'], 'nombre' => ['nullable', 'string', 'max:191'],
            'aliases' => ['nullable', 'array', 'max:100'], 'aliases.*.nombre' => ['required', 'string', 'max:191'],
            'aliases.*.mina_id' => ['nullable', 'uuid'], 'motivo' => ['required', 'string', 'min:5', 'max:1000'],
        ])->validate();
        $payload['motivo'] = trim($payload['motivo']);
        if (mb_strlen($payload['motivo']) < 5) {
            $this->invalid('Escribe el motivo de la correccion.');
        }
        $principal = ExamenMinero::query()->findOrFail($payload['principal_id']);
        if ($this->identity->canonicalId($principal->id) !== $principal->id) {
            $this->invalid('El examen principal elegido ya pertenece a otro grupo.');
        }
        $variants = $payload['accion'] === 'asociar' ? ($payload['variante_ids'] ?? []) : [];
        foreach ($variants as $variantId) {
            ExamenMinero::query()->findOrFail($variantId);
            if ($variantId === $principal->id || ExamenMineroAsociacion::query()->where('principal_id', $variantId)->exists()
                || ! in_array($this->identity->canonicalId($variantId), [$variantId, $principal->id], true)) {
                $this->invalid('No se puede crear un ciclo, mover un grupo ni reasignar una variante de otro principal.');
            }
        }
        $payload['variante_ids'] = $variants;
        $aliases = $payload['accion'] === 'pendiente' ? [] : ($payload['aliases'] ?? []);
        if ($payload['accion'] === 'renombrar') {
            $payload['nombre'] = Str::squish($payload['nombre'] ?? '');
            if ($payload['nombre'] === '') {
                $this->invalid('Indica el nombre correcto.');
            }
            $aliases[] = ['nombre' => $principal->nombre, 'mina_id' => null];
            $this->assertNameAvailable($payload['nombre'], null, $principal->id, $variants);
        }
        foreach ($aliases as &$alias) {
            $alias = ['nombre' => Str::squish($alias['nombre']), 'mina_id' => $alias['mina_id'] ?? null];
            if ($alias['mina_id'] && ! Mina::query()->activeOperational()->whereKey($alias['mina_id'])->exists()) {
                $this->invalid('La mina del nombre alternativo no esta disponible.');
            }
            $this->assertNameAvailable($alias['nombre'], $alias['mina_id'], $principal->id, $variants);
        }
        unset($alias);
        // Existing exact names and aliases of every selected variant must remain unambiguous.
        foreach ($variants as $variantId) {
            $this->assertNameAvailable(ExamenMinero::query()->findOrFail($variantId)->nombre, null, $principal->id, $variants);
        }
        $payload['aliases'] = $aliases;
        $snapshot = $this->snapshot();
        $group = array_unique(array_merge($this->identity->groupIds($principal->id), $variants));

        return ['payload' => $payload, 'actor_id' => $actor->id, 'snapshot' => $snapshot,
            'fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'impacto' => collect($snapshot['report']['examenes'])->whereIn('id', $group)->values()->all()];
    }

    public function confirm(array $preview, Usuario $actor): array
    {
        $this->assertReady();
        if (($preview['actor_id'] ?? null) !== $actor->id) {
            $this->invalid('La revision pertenece a otro usuario.');
        }

        return DB::transaction(function () use ($preview, $actor): array {
            ExamenMinero::query()->orderBy('id')->lockForUpdate()->get();
            ExamenMineroAsociacion::query()->orderBy('id')->lockForUpdate()->get();
            ExamenMineroAlias::query()->orderBy('id')->lockForUpdate()->get();
            MinaRequisito::query()->orderBy('id')->lockForUpdate()->get();
            $fresh = $this->preview($preview['payload'], $actor);
            if (! hash_equals($preview['fingerprint'], $fresh['fingerprint'])) {
                $this->invalid('El catalogo cambio despues de la revision. Revisa nuevamente antes de confirmar.');
            }
            $payload = $fresh['payload'];
            if ($payload['accion'] === 'asociar') {
                foreach ($payload['variante_ids'] as $variantId) {
                    ExamenMineroAsociacion::query()->updateOrCreate(['variante_id' => $variantId],
                        ['id' => ExamenMineroAsociacion::query()->where('variante_id', $variantId)->value('id') ?? (string) Str::uuid(),
                            'principal_id' => $payload['principal_id'], 'usuario_id' => $actor->id, 'motivo' => $payload['motivo']]);
                }
            }
            if ($payload['accion'] === 'renombrar') {
                ExamenMinero::query()->findOrFail($payload['principal_id'])->update([
                    'nombre' => $payload['nombre'], 'updated_by_usuario_id' => $actor->id]);
            }
            foreach ($payload['aliases'] as $alias) {
                ExamenMineroAlias::query()->firstOrCreate([
                    'clave' => hash('sha256', $this->identity->normalizedName($alias['nombre'])),
                    'scope_key' => $alias['mina_id'] ?: 'global',
                ], ['id' => (string) Str::uuid(), 'examen_id' => $payload['principal_id'],
                    'nombre' => $alias['nombre'], 'mina_id' => $alias['mina_id'], 'usuario_id' => $actor->id]);
            }
            ExamenMineroCatalogoCambio::query()->create(['id' => (string) Str::uuid(), 'usuario_id' => $actor->id,
                'accion' => $payload['accion'], 'motivo' => $payload['motivo'],
                'antes_json' => $fresh['snapshot'], 'despues_json' => $this->snapshot()]);

            return ['principal_id' => $payload['principal_id'], 'accion' => $payload['accion']];
        }, 3);
    }

    private function assertNameAvailable(string $name, ?string $mineId, string $principalId, array $variants): void
    {
        $key = $this->identity->normalizedName($name);
        if ($key === '') {
            $this->invalid('El nombre alternativo debe contener letras o numeros.');
        }
        $allowed = array_unique(array_merge($this->identity->groupIds($principalId), $variants));
        $exactIds = ExamenMinero::query()->get(['id', 'nombre'])
            ->filter(fn ($exam) => $this->identity->normalizedName($exam->nombre) === $key)->pluck('id')->all();
        $aliasIds = ExamenMineroAlias::query()->where('clave', hash('sha256', $key))
            ->when($mineId, fn ($query) => $query->where(fn ($q) => $q->whereNull('mina_id')->orWhere('mina_id', $mineId)))
            ->pluck('examen_id')->all();
        if (array_diff(array_merge($exactIds, $aliasIds), $allowed)) {
            $this->invalid('Ese nombre ya corresponde a otro examen o grupo. Revisa la coincidencia antes de asociarlo.');
        }
    }

    private function snapshot(): array
    {
        $report = $this->audit->report();

        return ['report' => $report, 'examenes' => ExamenMinero::query()->orderBy('id')->get()->toArray(),
            'requisitos' => MinaRequisito::query()->orderBy('id')->get()->toArray(),
            'asociaciones' => ExamenMineroAsociacion::query()->orderBy('id')->get()->toArray(),
            'aliases' => ExamenMineroAlias::query()->orderBy('id')->get()->toArray()];
    }

    private function assertReady(): void
    {
        if (! $this->identity->schemaReady()) {
            $this->invalid('Falta instalar la migracion de organizacion del catalogo. No se guardaron cambios.');
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['catalogo' => $message]);
    }
}
