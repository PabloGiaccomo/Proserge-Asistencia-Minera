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
use App\Modules\Personal\Support\PersonalFichaCatalog;
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

        try {
            $huellaPath = $this->replaceIngresoArchivo($ingreso, 'huella', $huella, false);
            $ingreso->forceFill(['huella_path' => $huellaPath])->save();
        } catch (Throwable $exception) {
            Log::error('No se pudo guardar la huella de ingreso publico.', [
                'personal_ingreso_id' => $ingreso->id,
                'submission_uuid' => $submissionUuid,
                'documento' => $data['numero_documento'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            $this->appendIngresoObservation(
                $ingreso,
                'La ficha llego al sistema, pero la foto de huella no se guardo correctamente. El trabajador debe reenviar la ficha sin cerrar la pagina.',
            );

            throw ValidationException::withMessages([
                'huella' => 'La ficha se registro, pero no se pudo guardar la foto de huella. Vuelve a enviarla sin cerrar la pagina.',
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
                    'documento' => $data['numero_documento'] ?? null,
                    'error' => $exception->getMessage(),
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

    public function updateIngreso(PersonalIngreso $ingreso, array $fields, array $familiares, ?string $firmaBase64, ?UploadedFile $huella, array $documentos, Usuario $user): PersonalIngreso
    {
        $this->assertIngresoEditable($ingreso);

        $data = $this->fichaService->normalizeFichaData($fields);
        $this->assertDocumentIsValid($data);
        $personalExistente = $this->findPersonalByDocument($data['tipo_documento'] ?? 'DNI', $data['numero_documento'] ?? '');

        return DB::transaction(function () use ($ingreso, $data, $familiares, $firmaBase64, $huella, $documentos, $user, $personalExistente): PersonalIngreso {
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
                $payload['huella_path'] = $this->replaceIngresoArchivo($ingreso, 'huella', $huella, false);
            }

            $ingreso->forceFill($payload)->save();

            foreach ($documentos as $tipo => $file) {
                if ($file instanceof UploadedFile) {
                    $this->replaceIngresoArchivo($ingreso, $this->safeFileType((string) $tipo), $file, true);
                }
            }

            return $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado', 'revisadoPor.personal']);
        });
    }

    public function accept(PersonalIngreso $ingreso, Usuario $user, array $contractPayload): Personal
    {
        return $this->persistIntoPersonal($ingreso, $user, false, $contractPayload);
    }

    public function markContractNotSigned(PersonalIngreso $ingreso, Usuario $user): Personal
    {
        return $this->persistIntoPersonal($ingreso, $user, true);
    }

    public function deleteErroneous(PersonalIngreso $ingreso): void
    {
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

    private function persistIntoPersonal(PersonalIngreso $ingreso, Usuario $user, bool $contractNotSigned, array $contractPayload = []): Personal
    {
        $this->assertIngresoEditable($ingreso);

        $ingreso = $ingreso->fresh(['archivos', 'personalExistente', 'personalCreado']);
        $data = $this->fichaService->normalizeFichaData(is_array($ingreso->datos_json) ? $ingreso->datos_json : []);
        $this->assertDocumentIsValid($data);
        $existing = $this->findPersonalByDocument($data['tipo_documento'] ?? $ingreso->tipo_documento, $data['numero_documento'] ?? $ingreso->numero_documento);
        $estado = $contractNotSigned
            ? self::ESTADO_NO_FIRMO_CONTRATO
            : ($existing ? (string) $existing->estado : PersonalFicha::ESTADO_APROBADO);

        if (!$contractNotSigned && in_array(strtoupper($estado), ['', PersonalFicha::ESTADO_PENDIENTE, PersonalFicha::ESTADO_ENVIADA, PersonalFicha::ESTADO_OBSERVADO, PersonalFicha::ESTADO_LINK_VENCIDO], true)) {
            $estado = PersonalFicha::ESTADO_APROBADO;
        }

        return DB::transaction(function () use ($ingreso, $data, $existing, $estado, $contractNotSigned, $contractPayload, $user): Personal {
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
            $this->syncFichaFromIngreso($personal, $ingreso, $fichaEstado, $user);

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

    private function syncFichaFromIngreso(Personal $personal, PersonalIngreso $ingreso, string $estado, Usuario $user): PersonalFicha
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

        $this->copyHuellaToFicha($ingreso, $ficha);
        $this->syncFamiliares($ficha, $ingreso);
        $this->copyArchivosToFicha($ingreso, $ficha);

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

    private function copyHuellaToFicha(PersonalIngreso $ingreso, PersonalFicha $ficha): void
    {
        if (!$ingreso->huella_path || !Storage::disk('local')->exists($ingreso->huella_path)) {
            return;
        }

        $extension = pathinfo($ingreso->huella_path, PATHINFO_EXTENSION) ?: 'jpg';
        $target = 'personal_fichas/' . $ficha->id . '/huella_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $extension;
        Storage::disk('local')->copy($ingreso->huella_path, $target);
        $ficha->forceFill(['huella_path' => $target])->save();
    }

    private function copyArchivosToFicha(PersonalIngreso $ingreso, PersonalFicha $ficha): void
    {
        $ingreso->loadMissing('archivos');

        foreach ($ingreso->archivos as $archivo) {
            if ($archivo->tipo === 'huella' || !$archivo->path || !Storage::disk('local')->exists($archivo->path)) {
                continue;
            }

            $extension = pathinfo($archivo->path, PATHINFO_EXTENSION) ?: 'bin';
            $target = 'personal_fichas/' . $ficha->id . '/documentos/' . $archivo->tipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . $extension;
            Storage::disk('local')->copy($archivo->path, $target);

            $this->replaceFichaArchivoFromStored($ficha, $archivo, $target);
        }
    }

    private function replaceFichaArchivoFromStored(PersonalFicha $ficha, PersonalIngresoArchivo $source, string $target): PersonalFichaArchivo
    {
        $existingFiles = PersonalFichaArchivo::query()
            ->where('personal_ficha_id', $ficha->id)
            ->where('tipo', $source->tipo)
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

    private function replaceIngresoArchivo(PersonalIngreso $ingreso, string $tipo, UploadedFile $file, bool $documento): string
    {
        $safeTipo = $this->safeFileType($tipo);
        $directory = $documento
            ? 'personal_ingresos/' . $ingreso->id . '/documentos'
            : 'personal_ingresos/' . $ingreso->id;
        $filename = $safeTipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($file->getClientOriginalExtension() ?: 'bin');
        $path = $file->storeAs($directory, $filename, 'local');

        $existingFiles = PersonalIngresoArchivo::query()
            ->where('personal_ingreso_id', $ingreso->id)
            ->where('tipo', $safeTipo)
            ->get();

        foreach ($existingFiles as $existing) {
            if ($existing->path && Storage::disk('local')->exists($existing->path)) {
                Storage::disk('local')->delete($existing->path);
            }
            $existing->delete();
        }

        PersonalIngresoArchivo::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ingreso_id' => $ingreso->id,
            'tipo' => $safeTipo,
            'nombre_original' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);

        return $path;
    }

    private function refreshExistingPersonalRelation(PersonalIngreso $ingreso): PersonalIngreso
    {
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
        if (!in_array($ingreso->estado, [PersonalIngreso::ESTADO_ACEPTADA, PersonalIngreso::ESTADO_CONTRATO_NO_FIRMADO], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'ingreso' => 'Esta ficha ya fue procesada. Revisa el trabajador en Personal para hacer cambios posteriores.',
        ]);
    }
}
