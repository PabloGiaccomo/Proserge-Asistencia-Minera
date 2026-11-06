<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalDocumentoEstado;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalFichaFamiliar;
use App\Models\PersonalIngreso;
use App\Models\PersonalIngresoArchivo;
use App\Models\PersonalIngresoClave;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaAccess;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalIngresoPurpose;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PersonalIngresoService
{
    public const ESTADO_NO_FIRMO_CONTRATO = 'NO_FIRMO_CONTRATO';

    public function __construct(
        private readonly PersonalFichaService $fichaService,
        private readonly PersonalService $personalService,
        private readonly PersonalContratoService $contratoService,
        private readonly PersonalArchivoVersionService $fileVersions,
    ) {
    }

    public function publicUrl(): string
    {
        return route('personal.ingresos.public.show');
    }

    public function todayKey(): array
    {
        $today = Carbon::today()->toDateString();
        $record = PersonalIngresoClave::query()->whereDate('fecha', $today)->first();

        if (!$record) {
            $plain = (string) random_int(100000, 999999);
            $record = PersonalIngresoClave::query()->create([
                'id' => (string) Str::uuid(),
                'fecha' => $today,
                'clave_hash' => Hash::make($plain),
                'clave_encrypted' => Crypt::encryptString($plain),
            ]);
        }

        try {
            $clave = Crypt::decryptString((string) $record->clave_encrypted);
        } catch (\Throwable) {
            $clave = (string) random_int(100000, 999999);
            $record->forceFill([
                'clave_hash' => Hash::make($clave),
                'clave_encrypted' => Crypt::encryptString($clave),
            ])->save();
        }

        return [
            'fecha' => $today,
            'clave' => $clave,
            'actualizada' => optional($record->updated_at)->format('d/m/Y H:i'),
        ];
    }

    public function verifyDailyKey(string $key): bool
    {
        $record = PersonalIngresoClave::query()
            ->whereDate('fecha', Carbon::today()->toDateString())
            ->first();

        return $record !== null && Hash::check(trim($key), (string) $record->clave_hash);
    }

    public function list(array $filters = []): Collection
    {
        $estado = strtoupper(trim((string) ($filters['estado'] ?? '')));
        $search = PersonalNormalizer::normalizeKey((string) ($filters['search'] ?? ''));
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();

        return PersonalIngreso::query()
            ->with(['archivos', 'personalExistente', 'personalCreado', 'revisadoPor.personal'])
            ->where('estado', '!=', PersonalIngreso::ESTADO_BORRADOR)
            ->where(function ($query) use ($todayStart, $todayEnd): void {
                $query->where('estado', '!=', PersonalIngreso::ESTADO_ACEPTADA)
                    ->orWhere(function ($acceptedQuery) use ($todayStart, $todayEnd): void {
                        $acceptedQuery->where('estado', PersonalIngreso::ESTADO_ACEPTADA)
                            ->where(function ($dateQuery) use ($todayStart, $todayEnd): void {
                                $dateQuery->whereBetween('reviewed_at', [$todayStart, $todayEnd])
                                    ->orWhere(function ($fallbackQuery) use ($todayStart, $todayEnd): void {
                                        $fallbackQuery->whereNull('reviewed_at')
                                            ->whereBetween('updated_at', [$todayStart, $todayEnd]);
                                    });
                            });
                    });
            })
            ->when($estado !== '', fn ($query) => $query->where('estado', $estado))
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalIngreso $ingreso): PersonalIngreso => $this->refreshExistingPersonalRelation($ingreso))
            ->filter(function (PersonalIngreso $ingreso) use ($search): bool {
                if ($search === '') {
                    return true;
                }

                $data = is_array($ingreso->datos_json) ? $ingreso->datos_json : [];
                $haystack = PersonalNormalizer::normalizeKey(collect([
                    $this->nombreCompleto($data),
                    $ingreso->personalExistente?->nombre_completo,
                    $ingreso->tipo_documento,
                    $ingreso->numero_documento,
                    $data['puesto'] ?? '',
                    $data['correo'] ?? '',
                ])->implode(' '));

                return str_contains($haystack, $search);
            })
            ->values();
    }

    public function findOrFail(string $id): PersonalIngreso
    {
        $ingreso = PersonalIngreso::query()
            ->with(['archivos', 'personalExistente', 'personalCreado', 'revisadoPor.personal'])
            ->findOrFail($id);

        return $this->refreshExistingPersonalRelation($ingreso);
    }

    public function storeSubmission(array $fields, array $familiares, string $firmaBase64, UploadedFile $huella, array $documentos = [], ?string $submissionUuid = null): PersonalIngreso
    {
        return DB::transaction(fn (): PersonalIngreso => $this->storeSubmissionWithinTransaction(
            $fields, $familiares, $firmaBase64, $huella, $documentos, $submissionUuid,
        ));
    }

    private function storeSubmissionWithinTransaction(array $fields, array $familiares, string $firmaBase64, UploadedFile $huella, array $documentos, ?string $submissionUuid): PersonalIngreso
    {
        $data = $this->fichaService->normalizeFichaData($fields);
        $this->assertDocumentIsValid($data);
        $personalExistente = $this->findPersonalByDocument($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
        $submissionUuid = $this->normalizeSubmissionUuid($submissionUuid);
        $supportsSubmissionUuid = $this->supportsSubmissionUuid();

        $ingreso = DB::transaction(function () use ($data, $familiares, $firmaBase64, $personalExistente, $submissionUuid, $supportsSubmissionUuid): PersonalIngreso {
            $payload = [
                'estado' => PersonalIngreso::ESTADO_RECIBIDA,
                'tipo_documento' => $data['tipo_documento'] ?? 'DNI',
                'numero_documento' => $data['numero_documento'] ?? '',
                'personal_existente_id' => $personalExistente?->id,
                'datos_json' => $data,
                'familiares_json' => $this->fichaService->normalizeFamiliares($familiares),
                'firma_base64' => $firmaBase64,
                'submitted_at' => now(),
            ];

            if ($supportsSubmissionUuid && $submissionUuid !== null) {
                $payload['submission_uuid'] = $submissionUuid;
            }

            $ingreso = null;
            if ($supportsSubmissionUuid && $submissionUuid !== null) {
                $ingreso = PersonalIngreso::query()
                    ->where('submission_uuid', $submissionUuid)
                    ->lockForUpdate()
                    ->first();
            }

            if ($ingreso instanceof PersonalIngreso) {
                abort_unless(($ingreso->proposito ?? PersonalIngresoPurpose::ALTA) === PersonalIngresoPurpose::ALTA,
                    409, 'Este identificador de envío pertenece a otra solicitud.');
                if (in_array($ingreso->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
                    return $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado']);
                }

                $ingreso->forceFill($payload)->save();

                return $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado']);
            }

            return PersonalIngreso::query()->create([
                'id' => (string) Str::uuid(),
                ...$payload,
            ])->fresh(['archivos', 'personalExistente', 'personalCreado']);
        });

        if (in_array($ingreso->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
            return $ingreso;
        }

        try {
            $this->replaceIngresoArchivo($ingreso, 'huella', $huella, false);
        } catch (Throwable $exception) {
            Log::error('No se pudo guardar la huella de ingreso publico.', [
                'personal_ingreso_id' => $ingreso->id,
                'submission_uuid' => $submissionUuid,
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'huella' => 'No se pudo guardar el envío con la foto de huella. Vuelve a enviarlo sin cerrar la página.',
            ]);
        }

        $documentWarnings = [];
        foreach ($documentos as $tipo => $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $safeTipo = $this->safeFileType((string) $tipo);
            try {
                $this->replaceIngresoArchivo($ingreso, $safeTipo, $file, true);
            } catch (Throwable $exception) {
                $documentWarnings[] = $safeTipo;
                Log::warning('No se pudo guardar documento opcional de ingreso publico.', [
                    'personal_ingreso_id' => $ingreso->id,
                    'submission_uuid' => $submissionUuid,
                    'tipo' => $safeTipo,
                    'exception' => $exception::class,
                ]);
            }
        }

        if ($documentWarnings !== []) {
            $this->appendIngresoObservation(
                $ingreso,
                'Algunos documentos opcionales no se guardaron correctamente: ' . implode(', ', array_unique($documentWarnings)) . '.',
            );
        }

        return $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado']);
    }

    public function updateIngreso(PersonalIngreso $ingreso, array $fields, array $familiares, array $biometrics, array $documentos, Usuario $user): PersonalIngreso
    {
        return DB::transaction(function () use ($ingreso, $fields, $familiares, $biometrics, $documentos, $user): PersonalIngreso {
            $ingreso = PersonalIngreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $this->assertIngresoEditable($ingreso);

            $current = $ingreso->datos_json ?? [];
            $firmaBase64 = $biometrics['firma_base64'] ?? null;
            $huella = $biometrics['huella'] ?? null;
            PersonalFichaAccess::assertSensitiveChangesAllowed(
                $user,
                $current,
                $fields,
                array_key_exists('firma_base64', $biometrics) && (string) $firmaBase64 !== (string) $ingreso->firma_base64,
                isset($documentos['huella'])
                    || (array_key_exists('huella', $biometrics) && ($huella !== null || filled($ingreso->huella_path))),
            );

            $data = $this->fichaService->normalizeFichaData(array_replace(PersonalFichaAccess::onlySensitive($current), $fields));
            $data = PersonalFichaAccess::preserveOmitted($data, $current, $fields);
            $this->assertDocumentIsValid($data);
            $personalExistente = $this->findPersonalByDocument($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');
            $payload = [
                'estado' => PersonalIngreso::ESTADO_FALTA_REVISION,
                'tipo_documento' => $data['tipo_documento'] ?? 'DNI',
                'numero_documento' => $data['numero_documento'] ?? '',
                'personal_existente_id' => $personalExistente?->id,
                'datos_json' => $data,
                'familiares_json' => $this->fichaService->normalizeFamiliares($familiares),
                'reviewed_at' => now(),
                'reviewed_by_usuario_id' => $user->id,
            ];

            if (filled($firmaBase64)) {
                $payload['firma_base64'] = $firmaBase64;
            }

            if ($huella instanceof UploadedFile) {
                $payload['huella_path'] = $this->replaceIngresoArchivo($ingreso, 'huella', $huella, false, $user);
            }

            $ingreso->forceFill($payload)->save();

            foreach (array_intersect_key($documentos, PersonalFichaCatalog::documentRequirements()) as $tipo => $file) {
                if ($file instanceof UploadedFile) {
                    $this->replaceIngresoArchivo($ingreso, $this->safeFileType((string) $tipo), $file, true, $user);
                }
            }

            return $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado', 'revisadoPor.personal']);
        });
    }

    public function accept(PersonalIngreso $ingreso, Usuario $user, array $contractPayload): Personal
    {
        return $this->persistIntoPersonal($ingreso, $user, false, $contractPayload);
    }

    public function acceptNewFromBatch(PersonalIngreso $ingreso, Usuario $user, array $contractPayload, array &$newFiles): Personal
    {
        return $this->persistIntoPersonal($ingreso, $user, false, $contractPayload, $newFiles);
    }

    public function markContractNotSigned(PersonalIngreso $ingreso, Usuario $user): Personal
    {
        return $this->persistIntoPersonal($ingreso, $user, true);
    }

    public function deleteErroneous(PersonalIngreso $ingreso): void
    {
        abort_if($ingreso->proposito === PersonalIngresoPurpose::ACTUALIZACION, 403, 'La actualización conserva su historial de revisión.');
        if ($ingreso->personal_creado_id || in_array($ingreso->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
            throw ValidationException::withMessages([
                'ingreso' => 'Esta ficha ya fue llevada a Personal. No se elimina desde Ingresos para proteger la trazabilidad.',
            ]);
        }

        DB::transaction(function () use ($ingreso): void {
            $ingreso->loadMissing('archivos');
            foreach ($ingreso->archivos as $archivo) {
                if ($archivo->path && Storage::disk('local')->exists($archivo->path)) {
                    Storage::disk('local')->delete($archivo->path);
                }
            }

            if ($ingreso->huella_path && Storage::disk('local')->exists($ingreso->huella_path)) {
                Storage::disk('local')->delete($ingreso->huella_path);
            }

            $directory = 'personal_ingresos/' . $ingreso->id;
            if (Storage::disk('local')->exists($directory)) {
                Storage::disk('local')->deleteDirectory($directory);
            }

            $ingreso->delete();
        });
    }

    public function dataForForm(?PersonalIngreso $ingreso = null): array
    {
        $data = $ingreso && is_array($ingreso->datos_json)
            ? $ingreso->datos_json
            : PersonalFichaCatalog::emptyData();
        $data = $this->fichaService->normalizeFichaData($data);

        $data['tipo_documento'] = $data['tipo_documento'] ?? $ingreso?->tipo_documento ?? 'DNI';
        $data['numero_documento'] = $data['numero_documento'] ?? $ingreso?->numero_documento ?? '';

        return $data;
    }

    public function familyRowsForForm(?PersonalIngreso $ingreso = null): array
    {
        $familiares = $ingreso && is_array($ingreso->familiares_json) ? $ingreso->familiares_json : [];

        if (!empty($familiares)) {
            return $familiares;
        }

        return collect(['Padre', 'Madre', 'Conyuge'])
            ->map(fn (string $parentesco): array => [
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

    public function statusLabel(string $state): string
    {
        return match (strtoupper($state)) {
            PersonalIngreso::ESTADO_RECIBIDA => 'Ficha recibida',
            PersonalIngreso::ESTADO_FALTA_REVISION => 'Falta revision',
            PersonalIngreso::ESTADO_ACEPTADA => 'Agregado a Personal',
            PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO => 'No firmo contrato',
            default => str_replace('_', ' ', $state),
        };
    }

    public function statusClass(string $state): string
    {
        return match (strtoupper($state)) {
            PersonalIngreso::ESTADO_ACEPTADA => 'success',
            PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO => 'warning',
            PersonalIngreso::ESTADO_FALTA_REVISION => 'info',
            default => 'pending',
        };
    }

    private function persistIntoPersonal(PersonalIngreso $ingreso, Usuario $user, bool $contractNotSigned, array $contractPayload = [], ?array &$newFiles = null): Personal
    {
        return DB::transaction(function () use ($ingreso, $contractNotSigned, $contractPayload, $user, &$newFiles): Personal {
            $ingreso = PersonalIngreso::query()->with('archivos')->lockForUpdate()->findOrFail($ingreso->id);
            $this->assertIngresoEditable($ingreso);
            $data = $this->fichaService->normalizeFichaData($ingreso->datos_json ?? []);
            $this->assertDocumentIsValid($data);
            $existing = $this->findPersonalByDocument($data['tipo_documento'] ?? $ingreso->tipo_documento, $data['numero_documento'] ?? $ingreso->numero_documento);
            if ($newFiles !== null && ($existing || $ingreso->personal_existente_id || $ingreso->personal_creado_id)) {
                throw ValidationException::withMessages(['ingreso' => 'Ya existe un trabajador con este documento. Revisa la ficha individualmente.']);
            }
            $estado = $contractNotSigned
                ? self::ESTADO_NO_FIRMO_CONTRATO
                : ($existing ? (string) $existing->estado : PersonalFicha::ESTADO_APROBADO);
            if (!$contractNotSigned && in_array(strtoupper($estado), ['', PersonalFicha::ESTADO_PENDIENTE, PersonalFicha::ESTADO_ENVIADA, PersonalFicha::ESTADO_OBSERVADO, PersonalFicha::ESTADO_LINK_VENCIDO], true)) {
                $estado = PersonalFicha::ESTADO_APROBADO;
            }

            $payload = [
                ...$this->personalPayloadFromData($data, $estado),
                'origen_registro' => 'NUEVO',
                'pendiente_contrato_firmado' => !$contractNotSigned,
            ];

            if (!$contractNotSigned && !empty($contractPayload['fecha_inicio_contrato'])) {
                $payload['fecha_ingreso'] = PersonalNormalizer::isoDate($contractPayload['fecha_inicio_contrato']);
            }

            $personal = $existing
                ? $this->personalService->update($existing, [...$payload, 'origen_registro' => $existing->origen_registro ?: 'NUEVO'])
                : $this->personalService->create($payload);

            $fichaEstado = $contractNotSigned ? PersonalFicha::ESTADO_ENVIADA : PersonalFicha::ESTADO_APROBADO;
            $this->syncFichaFromIngreso($personal, $ingreso, $fichaEstado, $user, $newFiles);

            if (!$contractNotSigned) {
                $signedContractFile = $contractPayload['contrato_pdf'] ?? null;
                $contract = $this->contratoService->prepareIngressContract(
                    $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos']) ?: $personal,
                    [
                        ...$contractPayload,
                        'tipo_contrato' => $data['contrato'] ?? $personal->contrato,
                        'puesto' => $data['puesto'] ?? $personal->puesto,
                        'area' => $data['puesto'] ?? $personal->puesto,
                    ],
                    $user,
                );

                if ($signedContractFile instanceof UploadedFile && !$contract->hasSignedFile()) {
                    $contract = $this->contratoService->uploadSignedFileForContract(
                        $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos']) ?: $personal,
                        $contract,
                        $signedContractFile,
                        $user,
                    );
                }

                if (Schema::hasColumn('personal', 'pendiente_contrato_firmado')) {
                    $personal->forceFill(['pendiente_contrato_firmado' => !$contract->hasSignedFile()])->save();
                }
            }

            $ingreso->forceFill([
                'estado' => $contractNotSigned ? PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO : PersonalIngreso::ESTADO_ACEPTADA,
                'personal_existente_id' => $existing?->id,
                'personal_creado_id' => $personal->id,
                'reviewed_at' => now(),
                'reviewed_by_usuario_id' => $user->id,
            ])->save();

            return $personal->fresh(['fichaColaborador', 'contratosLaborales', 'contratoDatos']);
        });
    }

    private function syncFichaFromIngreso(Personal $personal, PersonalIngreso $ingreso, string $estado, Usuario $user, ?array &$newFiles = null): PersonalFicha
    {
        $data = is_array($ingreso->datos_json) ? $ingreso->datos_json : [];
        $ficha = $personal->fichaColaborador ?: new PersonalFicha([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
        ]);

        $ficha->forceFill([
            'personal_id' => $personal->id,
            'estado' => $estado,
            'tipo_documento' => $data['tipo_documento'] ?? $ingreso->tipo_documento,
            'numero_documento' => $data['numero_documento'] ?? $ingreso->numero_documento,
            'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato'] ?? null),
            'datos_detectados_json' => $data,
            'datos_json' => $data,
            'campos_verificacion_json' => PersonalFichaCatalog::defaultVerificationKeys(),
            'advertencias_json' => [],
            'firma_base64' => $ingreso->firma_base64,
            'submitted_at' => $ingreso->submitted_at ?: now(),
            'approved_at' => $estado === PersonalFicha::ESTADO_APROBADO ? now() : null,
            'approved_by_usuario_id' => $estado === PersonalFicha::ESTADO_APROBADO ? $user->id : null,
        ])->save();

        $this->copyHuellaToFicha($ingreso, $ficha, $user, $newFiles);
        $this->syncFamiliares($ficha, $ingreso);
        $this->copyArchivosToFicha($ingreso, $ficha, $newFiles);

        return $ficha->fresh(['archivos', 'familiares']);
    }

    private function syncFamiliares(PersonalFicha $ficha, PersonalIngreso $ingreso): void
    {
        PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();

        foreach ($this->fichaService->normalizeFamiliares($ingreso->familiares_json ?? []) as $familiar) {
            PersonalFichaFamiliar::query()->create([
                'id' => (string) Str::uuid(),
                'personal_ficha_id' => $ficha->id,
                ...$familiar,
            ]);
        }
    }

    private function copyHuellaToFicha(PersonalIngreso $ingreso, PersonalFicha $ficha, Usuario $actor, ?array &$newFiles): void
    {
        if (!$ingreso->huella_path || !Storage::disk('local')->exists($ingreso->huella_path)) {
            return;
        }

        $extension = pathinfo($ingreso->huella_path, PATHINFO_EXTENSION) ?: 'jpg';
        $target = 'personal_fichas/' . $ficha->id . '/huella_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $extension;
        $this->copyToFicha($ingreso->huella_path, $target, $newFiles);
        $this->fileVersions->archiveCurrent($ficha, 'huella', $actor->id, 'ACEPTACION_INGRESO');
        $ficha->forceFill(['huella_path' => $target])->save();
    }

    private function copyArchivosToFicha(PersonalIngreso $ingreso, PersonalFicha $ficha, ?array &$newFiles): void
    {
        $ingreso->loadMissing('archivos');

        foreach ($ingreso->archivos as $archivo) {
            if ($archivo->tipo === 'huella' || !$archivo->path || !Storage::disk('local')->exists($archivo->path)) {
                continue;
            }

            $extension = pathinfo($archivo->path, PATHINFO_EXTENSION) ?: 'bin';
            $target = 'personal_fichas/' . $ficha->id . '/documentos/' . $archivo->tipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $extension;
            $this->copyToFicha($archivo->path, $target, $newFiles);

            $this->replaceFichaArchivoFromStored($ficha, $archivo, $target);
        }
    }

    private function copyToFicha(string $source, string $target, ?array &$newFiles): void
    {
        $this->fileVersions->trackNew($target);
        if ($newFiles !== null) {
            $newFiles[] = $target;
        }
        if (!Storage::disk('local')->copy($source, $target)) {
            throw ValidationException::withMessages(['ingreso' => 'No se pudo copiar un archivo de la ficha. Intenta nuevamente.']);
        }
    }

    private function replaceFichaArchivoFromStored(PersonalFicha $ficha, PersonalIngresoArchivo $source, string $target): PersonalFichaArchivo
    {
        $this->fileVersions->archiveCurrent($ficha, $source->tipo, $ficha->approved_by_usuario_id, 'ACEPTACION_INGRESO');

        $archivo = PersonalFichaArchivo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'tipo' => $source->tipo,
            'nombre_original' => $source->nombre_original,
            'path' => $target,
            'mime' => $source->mime,
            'size' => $source->size,
            'uploaded_by_usuario_id' => null,
            'uploaded_by_public' => true,
        ]);

        $this->markDocumentUploaded($ficha, $source->tipo);

        return $archivo;
    }

    private function markDocumentUploaded(PersonalFicha $ficha, string $tipo): void
    {
        if (!Schema::hasTable('personal_documento_estados')) {
            return;
        }

        $record = PersonalDocumentoEstado::query()->firstOrNew([
            'personal_ficha_id' => $ficha->id,
            'tipo' => $tipo,
        ]);

        if (!$record->exists) {
            $record->id = (string) Str::uuid();
        }

        $record->forceFill([
            'estado' => PersonalDocumentoEstado::ESTADO_CARGADO,
            'updated_by_usuario_id' => null,
            'estado_updated_at' => now(),
        ])->save();
    }

    private function replaceIngresoArchivo(PersonalIngreso $ingreso, string $tipo, UploadedFile $file, bool $documento, ?Usuario $actor = null): string
    {
        return DB::transaction(function () use ($ingreso, $tipo, $file, $documento, $actor): string {
            $owner = PersonalIngreso::query()->lockForUpdate()->findOrFail($ingreso->id);
            $safeTipo = $this->safeFileType($tipo);
            if ($actor === null) {
                abort_unless(($owner->proposito ?? PersonalIngresoPurpose::ALTA) === PersonalIngresoPurpose::ALTA,
                    409, 'Este identificador de envío pertenece a otra solicitud.');
                if (in_array($owner->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
                    return (string) ($safeTipo === 'huella' ? $owner->huella_path : $owner->archivos()->where('tipo', $safeTipo)->value('path'));
                }
            }
            $directory = 'personal_ingresos/'.$owner->id.($documento ? '/documentos' : '');
            $filename = $safeTipo.'_'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension() ?: 'bin');
            $path = $this->fileVersions->store($file, $directory, $filename, 'local');
            $this->fileVersions->archiveCurrent($owner, $safeTipo, $actor?->id, $actor ? 'RRHH_INGRESO' : 'ENLACE_INGRESO');
            PersonalIngresoArchivo::query()->create([
                'id' => (string) Str::uuid(), 'personal_ingreso_id' => $owner->id, 'tipo' => $safeTipo,
                'nombre_original' => $file->getClientOriginalName(), 'path' => $path,
                'mime' => $file->getMimeType(), 'size' => $file->getSize(),
            ]);
            if ($safeTipo === 'huella') {
                $owner->forceFill(['huella_path' => $path])->save();
            }

            return $path;
        });
    }

    private function refreshExistingPersonalRelation(PersonalIngreso $ingreso): PersonalIngreso
    {
        if ($ingreso->proposito === PersonalIngresoPurpose::ACTUALIZACION) {
            return $ingreso;
        }

        $data = is_array($ingreso->datos_json) ? $ingreso->datos_json : [];
        $personal = $this->findPersonalByDocument($data['tipo_documento'] ?? $ingreso->tipo_documento, $data['numero_documento'] ?? $ingreso->numero_documento);

        if ($personal && (string) $ingreso->personal_existente_id !== (string) $personal->id) {
            $ingreso->forceFill(['personal_existente_id' => $personal->id])->save();
            $ingreso->setRelation('personalExistente', $personal);
        }

        return $ingreso;
    }

    private function findPersonalByDocument(string $type, string $number): ?Personal
    {
        $type = PersonalNormalizer::documentType($type, $number);
        $number = PersonalNormalizer::documentNumber($number);

        if ($number === '') {
            return null;
        }

        return Personal::query()
            ->where(function ($query) use ($type, $number): void {
                if (Schema::hasColumn('personal', 'numero_documento')) {
                    $query->where('numero_documento', $number);
                }

                $query->orWhere('dni', $type === 'DNI' ? PersonalNormalizer::dni($number) : $number);
            })
            ->with(['fichaColaborador', 'contratosLaborales', 'contratoDatos'])
            ->first();
    }

    private function assertDocumentIsValid(array $data): void
    {
        $type = (string) ($data['tipo_documento'] ?? 'DNI');
        $number = (string) ($data['numero_documento'] ?? '');

        if (!PersonalNormalizer::isValidDocument($type, $number)) {
            throw ValidationException::withMessages([
                'fields.numero_documento' => 'Revisa el documento. Para DNI deben ser 8 digitos.',
            ]);
        }
    }

    private function personalPayloadFromData(array $data, string $estado): array
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
            'es_supervisor' => PersonalNormalizer::isSupervisorOccupation($data['ocupacion'] ?? $data['puesto'] ?? null),
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

    private function safeFileType(string $tipo): string
    {
        $tipo = Str::slug($tipo, '_');

        return $tipo !== '' ? mb_substr($tipo, 0, 80) : 'documento';
    }

    private function normalizeSubmissionUuid(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^A-Za-z0-9._-]/', '', $value) ?: '';
        $value = mb_substr($value, 0, 64);

        return $value !== '' ? $value : null;
    }

    private function supportsSubmissionUuid(): bool
    {
        return Schema::hasTable('personal_ingresos') && Schema::hasColumn('personal_ingresos', 'submission_uuid');
    }

    private function appendIngresoObservation(PersonalIngreso $ingreso, string $message): void
    {
        $current = trim((string) $ingreso->observaciones_revision);
        $line = '[' . now()->format('d/m/Y H:i') . '] ' . $message;

        $ingreso->forceFill([
            'observaciones_revision' => $current !== '' ? $current . PHP_EOL . $line : $line,
        ])->save();
    }

    private function assertIngresoEditable(PersonalIngreso $ingreso): void
    {
        if ($ingreso->proposito === PersonalIngresoPurpose::ACTUALIZACION) {
            throw ValidationException::withMessages(['ingreso' => 'Abre la revisión de actualización para procesar esta propuesta.']);
        }

        if (!in_array($ingreso->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'ingreso' => 'Esta ficha ya fue procesada. Revisa el trabajador en Personal para hacer cambios posteriores.',
        ]);
    }
}
