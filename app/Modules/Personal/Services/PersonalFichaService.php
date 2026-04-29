<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaArchivo;
use App\Models\PersonalFichaFamiliar;
use App\Models\PersonalFichaLink;
use App\Models\Usuario;
use App\Modules\Notificaciones\Services\NotificationService;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalFichaService
{
    public function __construct(private readonly PersonalService $personalService)
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

        return DB::transaction(function () use ($data, $documentType, $documentNumber, $verifyFields, $source, $user): array {
            $existing = $this->findPersonalByDocument($documentType, $documentNumber);
            if ($existing && in_array(strtoupper((string) $existing->estado), ['ACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA'], true)) {
                throw ValidationException::withMessages([
                    'fields.numero_documento' => 'Ya existe un trabajador activo o pendiente con este documento.',
                ]);
            }

            $payload = $this->personalPayloadFromFicha($data, PersonalFicha::ESTADO_PENDIENTE);
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
                'macro_original_nombre' => $source['original_name'] ?? null,
                'macro_original_path' => $source['path'] ?? null,
                'datos_detectados_json' => $source['detected'] ?? $data,
                'datos_json' => $data,
                'campos_verificacion_json' => $verifyFields,
                'advertencias_json' => $source['warnings'] ?? [],
                'created_by_usuario_id' => $user->id,
            ]);

            if (!empty($source['path'])) {
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

            [$token, $link] = $this->createSecureLink($ficha);

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
        if ($existing && in_array(strtoupper((string) $existing->estado), ['ACTIVO', 'PENDIENTE_COMPLETAR_FICHA', 'FICHA_ENVIADA'], true)) {
            return [
                'available' => false,
                'type' => $type,
                'number' => $number,
                'message' => 'Ya existe en Personal con estado ' . PersonalFichaCatalog::stateLabel((string) $existing->estado) . '.',
                'personal_id' => $existing->id,
            ];
        }

        return [
            'available' => true,
            'type' => $type,
            'number' => $number,
            'message' => 'Disponible para generar link.',
        ];
    }

    public function resolveToken(string $token): array
    {
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

        if ($ficha->submitted_at) {
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

    public function submitFromWorker(PersonalFichaLink $link, array $fields, array $familiares, string $firmaBase64, UploadedFile $huella, array $documentos = []): PersonalFicha
    {
        $ficha = $link->ficha()->with('personal')->firstOrFail();

        if ($ficha->submitted_at || now()->greaterThan($link->expires_at)) {
            throw ValidationException::withMessages([
                'ficha' => 'Este link ya no permite modificaciones.',
            ]);
        }

        $data = $this->normalizeFichaData([
            ...($ficha->datos_json ?? []),
            ...$fields,
            'tipo_documento' => $ficha->tipo_documento,
            'numero_documento' => $ficha->numero_documento,
        ]);

        $huellaPath = $huella->storeAs(
            'personal_fichas/' . $ficha->id,
            'huella_' . now()->format('Ymd_His') . '.' . strtolower($huella->getClientOriginalExtension() ?: 'jpg'),
            'local',
        );

        $documentPaths = [];
        foreach ($documentos as $tipo => $documento) {
            if (!$documento instanceof UploadedFile) {
                continue;
            }

            $safeTipo = Str::slug((string) $tipo, '_') ?: 'documento';
            $documentPaths[$safeTipo] = [
                'file' => $documento,
                'path' => $documento->storeAs(
                    'personal_fichas/' . $ficha->id . '/documentos',
                    $safeTipo . '_' . now()->format('Ymd_His') . '_' . Str::random(6) . '.' . strtolower($documento->getClientOriginalExtension() ?: 'bin'),
                    'local',
                ),
            ];
        }

        return DB::transaction(function () use ($ficha, $link, $data, $familiares, $firmaBase64, $huella, $huellaPath, $documentPaths): PersonalFicha {
            $ficha->forceFill([
                'estado' => PersonalFicha::ESTADO_ENVIADA,
                'datos_json' => $data,
                'firma_base64' => $firmaBase64,
                'huella_path' => $huellaPath,
                'submitted_at' => now(),
            ])->save();

            $ficha->personal?->forceFill(['estado' => PersonalFicha::ESTADO_ENVIADA])->save();

            PersonalFichaFamiliar::query()->where('personal_ficha_id', $ficha->id)->delete();
            foreach ($this->normalizeFamiliares($familiares) as $familiar) {
                PersonalFichaFamiliar::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_ficha_id' => $ficha->id,
                    ...$familiar,
                ]);
            }

            PersonalFichaArchivo::query()->create([
                'id' => (string) Str::uuid(),
                'personal_ficha_id' => $ficha->id,
                'tipo' => 'huella',
                'nombre_original' => $huella->getClientOriginalName(),
                'path' => $huellaPath,
                'mime' => $huella->getMimeType(),
                'size' => $huella->getSize(),
                'uploaded_by_public' => true,
            ]);

            foreach ($documentPaths as $tipo => $payload) {
                /** @var UploadedFile $documento */
                $documento = $payload['file'];
                PersonalFichaArchivo::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_ficha_id' => $ficha->id,
                    'tipo' => $tipo,
                    'nombre_original' => $documento->getClientOriginalName(),
                    'path' => $payload['path'],
                    'mime' => $documento->getMimeType(),
                    'size' => $documento->getSize(),
                    'uploaded_by_public' => true,
                ]);
            }

            $link->forceFill([
                'estado' => PersonalFichaLink::ESTADO_ENVIADO,
                'submitted_at' => now(),
                'read_until' => now()->addHours(24),
            ])->save();

            return $ficha->fresh(['personal', 'familiares', 'link', 'archivos']);
        });
    }

    public function notifyFichaSubmitted(PersonalFicha $ficha): void
    {
        app(NotificationService::class)->emit('personal_ficha_completada', [
            'module' => 'personal',
            'priority' => 'high',
            'category' => 'accion_requerida',
            'target_user_ids' => $this->rrhhUserIds(),
            'entity_type' => PersonalFicha::class,
            'entity_id' => $ficha->id,
            'message' => 'Se completo la ficha de ' . (($ficha->datos_json['puesto'] ?? $ficha->personal?->puesto) ?: 'trabajador') . ' - ' . ($ficha->personal?->nombre_completo ?: 'Sin nombre') . '. Pendiente de revision.',
            'payload' => [
                'personal_id' => $ficha->personal_id,
                'numero_documento' => $ficha->numero_documento,
            ],
            'dedupe_key' => 'personal_ficha_completada:' . $ficha->id,
        ]);
    }

    public function approve(PersonalFicha $ficha, Usuario $user, ?string $observaciones = null): PersonalFicha
    {
        return DB::transaction(function () use ($ficha, $user, $observaciones): PersonalFicha {
            $data = $this->normalizeFichaData($ficha->datos_json ?? []);
            $personal = $ficha->personal()->firstOrFail();

            $this->personalService->update($personal, $this->personalPayloadFromFicha($data, 'ACTIVO'));

            $ficha->forceFill([
                'estado' => PersonalFicha::ESTADO_APROBADO,
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

        try {
            $token = Crypt::decryptString($link->token_encrypted);
        } catch (\Throwable) {
            return null;
        }

        return route('ficha-colaborador.show', ['token' => $token]);
    }

    public function temporaryLinkRows(): array
    {
        $this->expireStaleLinks();

        return PersonalFicha::query()
            ->with(['personal', 'link'])
            ->whereIn('estado', [
                PersonalFicha::ESTADO_PENDIENTE,
                PersonalFicha::ESTADO_ENVIADA,
                PersonalFicha::ESTADO_OBSERVADO,
            ])
            ->latest('created_at')
            ->limit(300)
            ->get()
            ->map(function (PersonalFicha $ficha): array {
                $link = $ficha->link;

                return [
                    'ficha' => $ficha,
                    'personal' => $ficha->personal,
                    'link' => $link,
                    'url' => $this->publicUrlForLink($link),
                    'estado_label' => PersonalFichaCatalog::stateLabel($ficha->estado),
                ];
            })
            ->all();
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

        foreach (['fecha_nacimiento', 'fecha_ingreso'] as $dateField) {
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
            ->filter(fn ($item): bool => is_array($item) && PersonalNormalizer::text($item['nombres_apellidos'] ?? '') !== '')
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
                    'contacto_emergencia' => filter_var($item['contacto_emergencia'] ?? false, FILTER_VALIDATE_BOOL),
                ];
            })
            ->values()
            ->all();
    }

    private function createSecureLink(PersonalFicha $ficha): array
    {
        do {
            $token = Str::random(80);
            $hash = hash('sha256', $token);
        } while (PersonalFichaLink::query()->where('token_hash', $hash)->exists());

        $expiresAt = now()->copy()->addHours(24)->format('Y-m-d H:i:s');

        $link = PersonalFichaLink::query()->create([
            'id' => (string) Str::uuid(),
            'personal_ficha_id' => $ficha->id,
            'token_hash' => $hash,
            'token_encrypted' => Crypt::encryptString($token),
            'estado' => PersonalFichaLink::ESTADO_ACTIVO,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $link];
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

    private function rrhhUserIds(): array
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

                return $roles->contains('RRHH');
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
                    $personal->delete();
                    return;
                }

                $ficha->delete();
                return;
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

        return $name !== '' ? $name : 'Pendiente completar nombres';
    }
}
