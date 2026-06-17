<?php

namespace App\Modules\Personal\Services;

use App\Mail\PersonalFichaLinkMail;
use App\Models\Personal;
use App\Models\PersonalDocumentoEstado;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalFichaFamiliar;
use App\Models\PersonalFichaLink;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Notificaciones\Services\OperationalNotificationService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalFichaService
{
    public const DEFAULT_LINK_HOURS = 72;
    public const TEMPORAL_ESTADO_VENCIDO = 'VENCIDO';
    public const TEMPORAL_ESTADO_LINK_ENVIADO_PENDIENTE = 'LINK_ENVIADO_PENDIENTE';
    public const TEMPORAL_ESTADO_LINK_ENVIADO_VENCIDO = 'LINK_ENVIADO_VENCIDO';

    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalContratoDatoService $contratoDatoService,
        private readonly OperationalNotificationService $operationalNotifications,
    )
    {
    }

    public function createFromConfirmation(array $fields, array $verifyFields, array $source, Usuario $user): array
    {
        $data = $this->normalizeFichaData($fields);
        $documentType = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $documentNumber = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');

        if (!PersonalNormalizer::isValidDocument($documentType, $documentNumber)) {
            throw ValidationException::withMessages([
                'fields.numero_documento' => 'El documento no tiene un formato valido para el tipo seleccionado.',
            ]);
        }

        return DB::transaction(function () use ($data, $documentType, $documentNumber, $verifyFields, $source, $user): array {
            $existing = $this->findPersonalByDocument($documentType, $documentNumber);
            $data = $this->mergedImportFichaData($existing, $data);

            $requiredMissing = collect(PersonalFichaCatalog::requiredKeys())
                ->filter(fn (string $key): bool => trim((string) ($data[$key] ?? '')) === '')
                ->values()
                ->all();

            $verifyFields = collect($verifyFields)
                ->merge($requiredMissing)
                ->filter(fn ($key): bool => array_key_exists((string) $key, PersonalFichaCatalog::fields()))
                ->map(fn ($key): string => (string) $key)
                ->unique()
                ->values()
                ->all();

            $payload = $this->personalPayloadFromFicha(
                $data,
                $existing ? $this->resolveImportedPersonalState($existing) : PersonalFicha::ESTADO_PENDIENTE
            );
            $personal = $existing
                ? $this->personalService->update($existing, $payload)
                : $this->personalService->create($payload);

            $ficha = $personal->fichaColaborador;
            if ($ficha) {
                $ficha->forceFill([
                    'estado' => PersonalFicha::ESTADO_PENDIENTE,
                    'tipo_documento' => $documentType,
                    'numero_documento' => $documentNumber,
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                    'macro_original_nombre' => $source['original_name'] ?? $ficha->macro_original_nombre,
                    'macro_original_path' => $source['path'] ?? $ficha->macro_original_path,
                    'datos_detectados_json' => $this->mergeNonEmptyFichaData($ficha->datos_detectados_json ?? [], $source['detected'] ?? $data),
                    'datos_json' => $this->mergeNonEmptyFichaData($ficha->datos_json ?? [], $data),
                    'campos_verificacion_json' => $verifyFields,
                    'advertencias_json' => array_values(array_unique(array_merge($ficha->advertencias_json ?? [], $source['warnings'] ?? []))),
                    'submitted_at' => null,
                    'approved_at' => null,
                    'approved_by_usuario_id' => null,
                    'observed_at' => null,
                    'rejected_at' => null,
                ])->save();
            } else {
                $ficha = PersonalFicha::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'estado' => PersonalFicha::ESTADO_PENDIENTE,
                    'tipo_documento' => $documentType,
                    'numero_documento' => $documentNumber,
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                    'macro_original_nombre' => $source['original_name'] ?? null,
                    'macro_original_path' => $source['path'] ?? null,
                    'datos_detectados_json' => $source['detected'] ?? $data,
                    'datos_json' => $data,
                    'campos_verificacion_json' => $verifyFields,
                    'advertencias_json' => $source['warnings'] ?? [],
                    'created_by_usuario_id' => $user->id,
                ]);
            }

            if (!empty($source['path'])) {
                $existingMacro = PersonalFichaArchivo::query()
                    ->where('personal_ficha_id', $ficha->id)
                    ->where('tipo', 'macro_contrato')
                    ->first();

                if ($existingMacro?->path !== ($source['path'] ?? null)) {
                    if ($existingMacro) {
                        $existingMacro->delete();
                    }

                    PersonalFichaArchivo::query()->create([
                        'id' => (string) Str::uuid(),
                        'personal_ficha_id' => $ficha->id,
                        'tipo' => 'macro_contrato',
                        'nombre_original' => $source['original_name'] ?? null,
                        'path' => $source['path'],
                        'mime' => $source['mime'] ?? null,
                        'size' => $source['size'] ?? null,
                        'uploaded_by_usuario_id' => $user->id,
                        'uploaded_by_public' => false,
                    ]);
                }
            }

            [$token, $link] = $this->createSecureLink($ficha, self::DEFAULT_LINK_HOURS, true);

            return [
                'personal' => $personal->fresh(['fichaColaborador.link']),
                'ficha' => $ficha->fresh(['personal', 'link']),
                'link' => $link,
                'token' => $token,
                'url' => route('ficha-colaborador.show', ['token' => $token]),
            ];
        });
    }

    public function createManyFromConfirmation(array $items, array $verifyFields, array $source, Usuario $user): array
    {
        $created = [];
        $skipped = [];

        foreach ($items as $index => $item) {
            $fields = is_array($item['fields'] ?? null) ? $item['fields'] : [];
            $rowNumber = (int) ($item['row_number'] ?? ($index + 1));

            try {
                $result = $this->createFromConfirmation($fields, $verifyFields, [
                    ...$source,
                    'detected' => $fields,
                    'warnings' => $item['warnings'] ?? $source['warnings'] ?? [],
                ], $user);

                $created[] = [
                    ...$result,
                    'row_number' => $rowNumber,
                ];
            } catch (ValidationException $exception) {
                $skipped[] = [
                    'row_number' => $rowNumber,
                    'fields' => $fields,
                    'message' => collect($exception->errors())->flatten()->first() ?: 'No se pudo generar el link para este trabajador.',
                ];
            }
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'created_count' => count($created),
            'skipped_count' => count($skipped),
        ];
    }

    public function createManual(array $fields, array $attributes, Usuario $user): array
    {
        $data = $this->normalizeFichaData($fields);
        $documentType = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $documentNumber = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');

        if (!PersonalNormalizer::isValidDocument($documentType, $documentNumber)) {
            throw ValidationException::withMessages([
                'fields.numero_documento' => 'El documento no tiene un formato valido para el tipo seleccionado.',
            ]);
        }

        $missingRequired = collect(PersonalFichaCatalog::requiredKeys())
            ->filter(fn (string $key): bool => trim((string) ($data[$key] ?? '')) === '')
            ->values();

        $verifyFields = collect($attributes['verify_fields'] ?? PersonalFichaCatalog::defaultVerificationKeys())
            ->merge($missingRequired)
            ->filter(fn ($key): bool => array_key_exists((string) $key, PersonalFichaCatalog::fields()))
            ->map(fn ($key): string => (string) $key)
            ->unique()
            ->values()
            ->all();

        $result = DB::transaction(function () use ($data, $documentType, $documentNumber, $attributes, $verifyFields, $missingRequired, $user): array {
            $existing = $this->findPersonalByDocument($documentType, $documentNumber);

            if ($existing && in_array(strtoupper((string) $existing->estado), ['ACTIVO', 'FALTA_CONTRATO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'OBSERVADO', 'LINK_VENCIDO'], true)) {
                throw ValidationException::withMessages([
                    'fields.numero_documento' => 'Ya existe un trabajador activo o pendiente con este documento.',
                ]);
            }

            $payload = [
                ...$this->personalPayloadFromFicha($data, PersonalFicha::ESTADO_PENDIENTE),
                'es_supervisor' => filter_var($attributes['es_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'minas' => $attributes['minas'] ?? [],
            ];

            $personal = $existing
                ? $this->personalService->update($existing, $payload)
                : $this->personalService->create($payload);

            $ficha = PersonalFicha::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'estado' => PersonalFicha::ESTADO_PENDIENTE,
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                'datos_detectados_json' => $data,
                'datos_json' => $data,
                'campos_verificacion_json' => $verifyFields,
                'advertencias_json' => [],
                'created_by_usuario_id' => $user->id,
            ]);

            [$token, $link] = $this->createSecureLink($ficha, self::DEFAULT_LINK_HOURS, true);

            return [
                'personal' => $personal->fresh(['fichaColaborador.link']),
                'ficha' => $ficha->fresh(['personal', 'link']),
                'link' => $link,
                'token' => $token,
                'url' => route('ficha-colaborador.show', ['token' => $token]),
                'missing_required' => $missingRequired->all(),
            ];
        });

        $this->assertTemporaryLinkReady($result, $result['token'] ?? null);

        return $result;
    }

    public function updateManual(Personal $personal, array $fields, array $attributes, Usuario $user): array
    {
        $data = $this->normalizeFichaData($fields);
        $documentType = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $documentNumber = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');

        if (!PersonalNormalizer::isValidDocument($documentType, $documentNumber)) {
            throw ValidationException::withMessages([
                'fields.numero_documento' => 'El documento no tiene un formato valido para el tipo seleccionado.',
            ]);
        }

        $existing = $this->findPersonalByDocument($documentType, $documentNumber);
        if ($existing && $existing->id !== $personal->id && in_array(strtoupper((string) $existing->estado), ['ACTIVO', 'FALTA_CONTRATO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'OBSERVADO', 'LINK_VENCIDO'], true)) {
            throw ValidationException::withMessages([
                'fields.numero_documento' => 'Ya existe otro trabajador activo o pendiente con este documento.',
            ]);
        }

        $documentPaths = $this->storeFichaDocuments($personal->fichaColaborador?->id, $attributes['documentos'] ?? []);

        return DB::transaction(function () use ($personal, $data, $attributes, $user, $documentType, $documentNumber, $documentPaths): array {
            $updatedPersonal = $this->personalService->update($personal, [
                ...$this->personalPayloadFromFicha($data, $attributes['estado'] ?? ((string) $personal->estado ?: PersonalFicha::ESTADO_PENDIENTE)),
                'es_supervisor' => filter_var($attributes['es_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'minas' => $attributes['minas'] ?? [],
            ]);

            $ficha = $personal->fichaColaborador()->with('link')->first();
            $signatureBase64 = trim((string) ($attributes['firma_base64'] ?? ''));

            if ($ficha) {
                $fichaPayload = [
                    'tipo_documento' => $documentType,
                    'numero_documento' => $documentNumber,
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                    'datos_json' => $data,
                    'datos_detectados_json' => $this->mergeNonEmptyFichaData($ficha->datos_detectados_json ?? [], $data),
                ];

                if ($signatureBase64 !== '') {
                    $fichaPayload['firma_base64'] = $signatureBase64;
                }

                $ficha->forceFill($fichaPayload)->save();
            } else {
                $estadoPersonal = strtoupper((string) ($attributes['estado'] ?? $personal->estado));
                $estadoFicha = match ($estadoPersonal) {
                    'ACTIVO', PersonalContratoDatoService::PENDING_STATE, PersonalFicha::ESTADO_APROBADO => PersonalFicha::ESTADO_APROBADO,
                    PersonalFicha::ESTADO_PENDIENTE,
                    PersonalFicha::ESTADO_ENVIADA,
                    PersonalFicha::ESTADO_OBSERVADO,
                    PersonalFicha::ESTADO_LINK_VENCIDO,
                    PersonalFicha::ESTADO_RECHAZADO => $estadoPersonal,
                    default => 'INACTIVO',
                };

                $fichaPayload = [
                    'id' => (string) Str::uuid(),
                    'personal_id' => $updatedPersonal->id,
                    'estado' => $estadoFicha,
                    'tipo_documento' => $documentType,
                    'numero_documento' => $documentNumber,
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                    'datos_detectados_json' => $data,
                    'datos_json' => $data,
                    'campos_verificacion_json' => [],
                    'advertencias_json' => [],
                    'created_by_usuario_id' => $user->id,
                    'approved_at' => $estadoFicha === PersonalFicha::ESTADO_APROBADO ? now() : null,
                    'approved_by_usuario_id' => $estadoFicha === PersonalFicha::ESTADO_APROBADO ? $user->id : null,
                ];

                if ($signatureBase64 !== '') {
                    $fichaPayload['firma_base64'] = $signatureBase64;
                }

                $ficha = PersonalFicha::query()->create($fichaPayload);
            }

            if (array_key_exists('familiares', $attributes)) {
                PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();

                foreach ($this->normalizeFamiliares($attributes['familiares'] ?? []) as $familiar) {
                    PersonalFichaFamiliar::query()->create([
                        'id' => (string) Str::uuid(),
                        'personal_ficha_id' => $ficha->id,
                        ...$familiar,
                    ]);
                }
            }

            $this->syncManualHuella($ficha, $attributes['huella'] ?? null, $user);
            $this->syncFichaDocuments($ficha, $documentPaths, $user);

            if (strtoupper((string) ($attributes['estado'] ?? '')) === 'CESADO' && $ficha->link) {
                $ficha->link->forceFill([
                    'estado' => PersonalFichaLink::ESTADO_INHABILITADO,
                    'disabled_at' => now(),
                    'enabled_manually_at' => null,
                ])->save();
            }

            return [
                'personal' => $updatedPersonal->fresh(['fichaColaborador.link']),
                'ficha' => $ficha->fresh(['personal', 'link', 'familiares', 'archivos']),
            ];
        });
    }

    public function updateDocuments(Personal $personal, array $documentos, Usuario $user): PersonalFicha
    {
        $documentPaths = $this->storeFichaDocuments($personal->fichaColaborador?->id, $documentos);

        return DB::transaction(function () use ($personal, $documentPaths, $user): PersonalFicha {
            $ficha = $personal->fichaColaborador()->first();

            if (!$ficha) {
                $data = $this->seedFichaDataFromPersonal($personal);
                $documentType = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
                $documentNumber = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');
                $estadoPersonal = strtoupper((string) $personal->estado);
                $estadoFicha = match ($estadoPersonal) {
                    'ACTIVO', PersonalContratoDatoService::PENDING_STATE, PersonalFicha::ESTADO_APROBADO => PersonalFicha::ESTADO_APROBADO,
                    PersonalFicha::ESTADO_PENDIENTE,
                    PersonalFicha::ESTADO_ENVIADA,
                    PersonalFicha::ESTADO_OBSERVADO,
                    PersonalFicha::ESTADO_LINK_VENCIDO,
                    PersonalFicha::ESTADO_RECHAZADO => $estadoPersonal,
                    default => (string) $personal->estado,
                };

                $ficha = PersonalFicha::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'estado' => $estadoFicha,
                    'tipo_documento' => $documentType,
                    'numero_documento' => $documentNumber,
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
                    'datos_detectados_json' => $data,
                    'datos_json' => $data,
                    'campos_verificacion_json' => [],
                    'advertencias_json' => [],
                    'created_by_usuario_id' => $user->id,
                    'approved_at' => $estadoFicha === PersonalFicha::ESTADO_APROBADO ? now() : null,
                    'approved_by_usuario_id' => $estadoFicha === PersonalFicha::ESTADO_APROBADO ? $user->id : null,
                ]);
            }

            $this->syncFichaDocuments($ficha, $documentPaths, $user);

            return $ficha->fresh(['personal', 'link', 'familiares', 'archivos']);
        });
    }

    public function documentAvailability(array $fields): array
    {
        $data = $this->normalizeFichaData($fields);
        $type = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $number = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');

        if (!PersonalNormalizer::isValidDocument($type, $number)) {
            return [
                'available' => false,
                'type' => $type,
                'number' => $number,
                'message' => 'Documento invalido para el tipo seleccionado.',
            ];
        }

        $existing = $this->findPersonalByDocument($type, $number);
        if ($existing && in_array(strtoupper((string) $existing->estado), ['ACTIVO', 'FALTA_CONTRATO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA', 'OBSERVADO', 'LINK_VENCIDO'], true)) {
            return [
                'available' => true,
                'type' => $type,
                'number' => $number,
                'message' => 'Ya existe en Personal con estado ' . PersonalFichaCatalog::stateLabel((string) $existing->estado) . '. La macro completara o actualizara los datos existentes.',
                'personal_id' => $existing->id,
                'will_update_existing' => true,
            ];
        }

        return [
            'available' => true,
            'type' => $type,
            'number' => $number,
            'message' => 'Disponible para generar link.',
        ];
    }

    public function documentMatrix(?PersonalFicha $ficha): array
    {
        $requirements = PersonalFichaCatalog::documentRequirements();

        if (!$ficha) {
            return collect($requirements)
                ->map(function (array $requirement, string $key): array {
                    return [
                        'key' => $key,
                        'label' => $requirement['label'] ?? $key,
                        'description' => $requirement['description'] ?? '',
                        'required' => (bool) ($requirement['required'] ?? false),
                        'conditional' => ($requirement['condition'] ?? null) !== null,
                        'applies' => true,
                        'special' => $requirement['special'] ?? null,
                        'estado' => PersonalDocumentoEstado::ESTADO_PENDIENTE,
                        'estado_label' => PersonalFichaCatalog::documentStateLabels()[PersonalDocumentoEstado::ESTADO_PENDIENTE],
                        'archivo' => null,
                        'observacion' => null,
                        'complete' => false,
                        'missing_file' => (bool) ($requirement['required'] ?? false),
                        'pending_review' => false,
                        'observed' => false,
                        'vida_ley_entrega_fisica' => null,
                        'vida_ley_entrega_fisica_label' => null,
                        'vida_ley_entrega_observacion' => null,
                    ];
                })
                ->values()
                ->all();
        }

        $relations = ['archivos', 'familiares'];
        if (Schema::hasTable('personal_documento_estados')) {
            $relations[] = 'documentoEstados';
        }
        $ficha->loadMissing($relations);

        $archivos = $ficha->archivos->keyBy('tipo');
        $estados = Schema::hasTable('personal_documento_estados')
            ? $ficha->documentoEstados->keyBy('tipo')
            : collect();

        return collect($requirements)
            ->map(function (array $requirement, string $key) use ($ficha, $archivos, $estados): array {
                $archivo = $archivos->get($key);
                $estadoRecord = $estados->get($key);
                $applies = $this->documentApplies($ficha, $key, $requirement);
                $baseRequired = (bool) ($requirement['required'] ?? false);
                $required = $baseRequired || (($requirement['condition'] ?? null) !== null && $applies);
                $storedState = strtoupper(trim((string) ($estadoRecord?->estado ?? '')));

                if (!$applies) {
                    $estado = PersonalDocumentoEstado::ESTADO_NO_APLICA;
                } elseif (in_array($storedState, PersonalDocumentoEstado::estados(), true)) {
                    $estado = $storedState;
                } else {
                    $estado = $archivo
                        ? PersonalDocumentoEstado::ESTADO_CARGADO
                        : PersonalDocumentoEstado::ESTADO_PENDIENTE;
                }

                $labels = PersonalFichaCatalog::documentStateLabels();
                $vidaLeyFisica = $estadoRecord?->vida_ley_entrega_fisica;
                $vidaLeyLabels = PersonalFichaCatalog::vidaLeyPhysicalStateLabels();

                return [
                    'key' => $key,
                    'label' => $requirement['label'] ?? $key,
                    'description' => $requirement['description'] ?? '',
                    'required' => $required,
                    'conditional' => ($requirement['condition'] ?? null) !== null,
                    'applies' => $applies,
                    'special' => $requirement['special'] ?? null,
                    'estado' => $estado,
                    'estado_label' => $labels[$estado] ?? str_replace('_', ' ', $estado),
                    'archivo' => $archivo,
                    'observacion' => $estadoRecord?->observacion,
                    'complete' => in_array($estado, [PersonalDocumentoEstado::ESTADO_APROBADO, PersonalDocumentoEstado::ESTADO_NO_APLICA], true),
                    'missing_file' => $applies && $required && !$archivo,
                    'pending_review' => $applies && $archivo && $estado === PersonalDocumentoEstado::ESTADO_CARGADO,
                    'observed' => $applies && $estado === PersonalDocumentoEstado::ESTADO_OBSERVADO,
                    'vida_ley_entrega_fisica' => $vidaLeyFisica,
                    'vida_ley_entrega_fisica_label' => $vidaLeyFisica ? ($vidaLeyLabels[$vidaLeyFisica] ?? str_replace('_', ' ', $vidaLeyFisica)) : null,
                    'vida_ley_entrega_observacion' => $estadoRecord?->vida_ley_entrega_observacion,
                ];
            })
            ->values()
            ->all();
    }

    public function documentSummary(?PersonalFicha $ficha): array
    {
        $rows = collect($this->documentMatrix($ficha));

        return [
            'total' => $rows->count(),
            'aprobados' => $rows->where('estado', PersonalDocumentoEstado::ESTADO_APROBADO)->count(),
            'cargados' => $rows->where('estado', PersonalDocumentoEstado::ESTADO_CARGADO)->count(),
            'pendientes' => $rows->where('estado', PersonalDocumentoEstado::ESTADO_PENDIENTE)->count(),
            'observados' => $rows->where('estado', PersonalDocumentoEstado::ESTADO_OBSERVADO)->count(),
            'no_aplica' => $rows->where('estado', PersonalDocumentoEstado::ESTADO_NO_APLICA)->count(),
            'faltan_archivos' => $rows->where('missing_file', true)->count(),
            'pendientes_revision' => $rows->where('pending_review', true)->count(),
            'completos' => $rows->every(fn (array $row): bool => (bool) $row['complete']),
        ];
    }

    public function requiredDocumentKeysForPayload(array $fields, array $familiares): array
    {
        $data = [
            ...$fields,
            ...$this->normalizeFichaData($fields),
        ];

        return collect(PersonalFichaCatalog::documentRequirements())
            ->filter(function (array $requirement) use ($data, $familiares): bool {
                if ((bool) ($requirement['required'] ?? false)) {
                    return true;
                }

                return ($requirement['condition'] ?? null) !== null
                    && $this->documentAppliesToPayload($data, $familiares, $requirement);
            })
            ->keys()
            ->values()
            ->all();
    }

    public function updateDocumentState(PersonalFicha $ficha, string $tipo, array $payload, Usuario $user): PersonalDocumentoEstado
    {
        if (!Schema::hasTable('personal_documento_estados')) {
            throw ValidationException::withMessages([
                'documento' => 'La tabla de estados documentales todavia no esta disponible. Ejecuta las migraciones.',
            ]);
        }

        $requirements = PersonalFichaCatalog::documentRequirements();
        if (!array_key_exists($tipo, $requirements)) {
            throw ValidationException::withMessages([
                'documento' => 'Tipo de documento no reconocido.',
            ]);
        }

        $ficha->loadMissing(['archivos', 'familiares']);
        $requirement = $requirements[$tipo];
        $applies = $this->documentApplies($ficha, $tipo, $requirement);
        $archivo = $ficha->archivos->firstWhere('tipo', $tipo);
        $estado = strtoupper(trim((string) ($payload['estado'] ?? PersonalDocumentoEstado::ESTADO_PENDIENTE)));

        if (!in_array($estado, PersonalDocumentoEstado::estados(), true)) {
            throw ValidationException::withMessages([
                'estado' => 'Estado documental invalido.',
            ]);
        }

        if ($estado === PersonalDocumentoEstado::ESTADO_APROBADO && !$archivo) {
            throw ValidationException::withMessages([
                'estado' => 'No se puede aprobar un documento sin archivo cargado.',
            ]);
        }

        if ($estado === PersonalDocumentoEstado::ESTADO_OBSERVADO && trim((string) ($payload['observacion'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'observacion' => 'Escribe la observacion del documento.',
            ]);
        }

        if ($estado === PersonalDocumentoEstado::ESTADO_NO_APLICA && $applies && (bool) ($requirement['required'] ?? false) && ($requirement['condition'] ?? null) === null) {
            throw ValidationException::withMessages([
                'estado' => 'Este documento obligatorio no puede marcarse como no aplica.',
            ]);
        }

        $vidaLeyFisica = $payload['vida_ley_entrega_fisica'] ?? null;
        if (($requirement['special'] ?? null) === 'vida_ley' && $vidaLeyFisica !== null) {
            $vidaLeyFisica = strtoupper(trim((string) $vidaLeyFisica));
            if (!in_array($vidaLeyFisica, PersonalDocumentoEstado::vidaLeyEntregaFisicaEstados(), true)) {
                throw ValidationException::withMessages([
                    'vida_ley_entrega_fisica' => 'Estado de entrega fisica de Vida Ley invalido.',
                ]);
            }
        } else {
            $vidaLeyFisica = null;
        }

        return DB::transaction(function () use ($ficha, $tipo, $estado, $payload, $vidaLeyFisica, $user): PersonalDocumentoEstado {
            $record = PersonalDocumentoEstado::query()
                ->firstOrNew([
                    'personal_ficha_id' => $ficha->id,
                    'tipo' => $tipo,
                ]);

            if (!$record->exists) {
                $record->id = (string) Str::uuid();
            }

            $record->forceFill([
                'estado' => $estado,
                'observacion' => trim((string) ($payload['observacion'] ?? '')) ?: null,
                'vida_ley_entrega_fisica' => $vidaLeyFisica,
                'vida_ley_entrega_observacion' => trim((string) ($payload['vida_ley_entrega_observacion'] ?? '')) ?: null,
                'updated_by_usuario_id' => $user->id,
                'estado_updated_at' => now(),
            ])->save();

            return $record->fresh();
        });
    }

    public function resolveToken(string $token): array
    {
        $token = $this->normalizeFichaLinkToken($token) ?? $token;
        $hash = hash('sha256', $token);
        $link = PersonalFichaLink::query()
            ->with(['ficha.personal', 'ficha.familiares'])
            ->where('token_hash', $hash)
            ->first();

        if (!$link) {
            return ['mode' => 'invalid', 'link' => null, 'ficha' => null];
        }

        $link->forceFill(['last_accessed_at' => now()])->save();
        $ficha = $link->ficha;

        if (!$ficha) {
            return ['mode' => 'invalid', 'link' => $link, 'ficha' => null];
        }

        if ($link->disabled_at || $link->estado === PersonalFichaLink::ESTADO_INHABILITADO) {
            return ['mode' => 'disabled', 'link' => $link, 'ficha' => $ficha];
        }

        if (!$ficha->submitted_at && now()->greaterThan($link->expires_at)) {
            $this->markLinkExpired($link);

            return ['mode' => 'expired', 'link' => null, 'ficha' => null];
        }

        if ($ficha->submitted_at && $link->estado !== PersonalFichaLink::ESTADO_ACTIVO) {
            if ($link->read_until && now()->lessThanOrEqualTo($link->read_until)) {
                return ['mode' => 'readonly', 'link' => $link, 'ficha' => $ficha];
            }

            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_INHABILITADO,
                'disabled_at' => $link->disabled_at ?: now(),
            ])->save();

            return ['mode' => 'disabled', 'link' => $link->fresh(), 'ficha' => $ficha];
        }

        return ['mode' => 'edit', 'link' => $link, 'ficha' => $ficha];
    }

    public function submitFromWorker(PersonalFichaLink $link, array $fields, array $familiares, string $firmaBase64, ?UploadedFile $huella, array $documentos = []): PersonalFicha
    {
        $ficha = $link->ficha()->with('personal')->firstOrFail();
        $wasApproved = $ficha->estado === PersonalFicha::ESTADO_APROBADO;
        $previousPersonalState = strtoupper((string) ($ficha->personal?->estado ?? ''));

        if ($ficha->submitted_at || now()->greaterThan($link->expires_at)) {
            $isRegularizationLink = $link->estado === PersonalFichaLink::ESTADO_ACTIVO && !$link->disabled_at && now()->lessThanOrEqualTo($link->expires_at);

            if (!$isRegularizationLink) {
                throw ValidationException::withMessages([
                    'ficha' => 'Este link ya no permite modificaciones.',
                ]);
            }
        }

        $data = $this->normalizeFichaData([
            ...$fields,
            'tipo_documento' => $ficha->tipo_documento,
            'numero_documento' => $ficha->numero_documento,
        ]);

        $missingRequired = $this->missingRequiredFieldKeysFromData($data);
        if (!empty($missingRequired)) {
            Log::warning('Ficha publica con campos obligatorios faltantes.', [
                'ficha_id' => $ficha->id,
                'personal_id' => $ficha->personal_id,
                'missing_required' => $missingRequired,
            ]);

            throw ValidationException::withMessages([
                'ficha' => 'Faltan completar campos obligatorios antes de enviar la ficha. Revisa y vuelve a intentar.',
            ]);
        }

        $huellaPath = $ficha->huella_path;
        if ($huella instanceof UploadedFile) {
            $huellaPath = $huella->storeAs(
                'personal_fichas/' . $ficha->id,
                'huella_' . now()->format('Ymd_His') . '.' . strtolower($huella->getClientOriginalExtension() ?: 'jpg'),
                'local',
            );
        }

        $documentPaths = [];
        foreach ($documentos as $tipo => $documento) {
            if (!$documento instanceof UploadedFile) {
                continue;
            }

            $safeTipo = $this->safeFichaArchivoTipo((string) $tipo);
            $documentPaths[$safeTipo] = [
                'file' => $documento,
                'path' => $documento->storeAs(
                    'personal_fichas/' . $ficha->id . '/documentos',
                    $safeTipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($documento->getClientOriginalExtension() ?: 'bin'),
                    'local',
                ),
            ];
        }

        return DB::transaction(function () use ($ficha, $link, $data, $familiares, $firmaBase64, $huella, $huellaPath, $documentPaths, $wasApproved, $previousPersonalState): PersonalFicha {
            $ficha->forceFill([
                'estado' => $wasApproved ? PersonalFicha::ESTADO_APROBADO : PersonalFicha::ESTADO_ENVIADA,
                'datos_json' => $data,
                'datos_detectados_json' => $data,
                'firma_base64' => $firmaBase64,
                'huella_path' => $huellaPath,
                'submitted_at' => now(),
            ])->save();

            if ($ficha->personal) {
                $newPersonalState = $wasApproved || in_array($previousPersonalState, ['INACTIVO', 'CESADO'], true)
                    ? $previousPersonalState
                    : PersonalFicha::ESTADO_ENVIADA;

                $ficha->personal->forceFill(['estado' => $newPersonalState])->save();
            }

            PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();
            foreach ($this->normalizeFamiliares($familiares) as $familiar) {
                PersonalFichaFamiliar::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_ficha_id' => $ficha->id,
                    ...$familiar,
                ]);
            }

            if ($huella instanceof UploadedFile && $huellaPath) {
                $this->replaceFichaArchivo(
                    $ficha,
                    'huella',
                    $huella,
                    $huellaPath,
                    true,
                );
            }

            foreach ($documentPaths as $tipo => $payload) {
                /** @var UploadedFile $documento */
                $documento = $payload['file'];
                $this->replaceFichaArchivo(
                    $ficha,
                    $tipo,
                    $documento,
                    $payload['path'],
                    true,
                );
            }

            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_ENVIADO,
                'submitted_at' => now(),
                'read_until' => now()->addHours(24),
            ])->save();

            return $ficha->fresh(['personal', 'familiares', 'link', 'archivos']);
        });
    }

    public function storePublicDraftArchivo(PersonalFichaLink $link, string $tipo, UploadedFile $archivo): PersonalFichaArchivo
    {
        $ficha = $link->ficha()->firstOrFail();
        $safeTipo = $this->safeFichaArchivoTipo($tipo);
        $directory = $safeTipo === 'huella'
            ? 'personal_fichas/' . $ficha->id
            : 'personal_fichas/' . $ficha->id . '/documentos';
        $filename = $safeTipo . '_borrador_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($archivo->getClientOriginalExtension() ?: 'bin');
        $path = $archivo->storeAs($directory, $filename, 'local');

        return DB::transaction(function () use ($ficha, $safeTipo, $archivo, $path): PersonalFichaArchivo {
            if ($safeTipo === 'huella') {
                $ficha->forceFill(['huella_path' => $path])->save();
            }

            return $this->replaceFichaArchivo(
                $ficha,
                $safeTipo,
                $archivo,
                $path,
                true,
            );
        });
    }

    public function storePublicDraftData(PersonalFichaLink $link, array $fields, array $familiares = [], ?string $firmaBase64 = null): PersonalFicha
    {
        $ficha = $link->ficha()->with(['personal', 'familiares'])->firstOrFail();
        $existingData = is_array($ficha->datos_json) ? $ficha->datos_json : [];

        $data = $this->normalizeFichaData(array_replace($existingData, [
            ...$fields,
            'tipo_documento' => $ficha->tipo_documento,
            'numero_documento' => $ficha->numero_documento,
        ]));

        return DB::transaction(function () use ($ficha, $data, $familiares, $firmaBase64): PersonalFicha {
            $payload = [
                'datos_json' => $data,
            ];

            if (filled($firmaBase64)) {
                $payload['firma_base64'] = $firmaBase64;
            }

            $ficha->forceFill($payload)->save();

            PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();
            foreach ($this->normalizeFamiliares($familiares) as $familiar) {
                PersonalFichaFamiliar::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_ficha_id' => $ficha->id,
                    ...$familiar,
                ]);
            }

            return $ficha->fresh(['personal', 'familiares', 'link', 'archivos']);
        });
    }

    public function notifyFichaSubmitted(PersonalFicha $ficha): void
    {
        app(NotificationService::class)->emit('personal_ficha_completada', [
            'module' => 'personal',
            'priority' => 'high',
            'category' => 'accion_requerida',
            'target_user_ids' => $this->reviewNotificationUserIds(),
            'require_permission' => false,
            'entity_type' => PersonalFicha::class,
            'entity_id' => $ficha->id,
            'action_route' => '/personal/fichas/{entity_id}/revisar',
            'action_label' => 'Revisar ficha',
            'message' => 'Se completo la ficha de ' . (($ficha->datos_json['puesto'] ?? $ficha->personal?->puesto) ?: 'trabajador') . ' - ' . ($ficha->personal?->nombre_completo ?: 'Sin nombre') . '. Pendiente de revision.',
            'payload' => [
                'ficha_id' => $ficha->id,
                'personal_id' => $ficha->personal_id,
                'numero_documento' => $ficha->numero_documento,
            ],
            'dedupe_key' => 'personal_ficha_completada:' . $ficha->id . ':' . optional($ficha->submitted_at)->format('YmdHis'),
        ]);
    }

    public function approve(PersonalFicha $ficha, Usuario $user, ?string $observaciones = null, array $contractDates = []): PersonalFicha
    {
        $approved = DB::transaction(function () use ($ficha, $user, $observaciones, $contractDates): PersonalFicha {
            $data = $this->normalizeFichaData($ficha->datos_json ?? []);
            $data['fecha_ingreso'] = PersonalNormalizer::isoDate($contractDates['fecha_ingreso'] ?? $data['fecha_ingreso'] ?? null) ?? '';
            $data['fecha_fin_contrato'] = PersonalNormalizer::isoDate($contractDates['fecha_fin_contrato'] ?? $data['fecha_fin_contrato'] ?? null) ?? '';
            $data['periodo_prueba_inicio'] = PersonalNormalizer::isoDate($contractDates['periodo_prueba_inicio'] ?? $data['periodo_prueba_inicio'] ?? null) ?? '';
            $data['periodo_prueba_fin'] = PersonalNormalizer::isoDate($contractDates['periodo_prueba_fin'] ?? $data['periodo_prueba_fin'] ?? null) ?? '';
            $personal = $ficha->personal()->firstOrFail();

            $this->personalService->update($personal, $this->personalPayloadFromFicha($data, PersonalContratoDatoService::PENDING_STATE));
            $this->contratoDatoService->ensureForPersonal($personal->fresh(), [
                ...$data,
                'fecha_inicio_contrato' => $data['fecha_ingreso'] ?? null,
                'puesto' => $data['puesto'] ?? $personal->puesto,
            ], $user);

            $ficha->forceFill([
                'estado' => PersonalFicha::ESTADO_APROBADO,
                'datos_json' => $data,
                'datos_detectados_json' => $data,
                'approved_at' => now(),
                'approved_by_usuario_id' => $user->id,
                'observaciones_revision' => $observaciones,
            ])->save();

            if ($ficha->link) {
                $ficha->link->forceFill([
                    'estado' => PersonalFichaLink::ESTADO_INHABILITADO,
                    'disabled_at' => now(),
                ])->save();
            }

            return $ficha->fresh(['personal', 'familiares', 'link']);
        });

        $this->operationalNotifications->fichaAprobadaFaltaContrato($approved, $user);

        return $approved;
    }

    public function observe(PersonalFicha $ficha, Usuario $user, ?string $observaciones = null): PersonalFicha
    {
        return DB::transaction(function () use ($ficha, $observaciones): PersonalFicha {
            $ficha->forceFill([
                'estado' => PersonalFicha::ESTADO_OBSERVADO,
                'observed_at' => now(),
                'observaciones_revision' => $observaciones,
            ])->save();

            $ficha->personal?->forceFill(['estado' => PersonalFicha::ESTADO_OBSERVADO])->save();

            return $ficha->fresh(['personal', 'familiares', 'link']);
        });
    }

    public function imageDataUrl(?string $path): ?string
    {
        if (!$path || !Storage::disk('local')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'image/jpeg';

        return 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('local')->get($path));
    }

    public function fichaDataForPublic(PersonalFicha $ficha): array
    {
        $data = $this->normalizeFichaData($this->mergeNonEmptyFichaData($ficha->datos_detectados_json ?? [], $ficha->datos_json ?? []));

        $personal = $ficha->personal;
        if ($personal) {
            $nameParts = preg_split('/\s+/', trim((string) $personal->nombre_completo)) ?: [];
            if (($data['apellido_paterno'] ?? '') === '' && count($nameParts) >= 3 && $personal->nombre_completo !== 'Pendiente completar nombres') {
                $data['apellido_paterno'] = $nameParts[0] ?? '';
                $data['apellido_materno'] = $nameParts[1] ?? '';
                $data['nombres'] = implode(' ', array_slice($nameParts, 2));
            }

            $fallbacks = [
                'tipo_documento' => $personal->tipo_documento ?? 'DNI',
                'numero_documento' => $personal->numero_documento ?? $personal->dni,
                'puesto' => $personal->puesto !== 'Por definir' ? $personal->puesto : '',
                'contrato' => $personal->contrato,
                'correo' => $personal->correo,
                'telefono' => $personal->telefono,
                'fecha_ingreso' => optional($personal->fecha_ingreso)->toDateString(),
            ];

            foreach ($fallbacks as $key => $value) {
                if (($data[$key] ?? '') === '' && PersonalNormalizer::text($value) !== '') {
                    $data[$key] = (string) $value;
                }
            }
        }

        $data['tipo_documento'] = $ficha->tipo_documento;
        $data['numero_documento'] = $ficha->numero_documento;

        return $this->normalizeFichaData($data);
    }

    public function publicUrlForLink(?PersonalFichaLink $link): ?string
    {
        if (!$link || !$link->token_encrypted) {
            return null;
        }

        $token = null;

        try {
            $token = $this->normalizeFichaLinkToken(Crypt::decryptString($link->token_encrypted));
        } catch (\Throwable) {
            try {
                $token = $this->normalizeFichaLinkToken(Crypt::decrypt($link->token_encrypted));
            } catch (\Throwable) {
                return null;
            }
        }

        if (!$token) {
            return null;
        }

        return route('ficha-colaborador.show', ['token' => $token]);
    }

    public function temporaryLinkRows(?string $estado = null, ?string $search = null): array
    {
        $this->expireStaleLinks();
        $this->ensurePendingFichasForIncompletePersonal();

        $normalizedEstado = $estado ? strtoupper($estado) : null;
        $normalizedSearch = $this->normalizeTemporarySearchText($search);

        $query = PersonalFicha::query()
            ->with(['personal', 'link', 'archivos'])
            ->latest('created_at');

        if ($normalizedSearch !== '') {
            $tokens = collect(explode(' ', $normalizedSearch))
                ->filter()
                ->values()
                ->all();

            foreach ($tokens as $token) {
                $like = '%' . $token . '%';

                $query->where(function ($searchQuery) use ($like): void {
                    $searchQuery
                        ->whereRaw('LOWER(COALESCE(tipo_documento, "")) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(numero_documento, "")) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(COALESCE(macro_tipo_contrato, "")) LIKE ?', [$like])
                        ->orWhereHas('personal', function ($personalQuery) use ($like): void {
                            $personalQuery
                                ->whereRaw('LOWER(COALESCE(nombre_completo, "")) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(puesto, "")) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(contrato, "")) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(correo, "")) LIKE ?', [$like])
                                ->orWhereRaw('LOWER(COALESCE(telefono, "")) LIKE ?', [$like]);
                        });
                });
            }
        }

        return $query
            ->get()
            ->filter(function (PersonalFicha $ficha) use ($normalizedEstado, $normalizedSearch): bool {
                $displayState = $this->temporaryDisplayState($ficha);

                if ($normalizedEstado !== null && $normalizedEstado !== '' && $displayState !== $normalizedEstado) {
                    return false;
                }

                $hasWorkflowState = in_array($ficha->estado, [
                    PersonalFicha::ESTADO_PENDIENTE,
                    PersonalFicha::ESTADO_ENVIADA,
                    PersonalFicha::ESTADO_OBSERVADO,
                ], true);

                if ($normalizedEstado !== null && $normalizedEstado !== '') {
                    $matchesWorkflow = true;
                } else {
                    $matchesWorkflow = $hasWorkflowState || $this->needsRegularization($ficha);
                }

                if (!$matchesWorkflow) {
                    return false;
                }

                return true;
            })
            ->map(fn (PersonalFicha $ficha): ?array => $this->mapTemporaryFichaRow($ficha))
            ->filter()
            ->all();
    }

    public function temporaryLinkRow(PersonalFicha $ficha): ?array
    {
        $ficha->loadMissing(['personal', 'link', 'archivos']);

        return $this->mapTemporaryFichaRow($ficha);
    }

    private function mapTemporaryFichaRow(PersonalFicha $ficha): ?array
    {
        $summary = $this->regularizationSummary($ficha);
        $displayState = $this->temporaryDisplayState($ficha);
        $personalState = strtoupper((string) ($ficha->personal?->estado ?? ''));

        if ($personalState === 'CESADO') {
            return null;
        }

        if ($ficha->estado === PersonalFicha::ESTADO_APROBADO) {
            return null;
        }

        $isComplete = empty($summary['missing_fields'])
            && empty($summary['missing_documents'])
            && empty($summary['can_regularize']);
        $hasSubmittedFicha = $ficha->submitted_at !== null
            || $ficha->estado === PersonalFicha::ESTADO_ENVIADA
            || $ficha->estado === PersonalFicha::ESTADO_APROBADO
            || $summary['link']?->submitted_at !== null
            || $summary['link']?->estado === PersonalFichaLink::ESTADO_ENVIADO;

        if ($isComplete && $hasSubmittedFicha) {
            return null;
        }

        $row = [
            'ficha' => $ficha,
            'personal' => $ficha->personal,
            'link' => $summary['link'],
            'url' => $summary['url'],
            'correo' => $this->resolvedFichaEmail($ficha),
            'email_sent_at' => $summary['link']?->emailed_at,
            'estado_key' => $displayState,
            'estado_label' => $this->temporaryDisplayLabel($displayState),
            'missing_fields' => $summary['missing_fields'],
            'missing_documents' => $summary['missing_documents'],
            'can_regularize' => $summary['can_regularize'],
        ];

        return !empty($row['url']) || !empty($row['can_regularize'])
            ? $row
            : null;
    }

    private function normalizeTemporarySearchText(?string $value): string
    {
        $text = mb_strtolower(trim((string) $value));
        $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text);
    }

    public function temporaryDisplayState(PersonalFicha $ficha): string
    {
        $ficha->loadMissing('link');
        $link = $ficha->link;

        if (
            $ficha->estado === PersonalFicha::ESTADO_LINK_VENCIDO
            || $link?->estado === PersonalFichaLink::ESTADO_VENCIDO
        ) {
            return $link?->emailed_at
                ? self::TEMPORAL_ESTADO_LINK_ENVIADO_VENCIDO
                : self::TEMPORAL_ESTADO_VENCIDO;
        }

        if (
            $ficha->estado === PersonalFicha::ESTADO_PENDIENTE
            && $this->isUsableActiveLink($link)
            && $link->emailed_at !== null
        ) {
            return self::TEMPORAL_ESTADO_LINK_ENVIADO_PENDIENTE;
        }

        return strtoupper((string) $ficha->estado);
    }

    public function temporaryDisplayLabel(string $state): string
    {
        return match (strtoupper($state)) {
            self::TEMPORAL_ESTADO_VENCIDO => 'Vencido',
            self::TEMPORAL_ESTADO_LINK_ENVIADO_PENDIENTE => 'Link enviado - pendiente',
            self::TEMPORAL_ESTADO_LINK_ENVIADO_VENCIDO => 'Link enviado - vencido',
            default => PersonalFichaCatalog::stateLabel($state),
        };
    }

    public function ensurePendingFichasForIncompletePersonal(): void
    {
        Personal::query()
            ->with('fichaColaborador.link')
            ->where(function ($query): void {
                $query
                    ->whereNull('estado')
                    ->orWhere('estado', '!=', 'CESADO');
            })
            ->orderBy('created_at')
            ->chunk(200, function ($personales): void {
                foreach ($personales as $personal) {
                    /** @var Personal $personal */
                    $ficha = $personal->fichaColaborador;

                    $shouldEnsureFicha = $ficha === null
                        || in_array(strtoupper((string) $personal->estado), [
                            PersonalFicha::ESTADO_PENDIENTE,
                            PersonalFicha::ESTADO_ENVIADA,
                            PersonalFicha::ESTADO_LINK_VENCIDO,
                            PersonalFicha::ESTADO_OBSERVADO,
                        ], true)
                        || $this->hasMissingRequiredFields($ficha);

                    if ($shouldEnsureFicha) {
                        $this->ensurePendingFichaForPersonal($personal);
                    }
                }
            });
    }

    public function expireStaleLinks(): int
    {
        if (!Schema::hasTable('personal_ficha_links')) {
            return 0;
        }

        $links = PersonalFichaLink::query()
            ->with('ficha.personal')
            ->where('estado', PersonalFichaLink::ESTADO_ACTIVO)
            ->whereNull('submitted_at')
            ->where('expires_at', '<', now())
            ->limit(100)
            ->get();

        foreach ($links as $link) {
            $this->markLinkExpired($link);
        }

        return $links->count();
    }

    public function extendLink(PersonalFicha $ficha, int $hours = self::DEFAULT_LINK_HOURS): PersonalFichaLink
    {
        $link = $ficha->link()->first();

        if (!$link) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha no tiene un link temporal activo para extender.',
            ]);
        }

        if ($ficha->submitted_at) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha ya fue enviada por el trabajador y no se puede extender.',
            ]);
        }

        $base = $link->expires_at && $link->expires_at->greaterThan(now())
            ? $link->expires_at->copy()
            : now()->copy();

        $link->forceFill([
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'disabled_at' => null,
            'submitted_at' => null,
            'read_until' => null,
            'expires_at' => $base->addHours($hours),
            'enabled_manually_at' => $link->enabled_manually_at ?: now(),
        ])->save();

        return $link->fresh();
    }

    public function resolvedFichaEmail(?PersonalFicha $ficha): ?string
    {
        if (!$ficha) {
            return null;
        }

        $ficha->loadMissing('personal');
        $data = is_array($ficha->datos_json ?? null) ? $ficha->datos_json : [];
        $email = PersonalNormalizer::text($ficha->personal?->correo ?? $data['correo'] ?? '');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return mb_strtolower($email);
    }

    public function markLinkEmailed(PersonalFicha $ficha): array
    {
        $ficha->loadMissing(['personal', 'link']);
        $link = $ficha->link;

        if (!$link) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha no tiene un link disponible.',
            ]);
        }

        $email = $this->resolvedFichaEmail($ficha);
        if (!$email) {
            throw ValidationException::withMessages([
                'correo' => 'No se encontro un correo valido para este trabajador.',
            ]);
        }

        $wasResent = $link->emailed_at !== null;

        $link->forceFill([
            'emailed_at' => now(),
            'emailed_to' => $email,
        ])->save();

        return [
            'email' => $email,
            'resent' => $wasResent,
            'link' => $link->fresh(),
        ];
    }

    public function sendLinkByEmail(PersonalFicha $ficha): array
    {
        $ficha->loadMissing(['personal', 'link']);
        $link = $ficha->link;
        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array'], true)) {
            throw ValidationException::withMessages([
                'correo' => 'El servidor todavia no tiene configurado un mailer real para enviar correos. Configura SMTP en el .env.',
            ]);
        }

        if (!$link) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha no tiene un link disponible para enviar.',
            ]);
        }

        $email = $this->resolvedFichaEmail($ficha);
        if (!$email) {
            throw ValidationException::withMessages([
                'correo' => 'No se encontro un correo valido para este trabajador.',
            ]);
        }

        if ($this->isUsableActiveLink($link)) {
            $link = $this->ensureActiveLinkWindow($link, self::DEFAULT_LINK_HOURS);
            $ficha->setRelation('link', $link);
        }

        $url = $this->activePublicUrlForLink($link);
        if (!$url) {
            throw ValidationException::withMessages([
                'ficha' => 'El link temporal no esta activo o ya no se puede enviar.',
            ]);
        }

        $wasSent = $link->emailed_at !== null;

        Mail::to($email)->send(new PersonalFichaLinkMail(
            $ficha->fresh(['personal', 'link']),
            $url,
            $wasSent,
        ));

        $link->forceFill([
            'emailed_at' => now(),
            'emailed_to' => $email,
        ])->save();

        return [
            'email' => $email,
            'resent' => $wasSent,
            'link' => $link->fresh(),
            'url' => $url,
        ];
    }

    public function sendObservedFichaEmail(PersonalFicha $ficha): array
    {
        $ficha->loadMissing(['personal', 'link']);
        $mailer = (string) config('mail.default', 'log');

        if (in_array($mailer, ['log', 'array'], true)) {
            throw ValidationException::withMessages([
                'correo' => 'El servidor todavia no tiene configurado un mailer real para enviar correos. Configura SMTP en el .env.',
            ]);
        }

        if ($ficha->estado !== PersonalFicha::ESTADO_OBSERVADO) {
            throw ValidationException::withMessages([
                'ficha' => 'Solo se puede reenviar correo de observacion para fichas observadas.',
            ]);
        }

        $result = $this->ensureRegularizationLink($ficha, self::DEFAULT_LINK_HOURS);
        $ficha = $ficha->fresh(['personal', 'link']);
        $link = $ficha->link;
        $url = $result['url'] ?? $this->publicUrlForLink($link);

        if (!$link || !$url) {
            throw ValidationException::withMessages([
                'ficha' => 'No se pudo generar el link temporal para reenviar la observacion.',
            ]);
        }

        $email = $this->resolvedFichaEmail($ficha);
        if (!$email) {
            throw ValidationException::withMessages([
                'correo' => 'No se encontro un correo valido para este trabajador.',
            ]);
        }

        $wasSent = $link->emailed_at !== null;
        $observation = trim((string) $ficha->observaciones_revision);
        $subject = 'Necesitas revisar los datos de tu ficha - ' . trim($ficha->tipo_documento . ' ' . $ficha->numero_documento);
        $linkHtml = '<a href="' . e($url) . '" style="color:#0f62fe; font-weight:700; word-break:break-all;">' . e($url) . '</a>';
        $bodyHtml = nl2br(e(
            "Hola " . ($ficha->personal?->nombre_completo ?: 'colaborador') . ",\n\n"
            . "Tu ficha de colaborador fue observada y necesitas revisar o corregir tus datos.\n\n"
            . "Observacion registrada:\n"
            . ($observation !== '' ? $observation : 'Revisar los datos observados por RRHH.') . "\n\n"
            . "Ingresa al siguiente enlace temporal para actualizar la informacion:\n"
        ), false)
            . '<p style="margin:14px 0 18px;">' . $linkHtml . '</p>'
            . nl2br(e(
                "El enlace vence el " . (optional($link->expires_at)->format('d/m/Y H:i') ?: '-') . ".\n\n"
                . "Saludos,\nEquipo Proserge"
            ), false);

        Mail::to($email)->send(new PersonalFichaLinkMail(
            $ficha,
            $url,
            true,
            $subject,
            $bodyHtml,
        ));

        $link->forceFill([
            'emailed_at' => now(),
            'emailed_to' => $email,
        ])->save();

        return [
            'email' => $email,
            'resent' => $wasSent,
            'link' => $link->fresh(),
            'url' => $url,
        ];
    }

    public function ensureRegularizationLink(PersonalFicha $ficha, int $hours = self::DEFAULT_LINK_HOURS): array
    {
        $ficha->loadMissing(['personal', 'link', 'archivos']);

        $this->assertFichaHasPersistedPersonal($ficha);

        if (!$this->needsRegularization($ficha)) {
            throw ValidationException::withMessages([
                'ficha' => 'La ficha ya no tiene datos o documentos pendientes por regularizar.',
            ]);
        }

        if ($ficha->estado === PersonalFicha::ESTADO_LINK_VENCIDO) {
            $ficha->forceFill([
                'estado' => PersonalFicha::ESTADO_PENDIENTE,
            ])->save();

            if ($ficha->personal && strtoupper((string) $ficha->personal->estado) === PersonalFicha::ESTADO_LINK_VENCIDO) {
                $ficha->personal->forceFill([
                    'estado' => PersonalFicha::ESTADO_PENDIENTE,
                ])->save();
            }
        }

        if ($this->isUsableActiveLink($ficha->link)) {
            $link = $this->ensureActiveLinkWindow($ficha->link, $hours);

            return [
                'link' => $link,
                'url' => $this->publicUrlForLink($link),
            ];
        }

        [$token, $link] = $this->createSecureLink($ficha, $hours, true);

        return [
            'link' => $link,
            'token' => $token,
            'url' => route('ficha-colaborador.show', ['token' => $token]),
        ];
    }

    public function removeFromTemporaryList(PersonalFicha $ficha): void
    {
        DB::transaction(function () use ($ficha): void {
            $ficha->loadMissing(['personal', 'link', 'familiares', 'archivos']);

            foreach ($ficha->archivos as $archivo) {
                if ($archivo->path && Storage::disk('local')->exists($archivo->path)) {
                    Storage::disk('local')->delete($archivo->path);
                }
                $archivo->delete();
            }

            if ($ficha->huella_path && Storage::disk('local')->exists($ficha->huella_path)) {
                Storage::disk('local')->delete($ficha->huella_path);
            }

            PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();
            PersonalFichaLink::query()->where('personal_ficha_id', $ficha->id)->delete();
            $ficha->delete();
        });
    }

    public function activateTemporaryLinkForPersonal(Personal $personal, Usuario $user, int $hours = self::DEFAULT_LINK_HOURS): array
    {
        $personalId = (string) $personal->id;

        if ($personalId === '' || !Personal::query()->whereKey($personalId)->exists()) {
            throw ValidationException::withMessages([
                'personal' => 'No se encontro el trabajador en la base de datos. Guarda el trabajador antes de habilitar el link temporal.',
            ]);
        }

        $personal = Personal::query()->findOrFail($personalId);

        if (strtoupper((string) $personal->estado) === 'CESADO') {
            throw ValidationException::withMessages([
                'personal' => 'No se puede activar link temporal para un trabajador cesado.',
            ]);
        }

        $result = DB::transaction(function () use ($personalId, $user, $hours): array {
            $personal = Personal::query()
                ->lockForUpdate()
                ->findOrFail($personalId);

            $personal->loadMissing('fichaColaborador.link');
            $ficha = $personal->fichaColaborador;

            if (!$ficha || $ficha->estado === PersonalFicha::ESTADO_APROBADO) {
                $data = $this->seedFichaDataFromPersonal($personal);
                $ficha = PersonalFicha::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'estado' => PersonalFicha::ESTADO_PENDIENTE,
                    'tipo_documento' => PersonalNormalizer::documentType($personal->tipo_documento ?? 'DNI', $personal->numero_documento ?? $personal->dni ?? ''),
                    'numero_documento' => PersonalNormalizer::documentNumber($personal->numero_documento ?? $personal->dni ?? ''),
                    'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? $personal->contrato ?? null),
                    'datos_detectados_json' => $data,
                    'datos_json' => $data,
                    'campos_verificacion_json' => PersonalFichaCatalog::defaultVerificationKeys(),
                    'advertencias_json' => [],
                    'created_by_usuario_id' => $user->id,
                ]);

                [$token, $link] = $this->createSecureLink($ficha, $hours, true);

                return [
                    'ficha' => $ficha->fresh(['personal', 'link', 'archivos']),
                    'link' => $link,
                    'token' => $token,
                    'url' => route('ficha-colaborador.show', ['token' => $token]),
                ];
            }

            if ($this->needsRegularization($ficha)) {
                $result = $this->ensureRegularizationLink($ficha, $hours);

                return [
                    ...$result,
                    'ficha' => $ficha->fresh(['personal', 'link', 'archivos']),
                ];
            }

            if ($this->isUsableActiveLink($ficha->link)) {
                $link = $this->ensureActiveLinkWindow($ficha->link, $hours);
                $ficha->setRelation('link', $link);

                return [
                    'ficha' => $ficha->fresh(['personal', 'link', 'archivos']),
                    'link' => $link,
                    'url' => $this->publicUrlForLink($link),
                ];
            }

            [$token, $link] = $this->createSecureLink($ficha, $hours, true);

            return [
                'ficha' => $ficha->fresh(['personal', 'link', 'archivos']),
                'link' => $link,
                'token' => $token,
                'url' => route('ficha-colaborador.show', ['token' => $token]),
            ];
        });

        $this->assertTemporaryLinkReady($result, $result['token'] ?? null);

        return $result;
    }

    public function ensurePendingFichaForPersonal(Personal $personal): PersonalFicha
    {
        $personal->loadMissing('fichaColaborador.link');

        if ($personal->fichaColaborador) {
            return $personal->fichaColaborador;
        }

        $documentType = PersonalNormalizer::documentType($personal->tipo_documento ?? 'DNI', $personal->numero_documento ?? $personal->dni ?? '');
        $documentNumber = PersonalNormalizer::documentNumber($personal->numero_documento ?? $personal->dni ?? '');
        $data = $this->seedFichaDataFromPersonal($personal);

        return PersonalFicha::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_PENDIENTE,
            'tipo_documento' => $documentType,
            'numero_documento' => $documentNumber,
            'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? $personal->contrato ?? null),
            'datos_detectados_json' => $data,
            'datos_json' => $data,
            'campos_verificacion_json' => PersonalFichaCatalog::defaultVerificationKeys(),
            'advertencias_json' => [],
            'created_by_usuario_id' => null,
        ]);
    }

    public function normalizeFichaData(array $fields): array
    {
        $data = PersonalFichaCatalog::emptyData();

        foreach ($data as $key => $value) {
            $data[$key] = array_key_exists($key, $fields)
                ? PersonalNormalizer::text($fields[$key] ?? '')
                : $value;
        }

        $data['tipo_documento'] = PersonalNormalizer::documentType($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $data['numero_documento'] = PersonalNormalizer::documentNumber($data['numero_documento'] ?? '');
        $data['contrato'] = PersonalNormalizer::contract($data['contrato'] ?? null);

        foreach (['fecha_nacimiento', 'fecha_ingreso', 'fecha_fin_contrato', 'fecha_cese'] as $dateField) {
            $data[$dateField] = PersonalNormalizer::isoDate($data[$dateField] ?? null) ?? '';
        }

        foreach (['telefono', 'telefono_alterno'] as $phoneField) {
            $phoneData = PersonalNormalizer::normalizePhonePayload($data[$phoneField] ?? '');
            $data[$phoneField] = PersonalNormalizer::combinePhones($phoneData['telefono_1'] ?? null, $phoneData['telefono_2'] ?? null) ?? '';
        }

        if (($data['correo'] ?? '') !== '') {
            $data['correo'] = mb_strtolower($data['correo']);
        }

        if (($data['profesion_oficio'] ?? '') === '') {
            $data['profesion_oficio'] = trim(collect([$fields['profesion'] ?? '', $fields['oficio'] ?? ''])->filter()->implode(' / '));
        }

        if (($data['domicilio_tipo'] ?? '') === '') {
            $data['domicilio_tipo'] = 'Peru';
        }

        if (($data['pais_nacimiento'] ?? '') === '') {
            $data['pais_nacimiento'] = 'Peru';
        }

        if (($data['estado_civil'] ?? '') !== 'Otro') {
            $data['estado_civil_otro'] = '';
        }

        if (($data['nacionalidad'] ?? '') !== 'Otra') {
            $data['nacionalidad_otra'] = '';
        }

        if (($data['pais_nacimiento'] ?? '') === 'Otro') {
            $data['departamento_nacimiento'] = '';
            $data['provincia_nacimiento'] = '';
            $data['distrito_nacimiento'] = '';
        } else {
            $data['pais_nacimiento_otro'] = '';
            $data['lugar_nacimiento_extranjero'] = '';
        }

        if (($data['domicilio_tipo'] ?? '') === 'Extranjero') {
            $data['domicilio_departamento'] = '';
            $data['domicilio_provincia'] = '';
            $data['domicilio_distrito'] = '';
            $data['domicilio_direccion'] = '';
        } else {
            $data['domicilio_pais_otro'] = '';
            $data['domicilio_extranjero'] = '';
        }

        if (in_array($data['banco'] ?? '', ['BCP', 'Interbank'], true)) {
            $data['banco_otro'] = '';
            $data['cci'] = '';
        } elseif (($data['banco'] ?? '') === 'Otro') {
            $data['numero_cuenta'] = '';
        }

        if (in_array($data['sistema_pensionario'] ?? '', ['Sistema Nacional de Pensiones', 'SNP'], true)) {
            $data['sistema_pensionario'] = 'ONP';
        }

        if (($data['sistema_pensionario'] ?? '') !== 'Sistema Privado de Pensiones') {
            $data['tipo_comision'] = '';
            $data['tipo_afp'] = '';
            $data['cuspp'] = '';
        }

        if (($data['quinta_empleador_principal'] ?? '') !== 'Otra empresa') {
            $data['quinta_otra_empresa'] = '';
            $data['quinta_otra_empresa_ruc'] = '';
        }

        return $data;
    }

    public function normalizeFamiliares(array $familiares): array
    {
        return collect($familiares)
            ->filter(function ($item): bool {
                return is_array($item)
                    && PersonalNormalizer::text($item['nombres_apellidos'] ?? '') !== ''
                    && PersonalNormalizer::text($item['fecha_nacimiento'] ?? '') !== '';
            })
            ->map(function (array $item): array {
                $phoneData = PersonalNormalizer::normalizePhonePayload($item['telefono'] ?? '');

                return [
                    'nombres_apellidos' => PersonalNormalizer::text($item['nombres_apellidos'] ?? ''),
                    'parentesco' => PersonalNormalizer::text($item['parentesco'] ?? '') ?: null,
                    'fecha_nacimiento' => PersonalNormalizer::isoDate($item['fecha_nacimiento'] ?? null),
                    'tipo_documento' => PersonalNormalizer::documentType($item['tipo_documento'] ?? 'DNI', $item['numero_documento'] ?? ''),
                    'numero_documento' => PersonalNormalizer::documentNumber($item['numero_documento'] ?? '') ?: null,
                    'telefono' => PersonalNormalizer::combinePhones($phoneData['telefono_1'] ?? null, $phoneData['telefono_2'] ?? null),
                    'vive_con_trabajador' => filter_var($item['vive_con_trabajador'] ?? false, FILTER_VALIDATE_BOOL),
                    'estudia' => filter_var($item['estudia'] ?? false, FILTER_VALIDATE_BOOL),
                    'contacto_emergencia' => filter_var($item['contacto_emergencia'] ?? false, FILTER_VALIDATE_BOOL),
                ];
            })
            ->values()
            ->all();
    }

    public function familyRowsForEdit(?PersonalFicha $ficha): array
    {
        if ($ficha && $ficha->relationLoaded('familiares') === false) {
            $ficha->load('familiares');
        }

        if ($ficha && $ficha->familiares->count() > 0) {
            return $ficha->familiares->map(fn ($item) => [
                'nombres_apellidos' => $item->nombres_apellidos,
                'parentesco' => $item->parentesco,
                'fecha_nacimiento' => optional($item->fecha_nacimiento)->toDateString(),
                'tipo_documento' => $item->tipo_documento,
                'numero_documento' => $item->numero_documento,
                'telefono' => $item->telefono,
                'vive_con_trabajador' => $item->vive_con_trabajador,
                'estudia' => $item->estudia,
                'contacto_emergencia' => $item->contacto_emergencia,
            ])->values()->all();
        }

        return collect(['Padre', 'Madre', 'Conyuge'])
            ->map(fn ($parentesco) => [
                'nombres_apellidos' => '',
                'parentesco' => $parentesco,
                'fecha_nacimiento' => '',
                'tipo_documento' => 'DNI',
                'numero_documento' => '',
                'telefono' => '',
                'vive_con_trabajador' => false,
                'estudia' => false,
                'contacto_emergencia' => false,
            ])
            ->all();
    }

    public function missingRequiredDocumentKeys(?PersonalFicha $ficha): array
    {
        if (!$ficha) {
            return array_keys(array_filter(
                PersonalFichaCatalog::documentRequirements(),
                fn (array $requirement): bool => (bool) ($requirement['required'] ?? false)
            ));
        }

        return collect($this->documentMatrix($ficha))
            ->filter(fn (array $row): bool => (bool) ($row['required'] ?? false))
            ->filter(fn (array $row): bool => (bool) ($row['applies'] ?? true))
            ->filter(fn (array $row): bool => (bool) ($row['missing_file'] ?? false) || (bool) ($row['observed'] ?? false))
            ->pluck('key')
            ->values()
            ->all();
    }

    public function hasMissingRequiredDocuments(?PersonalFicha $ficha): bool
    {
        return count($this->missingRequiredDocumentKeys($ficha)) > 0;
    }

    public function missingRequiredFieldKeys(?PersonalFicha $ficha): array
    {
        if (!$ficha) {
            return PersonalFichaCatalog::requiredKeys();
        }

        $data = is_array($ficha->datos_json ?? null) ? $ficha->datos_json : [];
        if ($data === []) {
            return PersonalFichaCatalog::requiredKeys();
        }

        $requiredKeys = collect(PersonalFichaCatalog::requiredKeys());

        if (($data['estado_civil'] ?? null) === 'Otro') {
            $requiredKeys->push('estado_civil_otro');
        }

        if (($data['nacionalidad'] ?? null) === 'Otra') {
            $requiredKeys->push('nacionalidad_otra');
        }

        if (($data['pais_nacimiento'] ?? null) === 'Otro') {
            $requiredKeys = $requiredKeys->merge(['pais_nacimiento_otro', 'lugar_nacimiento_extranjero']);
        } else {
            $requiredKeys = $requiredKeys->merge(['departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento']);
        }

        if (($data['domicilio_tipo'] ?? 'Peru') === 'Extranjero') {
            $requiredKeys = $requiredKeys->merge(['domicilio_pais_otro', 'domicilio_extranjero']);
        } else {
            $requiredKeys = $requiredKeys->merge(['domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion']);
        }

        $banco = (string) ($data['banco'] ?? '');
        if (in_array($banco, ['BCP', 'Interbank'], true)) {
            $requiredKeys->push('numero_cuenta');
        } elseif ($banco === 'Otro') {
            $requiredKeys = $requiredKeys->merge(['banco_otro', 'cci']);
        }

        if (($data['sistema_pensionario'] ?? null) === 'Sistema Privado de Pensiones') {
            $requiredKeys = $requiredKeys->merge(['tipo_comision', 'tipo_afp', 'cuspp']);
        }

        if (($data['quinta_empleador_principal'] ?? null) === 'Otra empresa') {
            $requiredKeys = $requiredKeys->merge(['quinta_otra_empresa', 'quinta_otra_empresa_ruc']);
        }

        return $requiredKeys
            ->unique()
            ->filter(function (string $key) use ($data): bool {
                $value = $data[$key] ?? null;

                if (is_array($value)) {
                    return count(array_filter($value, static fn ($item) => filled($item))) === 0;
                }

                return !filled($value);
            })
            ->values()
            ->all();
    }

    private function missingRequiredFieldKeysFromData(array $data): array
    {
        $requiredKeys = collect(PersonalFichaCatalog::requiredKeys());

        if (($data['estado_civil'] ?? null) === 'Otro') {
            $requiredKeys->push('estado_civil_otro');
        }

        if (($data['nacionalidad'] ?? null) === 'Otra') {
            $requiredKeys->push('nacionalidad_otra');
        }

        if (($data['pais_nacimiento'] ?? null) === 'Otro') {
            $requiredKeys = $requiredKeys->merge(['pais_nacimiento_otro', 'lugar_nacimiento_extranjero']);
        } else {
            $requiredKeys = $requiredKeys->merge(['departamento_nacimiento', 'provincia_nacimiento', 'distrito_nacimiento']);
        }

        if (($data['domicilio_tipo'] ?? 'Peru') === 'Extranjero') {
            $requiredKeys = $requiredKeys->merge(['domicilio_pais_otro', 'domicilio_extranjero']);
        } else {
            $requiredKeys = $requiredKeys->merge(['domicilio_departamento', 'domicilio_provincia', 'domicilio_distrito', 'domicilio_direccion']);
        }

        $banco = (string) ($data['banco'] ?? '');
        if (in_array($banco, ['BCP', 'Interbank'], true)) {
            $requiredKeys->push('numero_cuenta');
        } elseif ($banco === 'Otro') {
            $requiredKeys = $requiredKeys->merge(['banco_otro', 'cci']);
        }

        if (($data['sistema_pensionario'] ?? null) === 'Sistema Privado de Pensiones') {
            $requiredKeys = $requiredKeys->merge(['tipo_comision', 'tipo_afp', 'cuspp']);
        }

        if (($data['quinta_empleador_principal'] ?? null) === 'Otra empresa') {
            $requiredKeys = $requiredKeys->merge(['quinta_otra_empresa', 'quinta_otra_empresa_ruc']);
        }

        return $requiredKeys
            ->unique()
            ->filter(function (string $key) use ($data): bool {
                $value = $data[$key] ?? null;

                if (is_array($value)) {
                    return count(array_filter($value, static fn ($item) => filled($item))) === 0;
                }

                return !filled($value);
            })
            ->values()
            ->all();
    }

    public function hasMissingRequiredFields(?PersonalFicha $ficha): bool
    {
        return count($this->missingRequiredFieldKeys($ficha)) > 0;
    }

    public function needsRegularization(?PersonalFicha $ficha): bool
    {
        if (!$ficha) {
            return false;
        }

        return $this->hasMissingRequiredFields($ficha)
            || $this->hasMissingRequiredDocuments($ficha)
            || in_array((string) $ficha->estado, [
                PersonalFicha::ESTADO_OBSERVADO,
                PersonalFicha::ESTADO_RECHAZADO,
                PersonalFicha::ESTADO_LINK_VENCIDO,
            ], true);
    }

    public function regularizationSummary(?PersonalFicha $ficha): array
    {
        if (!$ficha) {
            return [
                'missing_fields' => [],
                'missing_documents' => [],
                'can_regularize' => false,
                'has_active_link' => false,
                'link' => null,
                'url' => null,
            ];
        }

        $ficha->loadMissing('link');
        $missingFields = $this->missingRequiredFieldKeys($ficha);
        $missingDocuments = $this->missingRequiredDocumentKeys($ficha);
        $link = $ficha->link;

        $requiresManualEnable = $this->needsRegularization($ficha);
        $url = $this->activePublicUrlForLink($link);

        return [
            'missing_fields' => $missingFields,
            'missing_documents' => $missingDocuments,
            'can_regularize' => $requiresManualEnable,
            'has_active_link' => !empty($url),
            'link' => $link,
            'url' => $url,
        ];
    }

    private function isUsableActiveLink(?PersonalFichaLink $link): bool
    {
        return $link !== null
            && $link->estado === PersonalFichaLink::ESTADO_ACTIVO
            && $link->disabled_at === null
            && $link->submitted_at === null
            && $link->expires_at !== null
            && $link->expires_at->greaterThan(now())
            && filled($link->token_encrypted);
    }

    private function activePublicUrlForLink(?PersonalFichaLink $link): ?string
    {
        if (!$this->isUsableActiveLink($link)) {
            return null;
        }

        return $this->publicUrlForLink($link);
    }

    private function ensureActiveLinkWindow(PersonalFichaLink $link, int $hours = self::DEFAULT_LINK_HOURS): PersonalFichaLink
    {
        $expiresAt = $this->minimumLinkExpiration($hours);

        if (!$link->expires_at || $link->expires_at->lessThan($expiresAt)) {
            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_ACTIVO,
                'disabled_at' => null,
                'submitted_at' => null,
                'read_until' => null,
                'expires_at' => $expiresAt,
                'enabled_manually_at' => $link->enabled_manually_at ?: now(),
            ])->save();

            return $link->fresh();
        }

        if (!$link->enabled_manually_at) {
            $link->forceFill([
                'enabled_manually_at' => now(),
            ])->save();

            return $link->fresh();
        }

        return $link;
    }

    private function minimumLinkExpiration(int $hours = self::DEFAULT_LINK_HOURS): Carbon
    {
        return now()->copy()->addHours(max(1, $hours));
    }

    private function normalizeFichaLinkToken(mixed $token): ?string
    {
        if (!is_string($token)) {
            return null;
        }

        $token = trim($token);
        if ($token === '') {
            return null;
        }

        if (preg_match('/^s:\d+:"/u', $token) === 1) {
            $unserialized = @unserialize($token, ['allowed_classes' => false]);
            if (is_string($unserialized) && trim($unserialized) !== '') {
                return trim($unserialized);
            }
        }

        return $token;
    }

    private function createSecureLink(PersonalFicha $ficha, int $hours = self::DEFAULT_LINK_HOURS, bool $enabledManually = false): array
    {
        $this->assertFichaHasPersistedPersonal($ficha);

        PersonalFichaLink::query()
            ->where('personal_ficha_id', $ficha->id)
            ->where('estado', PersonalFichaLink::ESTADO_ACTIVO)
            ->update([
                'estado' => PersonalFichaLink::ESTADO_INHABILITADO,
                'disabled_at' => now(),
                'enabled_manually_at' => null,
            ]);

        do {
            $token = Str::random(80);
            $hash = hash('sha256', $token);
        } while (PersonalFichaLink::query()->where('token_hash', $hash)->exists());

        $expiresAt = $this->minimumLinkExpiration($hours);

        $link = PersonalFichaLink::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'token_hash' => $hash,
            'token_encrypted' => Crypt::encryptString($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => $expiresAt,
            'enabled_manually_at' => $enabledManually ? now() : null,
        ]);

        return [$token, $link];
    }

    private function assertFichaHasPersistedPersonal(PersonalFicha $ficha): void
    {
        $ficha->loadMissing('personal');

        if (
            !$ficha->exists
            || !$ficha->personal_id
            || !$ficha->personal
            || !Personal::query()->whereKey((string) $ficha->personal_id)->exists()
        ) {
            Log::warning('Intento de generar link temporal sin trabajador persistido.', [
                'ficha_id' => $ficha->id,
                'personal_id' => $ficha->personal_id,
            ]);

            throw ValidationException::withMessages([
                'personal' => 'No se puede habilitar el link porque el trabajador no esta guardado correctamente en la base de datos.',
            ]);
        }
    }

    private function assertTemporaryLinkReady(array $result, ?string $token = null): void
    {
        $personalId = (string) (($result['personal'] ?? null)?->id ?? ($result['ficha'] ?? null)?->personal_id ?? '');
        $fichaId = (string) (($result['ficha'] ?? null)?->id ?? ($result['link'] ?? null)?->personal_ficha_id ?? '');
        $linkId = (string) (($result['link'] ?? null)?->id ?? '');

        $personalExists = $personalId !== '' && Personal::query()->whereKey($personalId)->exists();
        $fichaExists = $fichaId !== '' && PersonalFicha::query()
            ->whereKey($fichaId)
            ->where('personal_id', $personalId)
            ->exists();
        $linkQuery = PersonalFichaLink::query()
            ->whereKey($linkId)
            ->where('personal_ficha_id', $fichaId)
            ->where('estado', PersonalFichaLink::ESTADO_ACTIVO)
            ->whereNull('disabled_at')
            ->whereNull('submitted_at')
            ->where('expires_at', '>', now())
            ->whereNotNull('token_encrypted');

        if ($token) {
            $linkQuery->where('token_hash', hash('sha256', $token));
        }

        $linkReady = $linkId !== '' && $linkQuery->exists();

        if ($personalExists && $fichaExists && $linkReady) {
            return;
        }

        Log::error('Link temporal generado sin respaldo consistente en base de datos.', [
            'personal_id' => $personalId,
            'ficha_id' => $fichaId,
            'link_id' => $linkId,
            'personal_exists' => $personalExists,
            'ficha_exists' => $fichaExists,
            'link_ready' => $linkReady,
        ]);

        throw ValidationException::withMessages([
            'personal' => 'El trabajador no quedo guardado correctamente y no se genero un link seguro. Intenta guardar nuevamente antes de enviar el enlace.',
        ]);
    }

    private function mergeNonEmptyFichaData(array $base, array $incoming): array
    {
        $merged = $base;

        foreach ($incoming as $key => $value) {
            $text = PersonalNormalizer::text($value);
            if ($text === '' && PersonalNormalizer::text($merged[$key] ?? '') !== '') {
                continue;
            }

            $merged[$key] = $value;
        }

        return $merged;
    }

    private function storeFichaDocuments(?string $fichaId, array $documentos): array
    {
        if ($fichaId === null || $fichaId === '') {
            return collect($documentos)
                ->filter(fn ($documento) => $documento instanceof UploadedFile)
                ->mapWithKeys(function (UploadedFile $documento, string $tipo): array {
                    $safeTipo = $this->safeFichaArchivoTipo((string) $tipo);

                    return [$safeTipo => [
                        'file' => $documento,
                        'path' => null,
                    ]];
                })
                ->all();
        }

        $documentPaths = [];
        foreach ($documentos as $tipo => $documento) {
            if (!$documento instanceof UploadedFile) {
                continue;
            }

            $safeTipo = $this->safeFichaArchivoTipo((string) $tipo);
            $documentPaths[$safeTipo] = [
                'file' => $documento,
                'path' => $documento->storeAs(
                    'personal_fichas/' . $fichaId . '/documentos',
                    $safeTipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($documento->getClientOriginalExtension() ?: 'bin'),
                    'local',
                ),
            ];
        }

        return $documentPaths;
    }

    private function syncManualHuella(PersonalFicha $ficha, mixed $huella, Usuario $user): void
    {
        if (!$huella instanceof UploadedFile) {
            return;
        }

        $path = $huella->storeAs(
            'personal_fichas/' . $ficha->id,
            'huella_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($huella->getClientOriginalExtension() ?: 'jpg'),
            'local',
        );

        $ficha->forceFill(['huella_path' => $path])->save();

        $this->replaceFichaArchivo(
            $ficha,
            'huella',
            $huella,
            $path,
            false,
            $user,
        );
    }

    private function syncFichaDocuments(PersonalFicha $ficha, array $documentPaths, Usuario $user): void
    {
        foreach ($documentPaths as $tipo => $payload) {
            /** @var UploadedFile $documento */
            $documento = $payload['file'];
            $path = $payload['path'];

            if ($path === null) {
                $safeTipo = $this->safeFichaArchivoTipo((string) $tipo);
                $path = $documento->storeAs(
                    'personal_fichas/' . $ficha->id . '/documentos',
                    $safeTipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($documento->getClientOriginalExtension() ?: 'bin'),
                    'local',
                );
            }

            $this->replaceFichaArchivo(
                $ficha,
                $tipo,
                $documento,
                $path,
                false,
                $user,
            );
        }
    }

    private function safeFichaArchivoTipo(string $tipo): string
    {
        if ($tipo === 'huella' || array_key_exists($tipo, PersonalFichaCatalog::documentRequirements())) {
            return $tipo;
        }

        return Str::slug($tipo, '_') ?: 'documento';
    }

    private function documentApplies(PersonalFicha $ficha, string $tipo, array $requirement): bool
    {
        $condition = $requirement['condition'] ?? null;
        if ($condition === null) {
            return true;
        }

        $ficha->loadMissing('familiares');
        $rawData = is_array($ficha->datos_json ?? null) ? $ficha->datos_json : [];
        $data = [
            ...$rawData,
            ...$this->normalizeFichaData($rawData),
        ];

        return match ($condition) {
            'married' => $this->isMarriedCivilState((string) ($data['estado_civil'] ?? '')),
            'minor_children' => $this->hasChildrenByAge($ficha, 'minor'),
            'adult_studying_children' => $this->hasAdultStudyingChildren($ficha) || ($this->hasChildrenByAge($ficha, 'adult') && $this->hasAdultStudyingChildrenFlag($data)),
            default => true,
        };
    }

    private function documentAppliesToPayload(array $data, array $familiares, array $requirement): bool
    {
        $condition = $requirement['condition'] ?? null;
        if ($condition === null) {
            return true;
        }

        return match ($condition) {
            'married' => $this->isMarriedCivilState((string) ($data['estado_civil'] ?? '')),
            'minor_children' => $this->payloadHasChildrenByAge($familiares, 'minor'),
            'adult_studying_children' => $this->payloadHasAdultStudyingChildren($familiares) || ($this->payloadHasChildrenByAge($familiares, 'adult') && $this->hasAdultStudyingChildrenFlag($data)),
            default => true,
        };
    }

    private function isMarriedCivilState(string $state): bool
    {
        $normalized = Str::lower(trim($state));

        return in_array($normalized, ['casado', 'casada', 'conviviente'], true);
    }

    private function hasChildrenByAge(PersonalFicha $ficha, string $ageGroup): bool
    {
        return $ficha->familiares->contains(function (PersonalFichaFamiliar $familiar) use ($ageGroup): bool {
            $parentesco = Str::lower(trim((string) $familiar->parentesco));
            if (!str_contains($parentesco, 'hijo') && !str_contains($parentesco, 'hija')) {
                return false;
            }

            if (!$familiar->fecha_nacimiento) {
                return false;
            }

            $age = $familiar->fecha_nacimiento->age;

            return $ageGroup === 'minor'
                ? $age < 18
                : $age >= 18;
        });
    }

    private function hasAdultStudyingChildren(PersonalFicha $ficha): bool
    {
        return $ficha->familiares->contains(function (PersonalFichaFamiliar $familiar): bool {
            $parentesco = Str::lower(trim((string) $familiar->parentesco));
            if (!str_contains($parentesco, 'hijo') && !str_contains($parentesco, 'hija')) {
                return false;
            }

            return (bool) $familiar->estudia
                && $familiar->fecha_nacimiento
                && $familiar->fecha_nacimiento->age >= 18;
        });
    }

    private function hasAdultStudyingChildrenFlag(array $data): bool
    {
        foreach (['hijos_mayores_estudian', 'hijos_mayores_estudiantes', 'tiene_hijos_mayores_estudiando'] as $key) {
            $value = Str::lower(trim((string) ($data[$key] ?? '')));
            if (in_array($value, ['1', 'si', 'sí', 'true', 'estudia', 'estudian'], true)) {
                return true;
            }
        }

        return false;
    }

    private function payloadHasChildrenByAge(array $familiares, string $ageGroup): bool
    {
        foreach ($familiares as $familiar) {
            if (!is_array($familiar)) {
                continue;
            }

            $parentesco = Str::lower(trim((string) ($familiar['parentesco'] ?? '')));
            if (!str_contains($parentesco, 'hijo') && !str_contains($parentesco, 'hija')) {
                continue;
            }

            $date = trim((string) ($familiar['fecha_nacimiento'] ?? ''));
            if ($date === '') {
                continue;
            }

            try {
                $age = Carbon::parse($date)->age;
            } catch (\Throwable) {
                continue;
            }

            if ($ageGroup === 'minor' && $age < 18) {
                return true;
            }

            if ($ageGroup === 'adult' && $age >= 18) {
                return true;
            }
        }

        return false;
    }

    private function payloadHasAdultStudyingChildren(array $familiares): bool
    {
        foreach ($familiares as $familiar) {
            if (!is_array($familiar) || empty($familiar['estudia'])) {
                continue;
            }

            $parentesco = Str::lower(trim((string) ($familiar['parentesco'] ?? '')));
            if (!str_contains($parentesco, 'hijo') && !str_contains($parentesco, 'hija')) {
                continue;
            }

            $date = trim((string) ($familiar['fecha_nacimiento'] ?? ''));
            if ($date === '') {
                continue;
            }

            try {
                if (Carbon::parse($date)->age >= 18) {
                    return true;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    private function markDocumentUploaded(PersonalFicha $ficha, string $tipo, ?Usuario $user): void
    {
        if (!Schema::hasTable('personal_documento_estados') || !array_key_exists($tipo, PersonalFichaCatalog::documentRequirements())) {
            return;
        }

        $record = PersonalDocumentoEstado::query()
            ->firstOrNew([
                'personal_ficha_id' => $ficha->id,
                'tipo' => $tipo,
            ]);

        if (!$record->exists) {
            $record->id = (string) Str::uuid();
        }

        $record->forceFill([
            'estado' => PersonalDocumentoEstado::ESTADO_CARGADO,
            'updated_by_usuario_id' => $user?->id,
            'estado_updated_at' => now(),
        ])->save();
    }

    private function replaceFichaArchivo(
        PersonalFicha $ficha,
        string $tipo,
        UploadedFile $documento,
        string $path,
        bool $uploadedByPublic,
        ?Usuario $user = null,
    ): PersonalFichaArchivo {
        $existingFiles = PersonalFichaArchivo::query()
            ->where('personal_ficha_id', $ficha->id)
            ->where('tipo', $tipo)
            ->get();

        foreach ($existingFiles as $existing) {
            if ($existing->path && Storage::disk('local')->exists($existing->path)) {
                Storage::disk('local')->delete($existing->path);
            }
            $existing->delete();
        }

        $archivo = PersonalFichaArchivo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'tipo' => $tipo,
            'nombre_original' => $documento->getClientOriginalName(),
            'path' => $path,
            'mime' => $documento->getMimeType(),
            'size' => $documento->getSize(),
            'uploaded_by_usuario_id' => $uploadedByPublic ? null : $user?->id,
            'uploaded_by_public' => $uploadedByPublic,
        ]);

        $this->markDocumentUploaded($ficha, $tipo, $uploadedByPublic ? null : $user);

        return $archivo;
    }

    private function reviewNotificationUserIds(): array
    {
        return Usuario::query()
            ->with(['rol:id,nombre', 'rolesAdicionales:id,nombre'])
            ->when(Schema::hasColumn('usuarios', 'estado'), fn ($query) => $query->where('estado', 'ACTIVO'))
            ->get()
            ->filter(function (Usuario $usuario): bool {
                $roles = collect([$usuario->rol?->nombre])
                    ->merge($usuario->rolesAdicionales->pluck('nombre'))
                    ->filter()
                    ->map(fn ($name): string => strtoupper((string) $name));

                return $roles->contains('ADMIN')
                    || $roles->contains(fn (string $name): bool => str_contains($name, 'RRHH'));
            })
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    private function markLinkExpired(PersonalFichaLink $link): void
    {
        DB::transaction(function () use ($link): void {
            $ficha = $link->ficha()->with('personal')->first();
            if ($ficha && !$ficha->submitted_at) {
                $personal = $ficha->personal;
                if ($personal && in_array(strtoupper((string) $personal->estado), [PersonalFicha::ESTADO_PENDIENTE, PersonalFicha::ESTADO_LINK_VENCIDO], true)) {
                    $personal->forceFill([
                        'estado' => PersonalFicha::ESTADO_LINK_VENCIDO,
                    ])->save();
                }

                $ficha->forceFill([
                    'estado' => PersonalFicha::ESTADO_LINK_VENCIDO,
                ])->save();
            }

            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_VENCIDO,
                'disabled_at' => now(),
            ])->save();
        });
    }

    private function findPersonalByDocument(string $type, string $number): ?Personal
    {
        $query = Personal::query();

        if (Schema::hasColumn('personal', 'numero_documento')) {
            $query->where(function ($q) use ($type, $number): void {
                $q->where('numero_documento', $number)
                    ->orWhere('dni', $number);

                if ($type === 'DNI') {
                    $q->orWhere('dni', PersonalNormalizer::dni($number));
                }
            });
        } else {
            $query->where('dni', $type === 'DNI' ? PersonalNormalizer::dni($number) : $number);
        }

        return $query->with('fichaColaborador')->first();
    }

    private function mergedImportFichaData(?Personal $personal, array $incoming): array
    {
        if (!$personal) {
            return $incoming;
        }

        $base = $personal->fichaColaborador
            ? $this->fichaDataForPublic($personal->fichaColaborador)
            : $this->seedFichaDataFromPersonal($personal);

        return $this->normalizeFichaData($this->mergeNonEmptyFichaData($base, $incoming));
    }

    private function seedFichaDataFromPersonal(Personal $personal): array
    {
        $data = PersonalFichaCatalog::emptyData();
        $nameParts = preg_split('/\s+/', trim((string) $personal->nombre_completo)) ?: [];

        if (count($nameParts) >= 3 && $personal->nombre_completo !== 'Pendiente completar nombres') {
            $data['apellido_paterno'] = $nameParts[0] ?? '';
            $data['apellido_materno'] = $nameParts[1] ?? '';
            $data['nombres'] = implode(' ', array_slice($nameParts, 2));
        } else {
            $data['nombres'] = trim((string) $personal->nombre_completo);
        }

        $data['tipo_documento'] = (string) ($personal->tipo_documento ?? 'DNI');
        $data['numero_documento'] = (string) ($personal->numero_documento ?? $personal->dni ?? '');
        $data['puesto'] = (string) ($personal->puesto ?? '');
        $data['ocupacion'] = (string) ($personal->ocupacion ?? '');
        $data['contrato'] = (string) ($personal->contrato ?? 'REG');
        $data['correo'] = (string) ($personal->correo ?? '');
        $data['telefono'] = (string) ($personal->telefono ?? '');
        $data['fecha_ingreso'] = (string) optional($personal->fecha_ingreso)->toDateString();

        return $this->normalizeFichaData($data);
    }

    private function resolveImportedPersonalState(Personal $personal): string
    {
        $current = strtoupper((string) $personal->estado);

        return in_array($current, ['ACTIVO', 'INACTIVO', 'CESADO'], true)
            ? $current
            : PersonalFicha::ESTADO_PENDIENTE;
    }

    private function personalPayloadFromFicha(array $data, string $estado): array
    {
        $phoneData = PersonalNormalizer::normalizePhonePayload($data['telefono'] ?? '');

        return [
            'tipo_documento' => $data['tipo_documento'] ?? 'DNI',
            'numero_documento' => $data['numero_documento'] ?? '',
            'dni' => $data['numero_documento'] ?? '',
            'nombre_completo' => $this->nombreCompleto($data),
            'puesto' => $data['puesto'] ?: 'Por definir',
            'ocupacion' => $data['ocupacion'] ?? null,
            'contrato' => $data['contrato'] ?: 'REG',
            'telefono_1' => $phoneData['telefono_1'] ?? null,
            'telefono_2' => $phoneData['telefono_2'] ?? null,
            'telefono' => PersonalNormalizer::combinePhones($phoneData['telefono_1'] ?? null, $phoneData['telefono_2'] ?? null),
            'correo' => $data['correo'] ?? null,
            'fecha_ingreso' => $data['fecha_ingreso'] ?: null,
            'estado' => $estado,
            'es_supervisor' => PersonalNormalizer::isSupervisorOccupation($data['ocupacion'] ?? null),
            'minas' => [],
        ];
    }

    private function nombreCompleto(array $data): string
    {
        $name = collect([
            $data['apellido_paterno'] ?? '',
            $data['apellido_materno'] ?? '',
            $data['nombres'] ?? '',
        ])->map(fn ($value): string => PersonalNormalizer::text($value))->filter()->implode(' ');

        return $name !== '' ? mb_strtoupper($name, 'UTF-8') : 'PENDIENTE COMPLETAR NOMBRES';
    }
}
