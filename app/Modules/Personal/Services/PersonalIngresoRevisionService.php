<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoRevision;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaAccess;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalIngresoFamilyProposal;
use App\Modules\Personal\Support\PersonalIngresoPurpose;
use App\Modules\Personal\Support\PersonalNormalizer;
use App\Support\Rbac\PermissionMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonalIngresoRevisionService
{
    public function __construct(private readonly PersonalFichaService $fichas) {}

    public function startUpdate(Personal $personal, Usuario $actor, string $purpose, array $requestedFields = []): PersonalIngreso
    {
        $this->assertCanIssue($actor);
        $allowed = PersonalIngresoPurpose::editableKeys($purpose);
        if ($allowed === [] || array_diff($requestedFields, [...$allowed, 'familiares']) !== []) {
            throw ValidationException::withMessages(['requested_fields' => 'Selecciona solo campos habilitados para actualización general.']);
        }

        $requestedFields = PersonalIngresoPurpose::requestedKeys($requestedFields);

        return DB::transaction(function () use ($personal, $actor, $purpose, $requestedFields): PersonalIngreso {
            $personal = Personal::query()->lockForUpdate()->findOrFail($personal->id);
            $ficha = $personal->fichaColaborador()->lockForUpdate()->first();
            $this->assertCanIssue($actor, currentRead: true);
            $ingreso = PersonalIngreso::query()->create([
                'id' => (string) Str::uuid(),
                'estado' => PersonalIngreso::ESTADO_BORRADOR,
                'proposito' => $purpose,
                'revision' => 1,
                'personal_existente_id' => $personal->id,
                'tipo_documento' => $personal->tipo_documento ?: 'DNI',
                'numero_documento' => $personal->numero_documento ?: $personal->dni,
                'base_hash' => $this->baseHash($personal, $ficha, true, in_array('familiares', $requestedFields, true)),
                'requested_fields_json' => $requestedFields,
            ]);
            $this->record($ingreso, 'BORRADOR', ['_version' => 2], $actor, 'RRHH');

            return $ingreso;
        });
    }

    public function assertCanIssue(Usuario $actor, bool $currentRead = false): void
    {
        abort_unless(PermissionMatrix::allowsDirectAny(
            PersonalFichaAccess::permissionsFor($actor, $currentRead), 'personal', ['editar', 'actualizar', 'editar_ficha'],
        ), 403);
    }

    public function completeness(PersonalIngreso $ingreso): array
    {
        $fields = $this->draftFields($this->snapshot($ingreso)->snapshot);
        $requested = $ingreso->requested_fields_json;
        $missing = [];
        foreach (PersonalIngresoPurpose::requiredKeys($fields, $requested) as $key) {
            if (! filled($fields[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        return [
            'complete' => $missing === [] && ($fields !== [] || ($this->snapshot($ingreso)->snapshot['_familiares'] ?? []) !== []),
            'missing_fields' => $missing,
            'missing_documents' => [],
            'sensitive_pending' => false,
        ];
    }

    public function publicDraft(PersonalIngreso $ingreso): array
    {
        $definitions = array_intersect_key(PersonalFichaCatalog::fields(), array_flip($ingreso->requested_fields_json));
        $snapshot = $this->snapshot($ingreso)->snapshot;

        return [
            'revision' => $ingreso->revision,
            'fields' => $this->draftFields($snapshot),
            'definitions' => $definitions,
            'families_enabled' => in_array('familiares', $ingreso->requested_fields_json, true),
            'familiares' => $snapshot['_familiares'] ?? [],
            'completeness' => $this->completeness($ingreso),
        ];
    }

    public function saveDraft(PersonalIngreso $ingreso, int $expectedRevision, array $fields, ?array $familiares = null): PersonalIngreso
    {
        abort_unless($ingreso->revision === $expectedRevision, 409, 'El borrador cambió. Vuelve a abrirlo antes de guardar.');
        $definitions = array_intersect_key(PersonalFichaCatalog::fields(), array_flip($ingreso->requested_fields_json));
        if (array_diff_key($fields, $definitions) !== []) {
            throw ValidationException::withMessages(['fields' => 'La propuesta incluye campos que no corresponden a esta solicitud.']);
        }

        $rules = [];
        foreach ($definitions as $key => $definition) {
            $type = $definition['type'];
            $rules[$key] = ['nullable', 'string', $type === 'textarea' ? 'max:2000' : 'max:191'];
            if ($type === 'email') {
                $rules[$key][] = 'email';
            }
            if ($type === 'date') {
                $rules[$key][] = 'date_format:Y-m-d';
                $rules[$key][] = 'before_or_equal:today';
            }
            if (! empty($definition['options'])) {
                $rules[$key][] = Rule::in(array_keys($definition['options']));
            }
        }

        $validated = Validator::make($fields, $rules, [
            '*.date_format' => 'Ingresa una fecha válida en el calendario.',
            '*.before_or_equal' => 'La fecha no puede ser posterior a hoy.',
            '*.in' => 'Selecciona una opción de la lista.',
            '*.email' => 'Ingresa un correo electrónico válido.',
        ])->validate();
        foreach (['telefono', 'telefono_alterno'] as $key) {
            if (filled($validated[$key] ?? null) && ! preg_match('/^\+?[0-9][0-9\s().-]{5,29}$/D', $validated[$key])) {
                throw ValidationException::withMessages([$key => 'Ingresa un número de teléfono válido.']);
            }
        }
        $snapshot = $this->snapshot($ingreso)->snapshot;
        $patch = array_replace($snapshot, array_map(
            fn ($value): string => PersonalNormalizer::text($value),
            $validated,
        ));
        if (array_key_exists('correo', $patch)) {
            $patch['correo'] = mb_strtolower($patch['correo']);
        }
        if ($familiares !== null) {
            if (! in_array('familiares', $ingreso->requested_fields_json, true)) {
                throw ValidationException::withMessages(['familiares' => 'La solicitud no incluye familiares.']);
            }
            $patch['_familiares'] = PersonalIngresoFamilyProposal::validate($familiares);
        }
        ksort($patch);

        if ($patch !== $snapshot) {
            $ingreso->increment('revision');
            $this->record($ingreso, 'BORRADOR', $patch, null, 'ENLACE');
        }

        return $ingreso;
    }

    public function submit(PersonalIngreso $ingreso, int $expectedRevision, string $submissionUuid): void
    {
        abort_unless($ingreso->revision === $expectedRevision, 409, 'El borrador cambió. Vuelve a abrirlo antes de enviar.');
        $completeness = $this->completeness($ingreso);
        if (! $completeness['complete']) {
            $catalog = PersonalFichaCatalog::fields();
            $errors = [];
            foreach ($completeness['missing_fields'] as $key) {
                $errors['fields.'.$key] = 'Completa '.($catalog[$key]['label'] ?? $key).'.';
            }
            throw ValidationException::withMessages($errors ?: ['fields' => 'Completa los campos solicitados antes de enviar.']);
        }

        $this->record($ingreso, 'ENVIADA', $this->snapshot($ingreso)->snapshot, null, 'ENLACE');
        $ingreso->forceFill([
            'estado' => PersonalIngreso::ESTADO_RECIBIDA,
            'submitted_at' => now(),
            'submission_uuid' => $submissionUuid,
            'completitud_json' => $completeness,
        ])->save();
    }

    public function compare(string $ingresoId, Usuario $actor): array
    {
        $this->assertCanReview($actor, ['ver', 'aprobar']);
        $ingreso = $this->updates()->findOrFail($ingresoId);
        abort_unless($ingreso->submitted_at, 404);
        $personal = $ingreso->personalExistente()->firstOrFail();

        return $this->comparison($ingreso, $personal, $personal->fichaColaborador);
    }

    public function acceptUpdate(string $ingresoId, Usuario $actor, int $expectedRevision, string $expectedBaseHash, string $reviewHash): Personal
    {
        return DB::transaction(function () use ($ingresoId, $actor, $expectedRevision, $expectedBaseHash, $reviewHash): Personal {
            [$ingreso, $personal, $ficha] = $this->lockForReview($ingresoId, $actor);

            if ($ingreso->estado === PersonalIngreso::ESTADO_ACEPTADA) {
                $accepted = $this->snapshot($ingreso, 'ACEPTADA', lock: true);
                abort_unless($accepted->revision === $expectedRevision
                    && hash_equals($accepted->base_hash, $expectedBaseHash)
                    && hash_equals($accepted->review_hash, $reviewHash), 409, 'La revisión ya fue procesada.');

                return $personal;
            }

            $comparison = $this->comparison($ingreso, $personal, $ficha);
            abort_unless($ingreso->estado === PersonalIngreso::ESTADO_RECIBIDA
                && $comparison['completeness']['complete'] && ! $comparison['conflict']
                && $ingreso->revision === $expectedRevision
                && hash_equals($comparison['base_hash'], $expectedBaseHash)
                && hash_equals($comparison['review_hash'], $reviewHash), 409, 'La revisión cambió. Vuelve a comparar la propuesta.');

            $patch = array_map(fn (array $change): string => $change['after'], $comparison['changes']);
            $familyAdditions = array_filter($comparison['family_changes'], fn (array $change): bool => $change['action'] === 'add');
            if ($patch !== [] || $familyAdditions !== []) {
                $ficha ??= new PersonalFicha([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'estado' => PersonalFicha::ESTADO_PENDIENTE,
                    'tipo_documento' => $ingreso->tipo_documento,
                    'numero_documento' => $ingreso->numero_documento,
                    'created_by_usuario_id' => $actor->id,
                ]);
                $ficha->forceFill([
                    'datos_json' => array_replace($ficha->datos_json ?? [], $patch),
                    'datos_detectados_json' => array_replace($ficha->datos_detectados_json ?? [], $patch),
                ])->save();
                foreach ($familyAdditions as $family) {
                    $ficha->familiares()->create([
                        'id' => (string) Str::uuid(),
                        ...array_map(fn ($value) => $value === '' ? null : $value, $family['after']),
                    ]);
                }
                $this->applyGeneral($personal, $ficha, $patch);
            }

            $this->record($ingreso, 'ACEPTADA', [
                ...$comparison['changes'],
                '_family_changes' => $comparison['family_changes'],
            ], $actor, 'RRHH', $reviewHash);
            $ingreso->forceFill([
                'estado' => PersonalIngreso::ESTADO_ACEPTADA,
                'reviewed_at' => now(),
                'reviewed_by_usuario_id' => $actor->id,
            ])->save();

            return $personal;
        }, 3);
    }

    public function observeUpdate(string $ingresoId, Usuario $actor, int $expectedRevision, string $reason): PersonalIngreso
    {
        return DB::transaction(function () use ($ingresoId, $actor, $expectedRevision, $reason): PersonalIngreso {
            [$ingreso] = $this->lockForReview($ingresoId, $actor);
            abort_unless($ingreso->estado === PersonalIngreso::ESTADO_RECIBIDA && $ingreso->revision === $expectedRevision, 409);
            $this->record($ingreso, 'OBSERVADA', [
                'propuesta' => $this->snapshot($ingreso, 'ENVIADA')->snapshot,
                'motivo' => $reason,
            ], $actor, 'RRHH');
            $ingreso->forceFill([
                'estado' => PersonalIngreso::ESTADO_OBSERVADO,
                'reviewed_at' => now(),
                'reviewed_by_usuario_id' => $actor->id,
            ])->save();

            return $ingreso;
        }, 3);
    }

    private function comparison(PersonalIngreso $ingreso, Personal $personal, ?PersonalFicha $ficha): array
    {
        if ($ingreso->estado === PersonalIngreso::ESTADO_ACEPTADA) {
            $accepted = $this->snapshot($ingreso, 'ACEPTADA');

            return [
                'ingreso_id' => $ingreso->id,
                'revision' => $accepted->revision,
                'base_hash' => $accepted->base_hash,
                'review_hash' => $accepted->review_hash,
                'changes' => array_diff_key($accepted->snapshot, ['_family_changes' => true]),
                'family_changes' => $accepted->snapshot['_family_changes'] ?? [],
                'completeness' => $this->completeness($ingreso),
                'conflict' => false,
            ];
        }

        $snapshot = $this->snapshot($ingreso, 'ENVIADA')->snapshot;
        $submitted = $this->draftFields($snapshot);
        $current = $this->currentFields($personal, $ficha, ($snapshot['_version'] ?? 1) >= 2);
        $allowed = array_flip(array_intersect(
            PersonalIngresoPurpose::editableKeys($ingreso->proposito),
            $ingreso->requested_fields_json,
        ));
        abort_if(array_diff_key($submitted, $allowed) !== [], 409, 'La propuesta contiene campos fuera de la solicitud.');
        $changes = [];
        foreach ($submitted as $key => $after) {
            $before = $current[$key] ?? '';
            if ($before !== $after) {
                $changes[$key] = ['before' => $before, 'after' => $after];
            }
        }
        $families = $snapshot['_familiares'] ?? [];
        abort_if($families !== [] && ! in_array('familiares', $ingreso->requested_fields_json, true), 409);
        $familyChanges = PersonalIngresoFamilyProposal::compare($families, PersonalIngresoFamilyProposal::current($ficha));
        $baseHash = $this->baseHash($personal, $ficha, ($snapshot['_version'] ?? 1) >= 2, in_array('familiares', $ingreso->requested_fields_json, true));
        $conflict = ! hash_equals($ingreso->base_hash, $baseHash)
            || collect($familyChanges)->contains('action', 'conflict');

        return [
            'ingreso_id' => $ingreso->id,
            'revision' => $ingreso->revision,
            'base_hash' => $ingreso->base_hash,
            'review_hash' => $this->hash([$ingreso->id, $ingreso->revision, $baseHash, $snapshot]),
            'changes' => $changes,
            'family_changes' => $familyChanges,
            'completeness' => $this->completeness($ingreso),
            'conflict' => $conflict,
            'observation' => $ingreso->estado === PersonalIngreso::ESTADO_OBSERVADO
                ? $this->snapshot($ingreso, 'OBSERVADA')->snapshot['motivo']
                : null,
        ];
    }

    private function lockForReview(string $id, Usuario $actor): array
    {
        $this->assertCanReview($actor, ['aprobar']);
        $reference = $this->updates()->findOrFail($id);
        $personal = $reference->personalExistente()->lockForUpdate()->firstOrFail();
        $ficha = $personal->fichaColaborador()->lockForUpdate()->first();
        $ficha?->setRelation('personal', $personal);
        PersonalIngresoFamilyProposal::current($ficha, lock: true);
        $ingreso = $this->updates()->lockForUpdate()->findOrFail($id);
        $this->assertCanReview($actor, ['aprobar'], currentRead: true);
        abort_unless($ingreso->personal_existente_id === $personal->id && $ingreso->submitted_at, 409);

        return [$ingreso, $personal, $ficha];
    }

    private function assertCanReview(Usuario $actor, array $actions, bool $currentRead = false): void
    {
        abort_unless(PermissionMatrix::allowsDirectAny(PersonalFichaAccess::permissionsFor($actor, $currentRead), 'personal_ingresos', $actions), 403);
    }

    private function applyGeneral(Personal $personal, PersonalFicha $ficha, array $patch): void
    {
        $contact = [];
        if (array_intersect_key($patch, array_flip(['nombres', 'apellido_paterno', 'apellido_materno'])) !== []) {
            $fields = $this->currentFields($personal, $ficha, true);
            $contact['nombre_completo'] = mb_strtoupper(trim(implode(' ', [
                $fields['apellido_paterno'] ?? '', $fields['apellido_materno'] ?? '', $fields['nombres'] ?? '',
            ])), 'UTF-8');
        }
        if (array_key_exists('correo', $patch)) {
            $contact['correo'] = $patch['correo'] ?: null;
        }
        if (array_key_exists('telefono', $patch)) {
            $phone = PersonalNormalizer::normalizePhonePayload($patch['telefono']);
            $contact['telefono_1'] = $phone['telefono_1'];
            $contact['telefono_2'] = $phone['telefono_2'] ?? $personal->telefono_2;
            $contact['telefono'] = PersonalNormalizer::combinePhones($contact['telefono_1'], $contact['telefono_2']);
        }
        if ($contact !== []) {
            $personal->forceFill($contact)->save();
        }
    }

    private function currentFields(Personal $personal, ?PersonalFicha $ficha, bool $extended = false): array
    {
        $fichaData = array_replace($ficha?->datos_detectados_json ?? [], $ficha?->datos_json ?? []);
        if ($extended) {
            $source = $ficha ?? (new PersonalFicha(['datos_json' => []]))->setRelation('personal', $personal);
            $fichaData = $this->fichas->fichaDataForPublic($source);
        }
        $data = array_replace([
            'correo' => $personal->correo,
            'telefono' => $personal->telefono,
        ], $fichaData);
        $keys = $extended ? PersonalIngresoPurpose::editableKeys(PersonalIngresoPurpose::ACTUALIZACION)
            : collect(PersonalFichaCatalog::sections())->whereIn('key', ['contacto', 'domicilio'])
                ->flatMap(fn (array $section): array => array_column($section['fields'], 'key'))->all();
        $data = array_intersect_key($data, array_flip($keys));
        ksort($data);

        return array_map(fn ($value): string => PersonalNormalizer::text($value), $data);
    }

    private function baseHash(Personal $personal, ?PersonalFicha $ficha, bool $extended = false, bool $families = false): string
    {
        $base = [
            $personal->id, $personal->tipo_documento, $personal->numero_documento ?: $personal->dni,
            $personal->correo, $personal->telefono, $personal->telefono_1, $personal->telefono_2,
            $ficha?->id, $this->currentFields($personal, $ficha, $extended),
        ];
        if ($extended) {
            $base[] = $personal->nombre_completo;
        }
        if ($families) {
            $base[] = PersonalIngresoFamilyProposal::current($ficha);
        }

        return $this->hash($base);
    }

    private function draftFields(array $snapshot): array
    {
        return array_diff_key($snapshot, array_flip(['_version', '_familiares']));
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function updates(): Builder
    {
        return PersonalIngreso::query()->where('proposito', PersonalIngresoPurpose::ACTUALIZACION);
    }

    private function snapshot(PersonalIngreso $ingreso, string $event = 'BORRADOR', bool $lock = false): PersonalIngresoRevision
    {
        return PersonalIngresoRevision::query()
            ->where('ingreso_id', $ingreso->id)
            ->where('revision', $ingreso->revision)
            ->where('evento', $event)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->firstOrFail();
    }

    private function record(PersonalIngreso $ingreso, string $event, array $snapshot, ?Usuario $actor, string $origin, ?string $reviewHash = null): void
    {
        PersonalIngresoRevision::query()->create([
            'id' => (string) Str::uuid(),
            'ingreso_id' => $ingreso->id,
            'revision' => $ingreso->revision,
            'evento' => $event,
            'snapshot' => $snapshot,
            'base_hash' => $ingreso->base_hash,
            'review_hash' => $reviewHash,
            'actor_id' => $actor?->id,
            'origen' => $origin,
        ]);
    }
}
