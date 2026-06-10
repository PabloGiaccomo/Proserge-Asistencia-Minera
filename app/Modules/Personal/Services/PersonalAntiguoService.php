<?php

namespace App\Modules\Personal\Services;

use App\Models\Personal;
use App\Models\PersonalFicha;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PersonalAntiguoService
{
    public function __construct(
        private readonly PersonalService $personalService,
        private readonly PersonalFichaService $fichaService,
        private readonly PersonalContratoService $contratoService,
    ) {
    }

    public function create(array $payload, ?UploadedFile $signedContract, Usuario $user): Personal
    {
        $documentNumber = PersonalNormalizer::documentNumber($payload['numero_documento'] ?? '');
        $documentType = PersonalNormalizer::documentType($payload['tipo_documento'] ?? 'DNI', $documentNumber);

        if (!PersonalNormalizer::isValidDocument($documentType, $documentNumber)) {
            throw ValidationException::withMessages([
                'numero_documento' => 'El documento no tiene un formato valido para el tipo seleccionado.',
            ]);
        }

        $this->guardAgainstDuplicateDocument($documentType, $documentNumber);

        return DB::transaction(function () use ($payload, $signedContract, $user, $documentType, $documentNumber): Personal {
            $estadoLaboral = strtoupper((string) ($payload['estado_laboral'] ?? PersonalContratoDatoService::PENDING_STATE));
            $contrato = PersonalNormalizer::contract($payload['contrato'] ?? $payload['tipo_contrato'] ?? null);
            $fechaInicio = PersonalNormalizer::isoDate($payload['fecha_inicio'] ?? null);
            $fechaFin = PersonalNormalizer::isoDate($payload['fecha_fin'] ?? null);
            $nombres = PersonalNormalizer::text($payload['nombres'] ?? '');
            $apellidoPaterno = PersonalNormalizer::text($payload['apellido_paterno'] ?? '');
            $apellidoMaterno = PersonalNormalizer::text($payload['apellido_materno'] ?? '');
            $nombreCompleto = trim($apellidoPaterno . ' ' . $apellidoMaterno . ' ' . $nombres);
            $fichaData = $this->fichaService->normalizeFichaData([
                'nombres' => $nombres,
                'apellido_paterno' => $apellidoPaterno,
                'apellido_materno' => $apellidoMaterno,
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'telefono' => $payload['telefono'] ?? '',
                'correo' => $payload['correo'] ?? '',
                'puesto' => $payload['puesto'] ?? '',
                'contrato' => $contrato,
                'fecha_ingreso' => $fechaInicio,
                'fecha_fin_contrato' => $fechaFin,
                'estado_civil' => $payload['estado_civil'] ?? 'Soltero',
                'nacionalidad' => $payload['nacionalidad'] ?? 'Peruana',
                'domicilio_tipo' => 'Peru',
                'pais_nacimiento' => 'Peru',
            ]);

            $personal = $this->personalService->create([
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'dni' => $documentNumber,
                'nombre_completo' => $nombreCompleto,
                'puesto' => $payload['puesto'] ?? '',
                'ocupacion' => $payload['ocupacion'] ?? $payload['puesto'] ?? '',
                'contrato' => $contrato,
                'es_supervisor' => filter_var($payload['es_supervisor'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'telefono' => $payload['telefono'] ?? null,
                'correo' => $payload['correo'] ?? null,
                'fecha_ingreso' => $fechaInicio,
                'estado' => $estadoLaboral === 'CESADO' ? 'CESADO' : PersonalContratoDatoService::PENDING_STATE,
                'origen_registro' => 'ANTIGUO',
                'observacion_historica' => $payload['observacion_historica'] ?? null,
                'minas' => [],
            ]);

            $extraPersonalData = [];
            if (Schema::hasColumn('personal', 'registrado_como_antiguo_at')) {
                $extraPersonalData['registrado_como_antiguo_at'] = now();
            }
            if (Schema::hasColumn('personal', 'registrado_como_antiguo_by_usuario_id')) {
                $extraPersonalData['registrado_como_antiguo_by_usuario_id'] = $user->id;
            }
            if (!empty($extraPersonalData)) {
                $personal->forceFill($extraPersonalData)->save();
            }

            $ficha = PersonalFicha::query()->create([
                'id' => (string) Str::uuid(),
                'personal_id' => $personal->id,
                'estado' => PersonalFicha::ESTADO_APROBADO,
                'tipo_documento' => $documentType,
                'numero_documento' => $documentNumber,
                'macro_tipo_contrato' => PersonalNormalizer::contractLabel($contrato),
                'datos_detectados_json' => $fichaData,
                'datos_json' => $fichaData,
                'campos_verificacion_json' => [],
                'advertencias_json' => [
                    'Registro creado como personal antiguo/manual.',
                ],
                'created_by_usuario_id' => $user->id,
                'approved_at' => now(),
                'approved_by_usuario_id' => $user->id,
            ]);

            $personal = $personal->fresh(['fichaColaborador', 'minas']) ?: $personal;

            $this->contratoService->registerLegacyContract($personal, [
                ...$payload,
                'estado_laboral' => $estadoLaboral,
                'tipo_contrato' => $contrato,
                'contrato' => $contrato,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'personal_ficha_id' => $ficha->id,
            ], $signedContract, $user);

            return Personal::query()
                ->with(['fichaColaborador.link', 'contratosLaborales', 'contratoDatos', 'minas'])
                ->findOrFail($personal->id);
        });
    }

    public function regularizeExisting(Personal $personal, array $payload, ?UploadedFile $signedContract, Usuario $user): array
    {
        $origin = strtoupper(trim((string) ($payload['origen_registro'] ?? 'ANTIGUO')));
        if (!in_array($origin, ['ANTIGUO', 'HISTORICO', 'IMPORTADO'], true)) {
            throw ValidationException::withMessages([
                'origen_registro' => 'Selecciona un origen valido para la regularizacion.',
            ]);
        }

        $syncContract = filter_var($payload['sincronizar_contrato'] ?? false, FILTER_VALIDATE_BOOLEAN) || $signedContract !== null;
        $markPending = filter_var($payload['pendiente_regularizacion'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $warnings = [];

        return DB::transaction(function () use ($personal, $payload, $signedContract, $user, $origin, $syncContract, $markPending, &$warnings): array {
            $personal = Personal::query()
                ->with(['fichaColaborador', 'minas', 'contratoDatos', 'contratoLaboralActual'])
                ->lockForUpdate()
                ->findOrFail($personal->id);

            $personalData = [];
            if (Schema::hasColumn('personal', 'origen_registro')) {
                $personalData['origen_registro'] = $origin;
            }
            if (Schema::hasColumn('personal', 'observacion_historica')) {
                $incomingObservation = PersonalNormalizer::text($payload['observacion_historica'] ?? '');
                $personalData['observacion_historica'] = $incomingObservation !== ''
                    ? $incomingObservation
                    : ($personal->observacion_historica ?: null);
            }
            if (Schema::hasColumn('personal', 'registrado_como_antiguo_at') && !$personal->registrado_como_antiguo_at && in_array($origin, ['ANTIGUO', 'HISTORICO'], true)) {
                $personalData['registrado_como_antiguo_at'] = now();
            }
            if (Schema::hasColumn('personal', 'registrado_como_antiguo_by_usuario_id') && !$personal->registrado_como_antiguo_by_usuario_id && in_array($origin, ['ANTIGUO', 'HISTORICO'], true)) {
                $personalData['registrado_como_antiguo_by_usuario_id'] = $user->id;
            }

            if (!empty($personalData)) {
                $personal->forceFill($personalData)->save();
            }

            $contractResult = null;
            if ($syncContract) {
                $contractResult = $this->contratoService->syncLegacyContractForExisting(
                    $personal->fresh(['fichaColaborador', 'minas', 'contratoDatos']) ?: $personal,
                    [
                        ...$payload,
                        'origen_registro' => $origin,
                        'estado_laboral' => strtoupper((string) $personal->estado),
                        'tipo_contrato' => $payload['tipo_contrato'] ?? $payload['contrato'] ?? $personal->contrato,
                        'contrato' => $payload['contrato'] ?? $personal->contrato,
                        'puesto' => $payload['puesto'] ?? $personal->puesto,
                    ],
                    $signedContract,
                    $user,
                );

                if (!empty($contractResult['warning'])) {
                    $warnings[] = (string) $contractResult['warning'];
                }
            }

            $personal = Personal::query()
                ->with(['fichaColaborador', 'minas', 'contratoDatos', 'contratoLaboralActual'])
                ->findOrFail($personal->id);
            $hasSignedContract = $this->personalService->hasSignedContract($personal);

            if (strtoupper((string) $personal->estado) === 'ACTIVO' && !$hasSignedContract) {
                $warnings[] = 'El trabajador figura activo, pero no tiene contrato vigente firmado asociado correctamente.';
            }

            $pending = $markPending || !$hasSignedContract || collect($warnings)->isNotEmpty();
            if (Schema::hasColumn('personal', 'pendiente_regularizacion')) {
                $personal->forceFill(['pendiente_regularizacion' => $pending])->save();
            }

            return [
                'personal' => $personal->fresh(['fichaColaborador', 'contratosLaborales', 'contratoDatos', 'minas']),
                'contract' => $contractResult['contract'] ?? null,
                'contract_created' => (bool) ($contractResult['created'] ?? false),
                'warnings' => collect($warnings)->filter()->unique()->values()->all(),
            ];
        });
    }

    private function guardAgainstDuplicateDocument(string $documentType, string $documentNumber): void
    {
        $legacyDni = $documentType === 'DNI' ? PersonalNormalizer::dni($documentNumber) : $documentNumber;

        $existing = Personal::query()
            ->where(function ($query) use ($documentNumber, $legacyDni): void {
                $query->where('numero_documento', $documentNumber)
                    ->orWhere('dni', $legacyDni);
            })
            ->first();

        if (!$existing) {
            return;
        }

        $state = strtoupper((string) $existing->estado);
        $message = in_array($state, ['CESADO', 'INACTIVO'], true)
            ? 'Ya existe un trabajador cesado o historico con este documento. Revisa el registro existente antes de crear otro.'
            : 'Ya existe un trabajador activo o en proceso con este documento. No se puede crear un duplicado.';

        throw ValidationException::withMessages([
            'numero_documento' => $message,
        ]);
    }
}
