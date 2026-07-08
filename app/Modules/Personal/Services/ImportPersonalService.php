<?php

namespace App\Modules\Personal\Services;

use App\Models\Mina;
use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalFicha;
use App\Models\PersonalFichaFamiliar;
use App\Models\PersonalMina;
use App\Models\PersonalPuesto;
use App\Models\Usuario;
use App\Modules\Personal\Support\PersonalFichaCatalog;
use App\Modules\Personal\Support\PersonalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SimpleXMLElement;
use ZipArchive;

class ImportPersonalService
{
    private const MAX_PUESTO_LENGTH = 120;

    private const MAX_CHANGE_DETAILS = 200;

    private const IMPORT_BATCH_SIZE = 100;

    private const IMPORT_MAX_EXECUTION_SECONDS = 600;

    private array $puestoCatalogCache = [];

    private ?bool $canUsePuestoCatalog = null;

    private const DEFAULT_COLUMNS = [
        'dni' => 3,
        'nombre' => 5,
        'puesto' => 6,
        'fecha_ingreso' => 8,
        'ocupacion' => 1,
        'contrato' => 0,
        'correo' => null,
    ];

    private const CONTACT_FORMAT_REQUIRED_COLUMNS = [
        'dni',
        'nombre',
        'puesto',
        'telefono',
        'correo',
    ];

    private const PHONE_ALIASES = [
        'celularparticular',
        'celular',
        'cel',
        'telefono',
        'telefono1',
        'telefono2',
        'telefonoparticular',
        'telefonocelular',
        'contacto',
        'contactotelefonico',
        'fono',
        'movil',
        'movil1',
        'movil2',
    ];

    private const FIXED_HEADERS = [
        'N',
        'NO',
        'NRO',
        'CONTRATO',
        'OCUPACION',
        'CC',
        'DNI',
        'DNI CONTEO',
        'APELLIDOS Y NOMBRES',
        'NOMBRES',
        'APELLIDOS',
        'NOMBRE',
        'CARGO',
        'PUESTO',
        'CARGO PUESTO',
        'CARGO GENERAL',
        'FECHA INGRESO',
        'FECHA INGRESO INICIAL',
        'FIN DE CONTRATO',
        'CELULAR',
        'CELULAR PARTICULAR',
        'TELEFONO',
        'CORREO',
        'EMAIL',
    ];

    public function import(UploadedFile $file, ?Usuario $user = null): array
    {
        $this->extendImportExecutionWindow();

        $rows = $this->readRows($file);

        if (count($rows) < 2) {
            throw new \RuntimeException('El archivo no contiene filas de datos.');
        }

        [$headers, $rawDataRows] = $this->resolveHeadersAndDataRows($rows);

        $dataRows = array_values(array_filter($rawDataRows, function (array $row): bool {
            foreach ($row as $cell) {
                if (PersonalNormalizer::text($cell) !== '') {
                    return true;
                }
            }

            return false;
        }));

        $personnelDataColumns = $this->detectPersonnelDataColumns($headers);
        if ($personnelDataColumns !== null) {
            return $this->importPersonnelDataRows($dataRows, $personnelDataColumns, $user);
        }

        $contactColumns = $this->detectContactColumns($headers);
        if ($contactColumns !== null) {
            return $this->importContactRows($dataRows, $contactColumns);
        }

        if ($this->looksLikeContactWorkbook($headers)) {
            throw new \RuntimeException('El Excel de contactos no tiene todas las columnas requeridas. Debe incluir DNI, NOMBRES, CARGO, CELULAR y CORREO.');
        }

        $strictColumns = $this->detectColumns($headers, false);
        if (!$this->isSupportedMasterFormat($headers, $dataRows, $strictColumns)) {
            throw new \RuntimeException('No se reconocio el formato del archivo. Sube el Master General o el Excel de contactos con columnas DNI, NOMBRES, CARGO, CELULAR y CORREO.');
        }

        $columns = $this->detectColumns($headers);
        $detectedMines = $this->detectMineColumns($headers, $dataRows, $columns);
        $mineSync = $this->syncMines($detectedMines);
        $mineMap = $mineSync['map'];

        return (function () use ($dataRows, $columns, $mineMap, $mineSync): array {
            $hasTelefonoColumn = Schema::hasColumn('personal', 'telefono');
            $hasTelefono1Column = Schema::hasColumn('personal', 'telefono_1');
            $hasTelefono2Column = Schema::hasColumn('personal', 'telefono_2');

            $stats = [
                'tipoImportacion' => 'master',
                'formatoDetectado' => 'Master General',
                'nuevos' => 0,
                'reactivados' => 0,
                'inactivados' => 0,
                'puestosActualizados' => 0,
                'duplicados' => 0,
                'omitidos' => 0,
                'minasDetectadas' => array_values(array_map(fn (array $mine) => $mine['nombre'], $mineMap)),
                'minasActivasDetectadas' => count($mineMap),
                'minasCreadas' => (int) ($mineSync['stats']['creadas'] ?? 0),
                'minasReutilizadas' => (int) ($mineSync['stats']['reutilizadas'] ?? 0),
                'minasActualizadas' => (int) ($mineSync['stats']['actualizadas'] ?? 0),
                'relacionesMinaCreadas' => 0,
                'relacionesMinaActualizadas' => 0,
                'relacionesMinaCreadasOActualizadas' => 0,
                'relacionesMinaEliminadas' => 0,
                'actualizados' => 0,
                'camposActualizados' => 0,
                'telefonosDetectados' => 0,
                'trabajadoresCon1Telefono' => 0,
                'trabajadoresCon2Telefonos' => 0,
                'telefonosCasosInvalidosLimpios' => 0,
                'telefonosCasosOmitidos' => 0,
                'telefonosConMasDeDos' => 0,
                'correosInvalidos' => 0,
                'correosInvalidosDetalle' => [],
                'cambiosDetectadosTotal' => 0,
                'cambiosDetectados' => [],
                'omitidosDetalle' => [],
                'noActualizadosDetalle' => [],
                'nuevosDetalle' => [],
                'reactivadosDetalle' => [],
                'inactivadosDetalle' => [],
                'activacionesBloqueadas' => 0,
                'activacionesBloqueadasDetalle' => [],
            ];

            $existingSelect = [
                'id',
                'dni',
                'nombre_completo',
                'puesto',
                'ocupacion',
                'contrato',
                'es_supervisor',
                'fecha_ingreso',
                'estado',
            ];

            if ($hasTelefonoColumn) {
                $existingSelect[] = 'telefono';
            }

            if ($hasTelefono1Column) {
                $existingSelect[] = 'telefono_1';
            }

            if ($hasTelefono2Column) {
                $existingSelect[] = 'telefono_2';
            }

            if (Schema::hasColumn('personal', 'correo')) {
                $existingSelect[] = 'correo';
            }

            if (Schema::hasColumn('personal', 'tipo_documento')) {
                $existingSelect[] = 'tipo_documento';
            }

            if (Schema::hasColumn('personal', 'numero_documento')) {
                $existingSelect[] = 'numero_documento';
            }

            $existing = Personal::query()
                ->with(['fichaColaborador', 'contratoDatos', 'contratoLaboralActual'])
                ->get($existingSelect)
                ->keyBy('dni');

            $processedDni = [];
            $activeDbDni = Personal::query()->where('estado', 'ACTIVO')->pluck('dni')->all();

            foreach (array_chunk($dataRows, self::IMPORT_BATCH_SIZE) as $chunkRows) {
                DB::transaction(function () use ($chunkRows, $columns, $mineMap, $hasTelefonoColumn, $hasTelefono1Column, $hasTelefono2Column, $existing, &$processedDni, &$stats): void {
                    foreach ($chunkRows as $row) {
                $rawDni = PersonalNormalizer::text($row[$columns['dni']] ?? null);
                $dni = PersonalNormalizer::dni($rawDni);
                if (!PersonalNormalizer::isValidDni($dni)) {
                    $stats['omitidos']++;
                    $this->registerNotUpdatedDetail($stats, 'omitidosDetalle', $dni ?: $rawDni, PersonalNormalizer::text($row[$columns['nombre']] ?? null), 'DNI invalido o vacio');
                    continue;
                }

                if (isset($processedDni[$dni])) {
                    $stats['duplicados']++;
                    $this->registerNotUpdatedDetail($stats, 'noActualizadosDetalle', $dni, PersonalNormalizer::text($row[$columns['nombre']] ?? null), 'DNI duplicado en el archivo');
                    continue;
                }
                $processedDni[$dni] = true;

                $nombreImportado = mb_strtoupper(PersonalNormalizer::text($row[$columns['nombre']] ?? ''), 'UTF-8');
                $puestoImportado = PersonalNormalizer::text($row[$columns['puesto']] ?? '');
                $ocupacionImportada = PersonalNormalizer::text($row[$columns['ocupacion']] ?? '');
                $contratoImportadoRaw = PersonalNormalizer::text($row[$columns['contrato']] ?? null);
                $correoRaw = Schema::hasColumn('personal', 'correo') && isset($columns['correo']) && $columns['correo'] !== null
                    ? PersonalNormalizer::text($row[$columns['correo']] ?? '')
                    : '';
                $correoImportado = $correoRaw !== '' ? $this->normalizeEmail($correoRaw) : null;

                $nombre = $nombreImportado !== '' ? $nombreImportado : 'Sin nombre';
                $puesto = $puestoImportado !== '' ? $puestoImportado : 'Sin puesto';
                $puesto = mb_substr($puesto, 0, self::MAX_PUESTO_LENGTH);
                $ocupacion = $ocupacionImportada;
                $contrato = $contratoImportadoRaw !== '' ? PersonalNormalizer::contract($contratoImportadoRaw) : null;
                $fechaIngreso = PersonalNormalizer::isoDate($row[$columns['fecha_ingreso']] ?? null);
                $isSupervisor = PersonalNormalizer::isSupervisorOccupation($ocupacionImportada);
                $phoneRaw = $this->extractPhoneRaw($row, $columns);
$phoneData = PersonalNormalizer::normalizePhonePayload($phoneRaw);

                if ($phoneData['valid_count'] === 1) {
                    $stats['trabajadoresCon1Telefono']++;
                }

                if ($phoneData['valid_count'] === 2) {
                    $stats['trabajadoresCon2Telefonos']++;
                }

                if ($phoneData['valid_count'] > 0) {
                    $stats['telefonosDetectados'] += (int) $phoneData['valid_count'];
                }

                if ($phoneData['had_invalid_cleanup'] || $phoneData['had_duplicates']) {
                    $stats['telefonosCasosInvalidosLimpios']++;
                }

                if ($phoneData['raw_has_content'] && $phoneData['valid_count'] === 0) {
                    $stats['telefonosCasosOmitidos']++;
                }

                if ($phoneData['had_more_than_two']) {
                    $stats['telefonosConMasDeDos']++;

                    Log::warning('Import Personal: se detectaron mas de dos telefonos, se conservaron solo dos.', [
                        'dni' => $dni,
                        'raw' => $phoneData['raw'] ?? null,
                        'telefonos_detectados' => $phoneData['all_valid_numbers'] ?? [],
                    ]);
                }

                if ($correoRaw !== '' && $correoImportado === null) {
                    $stats['correosInvalidos']++;
                    $this->registerInvalidEmailDetail($stats, $dni, $nombreImportado, $correoRaw);
                    $this->registerNotUpdatedDetail($stats, 'noActualizadosDetalle', $dni, $nombreImportado, 'Correo invalido: ' . $correoRaw);
                }

                $personal = $existing->get($dni);

                if ($personal) {
                    $updates = [];
                    $workerChanges = [];
                    $mergedFichaData = [];

                    $estadoAntesImport = strtoupper((string) $personal->estado);
                    $estadoPorIntencionActiva = app(PersonalService::class)->resolveActiveIntentState($personal);
                    if ($estadoPorIntencionActiva !== $estadoAntesImport) {
                        $updates['estado'] = $estadoPorIntencionActiva;
                        $this->registerFieldChange($workerChanges, $stats, 'estado', 'Estado', $personal->estado, $estadoPorIntencionActiva);

                        if ($estadoPorIntencionActiva === 'ACTIVO') {
                            $stats['reactivados']++;

                            if (count($stats['reactivadosDetalle']) < self::MAX_CHANGE_DETAILS) {
                                $stats['reactivadosDetalle'][] = [
                                    'dni' => $dni,
                                    'nombre' => $nombre,
                                    'antes' => $estadoAntesImport,
                                    'despues' => 'ACTIVO',
                                ];
                            }
                        } elseif ($estadoAntesImport === 'ACTIVO' || $estadoPorIntencionActiva !== 'CESADO') {
                            $this->registerBlockedActivation($stats, $dni, $nombre, $estadoAntesImport, $estadoPorIntencionActiva);
                        }
                    } elseif ($estadoAntesImport !== 'ACTIVO' && $estadoPorIntencionActiva !== 'CESADO') {
                        $this->registerBlockedActivation($stats, $dni, $nombre, $estadoAntesImport, $estadoPorIntencionActiva);
                    }

                    if (Schema::hasColumn('personal', 'tipo_documento') && (string) ($personal->tipo_documento ?? '') !== 'DNI') {
                        $updates['tipo_documento'] = 'DNI';
                    }

                    if (Schema::hasColumn('personal', 'numero_documento') && (string) ($personal->numero_documento ?? '') !== $dni) {
                        $updates['numero_documento'] = $dni;
                    }

                    if ($nombreImportado !== '' && $personal->nombre_completo !== $nombreImportado) {
                        $updates['nombre_completo'] = $nombreImportado;
                        $mergedFichaData = array_merge($mergedFichaData, $this->nameFieldsFromFullName($nombreImportado));
                        $this->registerFieldChange($workerChanges, $stats, 'nombre_completo', 'Nombre', $personal->nombre_completo, $nombreImportado);
                    }

                    if ($puestoImportado !== '' && $personal->puesto !== $puesto) {
                        $puestoCatalogo = $this->resolvePuestoCatalogo($puesto);
                        $updates['puesto'] = $puestoCatalogo?->nombre ?: $puesto;
                        if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
                            $updates['puesto_id'] = $puestoCatalogo->id;
                        }
                        $mergedFichaData['puesto'] = $puesto;
                        $stats['puestosActualizados']++;
                        $this->registerFieldChange($workerChanges, $stats, 'puesto', 'Cargo/Puesto', $personal->puesto, $puesto);
                    }

                    if ($ocupacionImportada !== '' && (string) $personal->ocupacion !== $ocupacion) {
                        $updates['ocupacion'] = $ocupacion ?: null;
                        $mergedFichaData['ocupacion'] = $ocupacion;
                        $this->registerFieldChange($workerChanges, $stats, 'ocupacion', 'Ocupación', $personal->ocupacion, $ocupacion ?: null);
                    }

                    if ($contrato !== null && (string) $personal->contrato !== $contrato) {
                        $updates['contrato'] = $contrato;
                        $mergedFichaData['contrato'] = $contrato;
                        $this->registerFieldChange(
                            $workerChanges,
                            $stats,
                            'contrato',
                            'Contrato',
                            PersonalNormalizer::contractLabel($personal->contrato),
                            PersonalNormalizer::contractLabel($contrato)
                        );
                    }

                    if ($ocupacionImportada !== '' && (bool) $personal->es_supervisor !== $isSupervisor) {
                        $updates['es_supervisor'] = $isSupervisor;
                        $this->registerFieldChange($workerChanges, $stats, 'es_supervisor', 'Supervisor', $personal->es_supervisor ? 'Sí' : 'No', $isSupervisor ? 'Sí' : 'No');
                    }

                    if ($fechaIngreso !== null && optional($personal->fecha_ingreso)->toDateString() !== $fechaIngreso) {
                        $updates['fecha_ingreso'] = $fechaIngreso;
                        $mergedFichaData['fecha_ingreso'] = $fechaIngreso;
                        $this->registerFieldChange($workerChanges, $stats, 'fecha_ingreso', 'Fecha ingreso', optional($personal->fecha_ingreso)->toDateString(), $fechaIngreso);
                    }

                    $oldCombinedPhone = PersonalNormalizer::combinePhones(
                        $personal->telefono_1 ?? null,
                        $personal->telefono_2 ?? null
) ?? ($personal->telefono ?? null);
                    $newCombinedPhone = PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']);

                    if ($phoneData['valid_count'] > 0 && (string) ($oldCombinedPhone ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $this->registerFieldChange($workerChanges, $stats, 'telefono', 'Teléfono', $oldCombinedPhone, $newCombinedPhone);
                    }

                    if ($phoneData['valid_count'] > 0 && (string) ($personal->telefono ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $updates['telefono'] = $newCombinedPhone;
                        $mergedFichaData['telefono'] = $newCombinedPhone;
                    }

                    if ($phoneData['valid_count'] > 0 && $hasTelefono1Column && (string) ($personal->telefono_1 ?? '') !== (string) ($phoneData['telefono_1'] ?? '')) {
                        $updates['telefono_1'] = $phoneData['telefono_1'];
                    }

                    if ($phoneData['valid_count'] > 0 && $hasTelefono2Column && (string) ($personal->telefono_2 ?? '') !== (string) ($phoneData['telefono_2'] ?? '')) {
                        $updates['telefono_2'] = $phoneData['telefono_2'];
                    }

                    if ($correoImportado !== null && (string) ($personal->correo ?? '') !== (string) $correoImportado) {
                        $updates['correo'] = $correoImportado;
                        $mergedFichaData['correo'] = $correoImportado;
                        $this->registerFieldChange($workerChanges, $stats, 'correo', 'Correo', $personal->correo, $correoImportado);
                    }

                    if (count($updates) > 0) {
                        Personal::query()->where('id', $personal->id)->update($updates);
                        $personal->fill($updates);
                        $this->syncFichaWithImportData($personal, $mergedFichaData);
                    }

                    $this->syncMineStatuses($personal, $row, $mineMap, $stats, $workerChanges);

                    if (count($workerChanges) > 0) {
                        $stats['actualizados']++;
                        $stats['cambiosDetectadosTotal']++;

                        if (count($stats['cambiosDetectados']) < self::MAX_CHANGE_DETAILS) {
                            $stats['cambiosDetectados'][] = [
                                'dni' => $dni,
                                'nombre' => $nombreImportado !== '' ? $nombreImportado : $personal->nombre_completo,
                                'cambios' => array_values($workerChanges),
                            ];
                        }
                    }
                } else {
                    $puestoCatalogo = $this->resolvePuestoCatalogo($puesto);

                    $newData = [
                        'id' => (string) Str::uuid(),
                        'dni' => $dni,
                        'nombre_completo' => $nombre,
                        'puesto' => $puestoCatalogo?->nombre ?: $puesto,
                        'ocupacion' => $ocupacion ?: null,
                        'contrato' => $contrato,
                        'es_supervisor' => $isSupervisor,
                        'qr_code' => 'QR-' . $dni . '-' . Str::upper(Str::random(8)),
                        'fecha_ingreso' => $fechaIngreso,
                        'estado' => app(PersonalService::class)->resolveActiveIntentState(null),
                    ];

                    if (Schema::hasColumn('personal', 'tipo_documento')) {
                        $newData['tipo_documento'] = 'DNI';
                    }

                    if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
                        $newData['puesto_id'] = $puestoCatalogo->id;
                    }

                    if (Schema::hasColumn('personal', 'numero_documento')) {
                        $newData['numero_documento'] = $dni;
                    }

                    if ($hasTelefonoColumn) {
                        $newData['telefono'] = PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']);
                    }

                    if ($hasTelefono1Column) {
                        $newData['telefono_1'] = $phoneData['telefono_1'];
                    }

                    if ($hasTelefono2Column) {
                        $newData['telefono_2'] = $phoneData['telefono_2'];
                    }

                    if (Schema::hasColumn('personal', 'correo')) {
                        $newData['correo'] = $correoImportado;
                    }

                    $personal = Personal::query()->create($newData);
                    $this->registerBlockedActivation($stats, $dni, $nombre, 'NUEVO', (string) $newData['estado']);

                    $existing->put($dni, $personal);
                    $this->syncFichaWithImportData($personal, $this->buildFichaImportData(
                        $nombreImportado,
                        $puesto,
                        $ocupacion,
                        $contrato,
                        $fechaIngreso,
                        PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']),
                        $correoImportado
                    ));
                    $stats['nuevos']++;

if (count($stats['nuevosDetalle']) < self::MAX_CHANGE_DETAILS) {
                        $stats['nuevosDetalle'][] = [
                            'dni' => $dni,
                            'nombre' => $nombre,
                            'puesto' => $puesto,
                            'ocupacion' => $ocupacion ?: '-',
                            'contrato' => PersonalNormalizer::contractLabel($contrato),
                        ];
                    }

                    $workerChanges = [];
                    $this->syncMineStatuses($personal, $row, $mineMap, $stats, $workerChanges);
                }
                    }
                });
            }

            foreach (array_chunk($activeDbDni, self::IMPORT_BATCH_SIZE) as $dniChunk) {
                DB::transaction(function () use ($dniChunk, $existing, &$processedDni, &$stats): void {
                    foreach ($dniChunk as $dbDni) {
                if (!isset($processedDni[$dbDni])) {
                    Personal::query()->where('dni', $dbDni)->update(['estado' => 'INACTIVO']);
                    $stats['inactivados']++;

                    $inactivated = $existing->get($dbDni);
                    if ($inactivated && count($stats['inactivadosDetalle']) < self::MAX_CHANGE_DETAILS) {
                        $stats['inactivadosDetalle'][] = [
                            'dni' => $dbDni,
                            'nombre' => $inactivated->nombre_completo,
                            'antes' => 'ACTIVO',
                            'despues' => 'INACTIVO',
                        ];
                    }
                }
                    }
                });
            }

            $stats['bloquesProcesados'] = count(array_chunk($dataRows, self::IMPORT_BATCH_SIZE));
            $stats['tamanoBloque'] = self::IMPORT_BATCH_SIZE;

            return $stats;
        })();
    }

    private function importContactRows(array $dataRows, array $columns): array
    {
        $hasTelefonoColumn = Schema::hasColumn('personal', 'telefono');
        $hasTelefono1Column = Schema::hasColumn('personal', 'telefono_1');
        $hasTelefono2Column = Schema::hasColumn('personal', 'telefono_2');
        $hasCorreoColumn = Schema::hasColumn('personal', 'correo');

        $stats = $this->emptyStats();
        $stats['tipoImportacion'] = 'contactos';
        $stats['formatoDetectado'] = 'Excel de correos y celulares';
        $stats['filasLeidas'] = count($dataRows);
        $stats['noEncontrados'] = 0;
        $stats['correosInvalidos'] = 0;
        $stats['sinCambios'] = 0;

        $existingSelect = [
            'id',
            'dni',
            'nombre_completo',
            'puesto',
            'estado',
        ];

        if ($hasTelefonoColumn) {
            $existingSelect[] = 'telefono';
        }

        if ($hasTelefono1Column) {
            $existingSelect[] = 'telefono_1';
        }

        if ($hasTelefono2Column) {
            $existingSelect[] = 'telefono_2';
        }

        if ($hasCorreoColumn) {
            $existingSelect[] = 'correo';
        }

        $existing = Personal::query()
            ->with('fichaColaborador')
            ->get($existingSelect)
            ->keyBy('dni');

        $processedDni = [];

        foreach (array_chunk($dataRows, self::IMPORT_BATCH_SIZE) as $chunkRows) {
            DB::transaction(function () use ($chunkRows, $columns, $hasTelefonoColumn, $hasTelefono1Column, $hasTelefono2Column, $hasCorreoColumn, $existing, &$processedDni, &$stats): void {
                foreach ($chunkRows as $row) {
                    if (!$this->rowHasContent($row)) {
                        continue;
                    }

                    $rawDni = PersonalNormalizer::text($row[$columns['dni']] ?? null);
                    $rowName = PersonalNormalizer::text($row[$columns['nombre']] ?? null);
                    $dni = PersonalNormalizer::dni($rawDni);
                    if (!PersonalNormalizer::isValidDni($dni)) {
                        $stats['omitidos']++;
                        $this->registerNotUpdatedDetail($stats, 'omitidosDetalle', $dni ?: $rawDni, $rowName, 'DNI invalido o vacio');
                        continue;
                    }

                    if (isset($processedDni[$dni])) {
                        $stats['duplicados']++;
                        $this->registerNotUpdatedDetail($stats, 'noActualizadosDetalle', $dni, $rowName, 'DNI duplicado en el archivo');
                        continue;
                    }
                    $processedDni[$dni] = true;

                    $personal = $existing->get($dni);
                    if (!$personal) {
                        $stats['omitidos']++;
                        $stats['noEncontrados']++;
                        $this->registerNotUpdatedDetail($stats, 'omitidosDetalle', $dni, $rowName, 'DNI no encontrado en personal');
                        continue;
                    }

                    $puestoImportado = PersonalNormalizer::text($row[$columns['puesto']] ?? '');
                    $puesto = $puestoImportado !== ''
                        ? mb_substr($puestoImportado, 0, self::MAX_PUESTO_LENGTH)
                        : '';
                    $correoRaw = $hasCorreoColumn ? PersonalNormalizer::text($row[$columns['correo']] ?? '') : '';
                    $correoImportado = $this->normalizeEmail($correoRaw);
                    $phoneData = PersonalNormalizer::normalizePhonePayload($row[$columns['telefono']] ?? null);

                    $this->addPhoneStats($stats, $dni, $phoneData);

                    $updates = [];
                    $workerChanges = [];
                    $mergedFichaData = [];
                    $notUpdatedReasons = [];
                    $hasNotUpdatedDetail = false;

                    if ($puesto !== '' && (string) $personal->puesto !== $puesto) {
                        $puestoCatalogo = $this->resolvePuestoCatalogo($puesto);
                        $updates['puesto'] = $puestoCatalogo?->nombre ?: $puesto;
                        if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
                            $updates['puesto_id'] = $puestoCatalogo->id;
                        }
                        $mergedFichaData['puesto'] = $puesto;
                        $stats['puestosActualizados']++;
                        $this->registerFieldChange($workerChanges, $stats, 'puesto', 'Cargo/Puesto', $personal->puesto, $puesto);
                    }

                    $oldCombinedPhone = PersonalNormalizer::combinePhones(
                        $personal->telefono_1 ?? null,
                        $personal->telefono_2 ?? null
                    ) ?? ($personal->telefono ?? null);
                    $newCombinedPhone = PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']);

                    if ($phoneData['valid_count'] > 0 && (string) ($oldCombinedPhone ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $this->registerFieldChange($workerChanges, $stats, 'telefono', 'Telefono', $oldCombinedPhone, $newCombinedPhone);
                    }

                    if ($phoneData['valid_count'] > 0 && $hasTelefonoColumn && (string) ($personal->telefono ?? '') !== (string) ($newCombinedPhone ?? '')) {
                        $updates['telefono'] = $newCombinedPhone;
                        $mergedFichaData['telefono'] = $newCombinedPhone;
                    }

                    if ($phoneData['valid_count'] > 0 && $hasTelefono1Column && (string) ($personal->telefono_1 ?? '') !== (string) ($phoneData['telefono_1'] ?? '')) {
                        $updates['telefono_1'] = $phoneData['telefono_1'];
                    }

                    if ($phoneData['valid_count'] > 0 && $hasTelefono2Column && (string) ($personal->telefono_2 ?? '') !== (string) ($phoneData['telefono_2'] ?? '')) {
                        $updates['telefono_2'] = $phoneData['telefono_2'];
                    }

                    if ($correoRaw !== '' && $correoImportado === null) {
                        $stats['correosInvalidos']++;
                        $notUpdatedReasons[] = 'Correo invalido: ' . $correoRaw;
                        $this->registerInvalidEmailDetail($stats, $dni, $personal->nombre_completo ?: $rowName, $correoRaw);
                        $this->registerNotUpdatedDetail(
                            $stats,
                            'noActualizadosDetalle',
                            $dni,
                            $personal->nombre_completo ?: $rowName,
                            'Correo invalido: ' . $correoRaw
                        );
                        $hasNotUpdatedDetail = true;
                    } elseif ($correoImportado !== null && (string) ($personal->correo ?? '') !== (string) $correoImportado) {
                        $updates['correo'] = $correoImportado;
                        $mergedFichaData['correo'] = $correoImportado;
                        $this->registerFieldChange($workerChanges, $stats, 'correo', 'Correo', $personal->correo, $correoImportado);
                    }

                    if (count($updates) > 0) {
                        Personal::query()->where('id', $personal->id)->update($updates);
                        $personal->fill($updates);
                        $this->syncFichaWithImportData($personal, $mergedFichaData);
                    }

                    if (count($workerChanges) > 0) {
                        $stats['actualizados']++;
                        $stats['cambiosDetectadosTotal']++;

                        if (count($stats['cambiosDetectados']) < self::MAX_CHANGE_DETAILS) {
                            $stats['cambiosDetectados'][] = [
                                'dni' => $dni,
                                'nombre' => $personal->nombre_completo ?: PersonalNormalizer::text($row[$columns['nombre']] ?? ''),
                                'cambios' => array_values($workerChanges),
                            ];
                        }
                    } else {
                        $stats['sinCambios']++;
                        if (!$hasNotUpdatedDetail) {
                            $this->registerNotUpdatedDetail(
                                $stats,
                                'noActualizadosDetalle',
                                $dni,
                                $personal->nombre_completo ?: $rowName,
                                $notUpdatedReasons !== [] ? implode('; ', $notUpdatedReasons) : 'Sin cambios para aplicar'
                            );
                        }
                    }
                }
            });
        }

        $stats['bloquesProcesados'] = count(array_chunk($dataRows, self::IMPORT_BATCH_SIZE));
        $stats['tamanoBloque'] = self::IMPORT_BATCH_SIZE;

        return $stats;
    }

    private function personnelRowData(array $row, array $columns): array
    {
        $text = fn (string $key): string => $this->nullableExcelText($row[$columns[$key] ?? -1] ?? null);
        $date = fn (string $key): ?string => PersonalNormalizer::isoDate($this->nullableExcelText($row[$columns[$key] ?? -1] ?? null));

        $tipoDocumento = PersonalNormalizer::documentType($text('tipo_documento'), $text('numero_documento'));
        $numeroDocumento = $tipoDocumento === 'DNI'
            ? PersonalNormalizer::dni($text('numero_documento'))
            : PersonalNormalizer::documentNumber($text('numero_documento'));

        $nombres = mb_strtoupper($text('nombres'), 'UTF-8');
        $apellidoPaterno = mb_strtoupper($text('apellido_paterno'), 'UTF-8');
        $apellidoMaterno = mb_strtoupper($text('apellido_materno'), 'UTF-8');
        $nombreCompleto = trim(implode(' ', array_filter([$apellidoPaterno, $apellidoMaterno, $nombres])));

        $phoneData = PersonalNormalizer::normalizePhonePayload($this->numericExcelText($row[$columns['telefono'] ?? -1] ?? null));
        $emergencyPhone = PersonalNormalizer::normalizePhonePayload($this->numericExcelText($row[$columns['emergencia_telefono'] ?? -1] ?? null));

        $banco = $text('banco');
        $bankData = $this->normalizeBank($banco);
        $pensionData = $this->normalizePension($text('sistema_pensionario'), $text('tipo_afp'));

        $fechaIngreso = $date('fecha_ingreso');
        $fechaInicioContrato = $date('fecha_inicio_contrato');
        $fechaFinContrato = $date('fecha_fin_contrato');

        $contrato = PersonalNormalizer::contract($text('contrato'));

        return [
            'nombres' => $nombres,
            'apellido_paterno' => $apellidoPaterno,
            'apellido_materno' => $apellidoMaterno,
            'nombre_completo' => $nombreCompleto !== '' ? $nombreCompleto : 'SIN NOMBRE',
            'sexo' => $this->normalizeSex($text('sexo')),
            'estado_civil' => $this->normalizeCivilState($text('estado_civil')),
            'nacionalidad' => $this->normalizeNationality($text('nacionalidad')),
            'nacionalidad_otra' => $this->normalizeNationality($text('nacionalidad')) === 'Otra' ? $text('nacionalidad') : '',
            'grupo_sanguineo' => $text('grupo_sanguineo'),
            'brevete' => $text('brevete'),
            'tipo_documento' => $tipoDocumento,
            'numero_documento' => $numeroDocumento,
            'fecha_nacimiento' => $date('fecha_nacimiento'),
            'pais_nacimiento' => $this->normalizeCountry($text('pais_nacimiento')),
            'pais_nacimiento_otro' => $this->normalizeCountry($text('pais_nacimiento')) === 'Otro' ? $text('pais_nacimiento') : '',
            'departamento_nacimiento' => $text('departamento_nacimiento'),
            'provincia_nacimiento' => $text('provincia_nacimiento'),
            'distrito_nacimiento' => $text('distrito_nacimiento'),
            'telefono' => PersonalNormalizer::combinePhones($phoneData['telefono_1'], $phoneData['telefono_2']),
            'telefono_1' => $phoneData['telefono_1'],
            'telefono_2' => $phoneData['telefono_2'],
            'telefono_data' => $phoneData,
            'correo' => $this->normalizeEmail($text('correo')),
            'correo_raw' => $text('correo'),
            'domicilio_tipo' => 'Peru',
            'domicilio_direccion' => $text('domicilio_direccion'),
            'domicilio_departamento' => $text('domicilio_departamento'),
            'domicilio_provincia' => $text('domicilio_provincia'),
            'domicilio_distrito' => $text('domicilio_distrito'),
            'puesto' => mb_substr($text('puesto'), 0, self::MAX_PUESTO_LENGTH),
            'contrato' => $contrato,
            'banco' => $bankData['banco'],
            'banco_otro' => $bankData['banco_otro'],
            'numero_cuenta' => $this->nullableNumericExcelText($row[$columns['numero_cuenta'] ?? -1] ?? null),
            'cci' => $this->nullableNumericExcelText($row[$columns['cci'] ?? -1] ?? null),
            'grado_instruccion' => $text('grado_instruccion'),
            'profesion_oficio' => $text('profesion_oficio'),
            'especialidad' => $text('titulado'),
            'institucion' => $text('institucion'),
            'anio_egreso' => $this->yearText($row[$columns['anio_egreso'] ?? -1] ?? null),
            'anio_experiencia' => $text('anio_experiencia'),
            'sistema_pensionario' => $pensionData['sistema_pensionario'],
            'tipo_afp' => $pensionData['tipo_afp'],
            'remuneracion' => $this->nullableNumericExcelText($row[$columns['remuneracion'] ?? -1] ?? null),
            'talla_zapato' => $this->nullableNumericExcelText($row[$columns['talla_zapato'] ?? -1] ?? null),
            'talla_polo' => $text('talla_polo'),
            'talla_pantalon' => $this->nullableNumericExcelText($row[$columns['talla_pantalon'] ?? -1] ?? null),
            'talla_respirador' => $text('talla_respirador'),
            'emergencia_nombre' => mb_strtoupper($text('emergencia_nombre'), 'UTF-8'),
            'emergencia_parentesco' => $text('emergencia_parentesco'),
            'emergencia_telefono' => PersonalNormalizer::combinePhones($emergencyPhone['telefono_1'], $emergencyPhone['telefono_2']),
            'fecha_ingreso' => $fechaIngreso,
            'fecha_inicio_contrato' => $fechaInicioContrato,
            'periodo_prueba_inicio' => $fechaInicioContrato,
            'periodo_prueba_fin' => $date('periodo_prueba_fin'),
            'fecha_fin_contrato' => $fechaFinContrato,
        ];
    }

    private function createPersonalFromData(
        array $data,
        bool $hasTelefonoColumn,
        bool $hasTelefono1Column,
        bool $hasTelefono2Column,
        bool $hasCorreoColumn,
        bool $hasTipoDocumentoColumn,
        bool $hasNumeroDocumentoColumn
    ): Personal {
        $puestoCatalogo = $this->resolveOrCreatePuestoCatalogo($data['puesto']);
        $documentForDniColumn = $data['tipo_documento'] === 'DNI'
            ? PersonalNormalizer::dni($data['numero_documento'])
            : PersonalNormalizer::documentNumber($data['numero_documento']);

        $payload = [
            'id' => (string) Str::uuid(),
            'dni' => $documentForDniColumn,
            'nombre_completo' => $data['nombre_completo'],
            'puesto' => $puestoCatalogo?->nombre ?: ($data['puesto'] ?: 'Sin puesto'),
            'ocupacion' => $data['profesion_oficio'] ?: null,
            'contrato' => $data['contrato'],
            'es_supervisor' => false,
            'qr_code' => 'QR-' . $documentForDniColumn . '-' . Str::upper(Str::random(8)),
            'fecha_ingreso' => $data['fecha_ingreso'],
            'estado' => PersonalContratoDatoService::PENDING_STATE,
            'origen_registro' => 'IMPORTADO',
            'pendiente_contrato_firmado' => true,
        ];

        if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id')) {
            $payload['puesto_id'] = $puestoCatalogo->id;
        }

        if ($hasTipoDocumentoColumn) {
            $payload['tipo_documento'] = $data['tipo_documento'];
        }

        if ($hasNumeroDocumentoColumn) {
            $payload['numero_documento'] = $data['numero_documento'];
        }

        if ($hasTelefonoColumn) {
            $payload['telefono'] = $data['telefono'];
        }

        if ($hasTelefono1Column) {
            $payload['telefono_1'] = $data['telefono_1'];
        }

        if ($hasTelefono2Column) {
            $payload['telefono_2'] = $data['telefono_2'];
        }

        if ($hasCorreoColumn) {
            $payload['correo'] = $data['correo'];
        }

        return Personal::query()->create($payload);
    }

    private function personalUpdatesFromPersonnelData(
        Personal $personal,
        array $data,
        array &$workerChanges,
        array &$stats,
        bool $hasTelefonoColumn,
        bool $hasTelefono1Column,
        bool $hasTelefono2Column,
        bool $hasCorreoColumn,
        bool $hasTipoDocumentoColumn,
        bool $hasNumeroDocumentoColumn
    ): array {
        $updates = [];
        $puestoCatalogo = $this->resolveOrCreatePuestoCatalogo($data['puesto']);
        $puesto = $puestoCatalogo?->nombre ?: $data['puesto'];

        $fieldMap = [
            'nombre_completo' => ['Nombre', $data['nombre_completo']],
            'puesto' => ['Cargo/Puesto', $puesto],
            'ocupacion' => ['Ocupacion', $data['profesion_oficio'] ?: null],
            'contrato' => ['Contrato', $data['contrato']],
            'fecha_ingreso' => ['Fecha ingreso', $data['fecha_ingreso']],
        ];

        if ($hasTipoDocumentoColumn) {
            $fieldMap['tipo_documento'] = ['Tipo de documento', $data['tipo_documento']];
        }

        if ($hasNumeroDocumentoColumn) {
            $fieldMap['numero_documento'] = ['Numero de documento', $data['numero_documento']];
        }

        if ($hasTelefonoColumn) {
            $fieldMap['telefono'] = ['Telefono', $data['telefono']];
        }

        if ($hasCorreoColumn && $data['correo'] !== null) {
            $fieldMap['correo'] = ['Correo', $data['correo']];
        } elseif ($hasCorreoColumn && $data['correo_raw'] !== '') {
            $stats['correosInvalidos']++;
            $this->registerInvalidEmailDetail($stats, $data['numero_documento'], $data['nombre_completo'], $data['correo_raw']);
        }

        foreach ($fieldMap as $column => [$label, $value]) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $personal->{$column} ?? null;
            $currentComparable = $current instanceof \DateTimeInterface ? $current->format('Y-m-d') : (string) $current;
            if ($currentComparable === (string) $value) {
                continue;
            }

            $updates[$column] = $value;
            $this->registerFieldChange(
                $workerChanges,
                $stats,
                $column,
                $label,
                $column === 'contrato' ? PersonalNormalizer::contractLabel($current) : $currentComparable,
                $column === 'contrato' ? PersonalNormalizer::contractLabel($value) : $value
            );
        }

        if ($hasTelefonoColumn && $data['telefono'] === null && PersonalNormalizer::text($personal->telefono ?? '') !== '') {
            $updates['telefono'] = null;
            $this->registerFieldChange($workerChanges, $stats, 'telefono', 'Telefono', $personal->telefono, null);
        }

        if ($hasCorreoColumn && $data['correo_raw'] === '' && PersonalNormalizer::text($personal->correo ?? '') !== '') {
            $updates['correo'] = null;
            $this->registerFieldChange($workerChanges, $stats, 'correo', 'Correo', $personal->correo, null);
        }

        if ($puestoCatalogo && Schema::hasColumn('personal', 'puesto_id') && (string) ($personal->puesto_id ?? '') !== (string) $puestoCatalogo->id) {
            $updates['puesto_id'] = $puestoCatalogo->id;
        }

        if ($hasTelefono1Column && $data['telefono_1'] !== null && (string) ($personal->telefono_1 ?? '') !== (string) $data['telefono_1']) {
            $updates['telefono_1'] = $data['telefono_1'];
        }

        if ($hasTelefono1Column && $data['telefono_1'] === null && PersonalNormalizer::text($personal->telefono_1 ?? '') !== '') {
            $updates['telefono_1'] = null;
        }

        if ($hasTelefono2Column && $data['telefono_2'] !== null && (string) ($personal->telefono_2 ?? '') !== (string) $data['telefono_2']) {
            $updates['telefono_2'] = $data['telefono_2'];
        }

        if ($hasTelefono2Column && $data['telefono_2'] === null && PersonalNormalizer::text($personal->telefono_2 ?? '') !== '') {
            $updates['telefono_2'] = null;
        }

        if (array_key_exists('puesto', $updates)) {
            $stats['puestosActualizados']++;
        }

        return $updates;
    }

    private function syncApprovedFichaFromPersonnelData(Personal $personal, array $data, ?Usuario $user = null): bool
    {
        $fichaData = $this->fichaDataFromPersonnelRow($data);
        $ficha = $personal->relationLoaded('fichaColaborador') ? $personal->fichaColaborador : $personal->fichaColaborador()->first();

        $payload = [
            'id' => $ficha?->id ?: (string) Str::uuid(),
            'personal_id' => $personal->id,
            'estado' => PersonalFicha::ESTADO_APROBADO,
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
            'macro_tipo_contrato' => PersonalNormalizer::contractLabel($data['contrato']),
            'datos_json' => $this->mergeFichaDataAllowEmpty(is_array($ficha?->datos_json ?? null) ? $ficha->datos_json : [], $fichaData),
            'datos_detectados_json' => $this->mergeFichaDataAllowEmpty(is_array($ficha?->datos_detectados_json ?? null) ? $ficha->datos_detectados_json : [], $fichaData),
            'campos_verificacion_json' => [],
            'approved_at' => $ficha?->approved_at ?: now(),
            'approved_by_usuario_id' => $ficha?->approved_by_usuario_id ?: $user?->id,
            'created_by_usuario_id' => $ficha?->created_by_usuario_id ?: $user?->id,
            'observaciones_revision' => $ficha?->observaciones_revision ?: 'Ficha actualizada desde Excel de datos del personal.',
        ];

        $before = $ficha ? json_encode([
            'estado' => $ficha->estado,
            'datos_json' => $ficha->datos_json,
            'datos_detectados_json' => $ficha->datos_detectados_json,
        ]) : null;

        $ficha = PersonalFicha::query()->updateOrCreate(
            ['personal_id' => $personal->id],
            $payload,
        );

        $this->syncEmergencyContact($ficha, $data);
        $personal->setRelation('fichaColaborador', $ficha->fresh(['familiares']));

        $after = json_encode([
            'estado' => $ficha->estado,
            'datos_json' => $ficha->datos_json,
            'datos_detectados_json' => $ficha->datos_detectados_json,
        ]);

        return $before !== $after;
    }

    private function syncContractDataFromPersonnelData(Personal $personal, array $data, ?Usuario $user, array &$stats): bool
    {
        if (!Schema::hasTable('personal_contrato_datos')) {
            return false;
        }

        $payload = [
            'fecha_inicio_contrato' => $data['fecha_inicio_contrato'],
            'fecha_fin_contrato' => $data['fecha_fin_contrato'],
            'periodo_prueba_inicio' => $data['periodo_prueba_inicio'],
            'periodo_prueba_fin' => $data['periodo_prueba_fin'],
            'sueldo_num' => $data['remuneracion'],
            'sueldo_texto' => $data['remuneracion'],
            'puesto' => $data['puesto'],
        ];

        $service = app(PersonalContratoDatoService::class);
        $current = $personal->relationLoaded('contratoDatos') ? $personal->contratoDatos : $personal->contratoDatos()->first();
        $normalized = array_intersect_key(
            $service->normalizePayload($payload),
            array_flip(['fecha_inicio_contrato', 'fecha_fin_contrato', 'periodo_prueba_inicio', 'periodo_prueba_fin', 'sueldo_num', 'sueldo_texto', 'puesto'])
        );
        $changed = !$current;

        if ($current) {
            foreach ($normalized as $key => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $currentValue = $current->{$key} ?? null;
                $currentComparable = $currentValue instanceof \DateTimeInterface ? $currentValue->format('Y-m-d') : (string) $currentValue;
                if ($currentComparable !== (string) $value) {
                    $changed = true;
                    break;
                }
            }
        }

        $record = $current ?: $service->ensureForPersonal($personal, $payload, $user);
        if (!$current || $changed) {
            $contractDataPayload = $normalized;
            if ($user) {
                $contractDataPayload['updated_by_usuario_id'] = $user->id;
            }
            $record->forceFill($contractDataPayload)->save();
        }
        $personal->setRelation('contratoDatos', $record);

        if ($user && $data['fecha_inicio_contrato'] && $changed) {
            try {
                $contract = app(PersonalContratoService::class)->prepareIngressContract($personal->fresh(['fichaColaborador', 'minas', 'contratoDatos']) ?: $personal, [
                    'fecha_inicio_contrato' => $data['fecha_inicio_contrato'],
                    'fecha_fin_contrato' => $data['fecha_fin_contrato'],
                    'puesto' => $data['puesto'],
                    'tipo_contrato' => $data['contrato'],
                    'contrato' => $data['contrato'],
                    'remuneracion' => $data['remuneracion'],
                ], $user);

                if ($contract && strtoupper((string) $contract->estado) === PersonalContrato::ESTADO_PREPARACION) {
                    $stats['contratosPreparados'] = (int) ($stats['contratosPreparados'] ?? 0) + 1;
                }
            } catch (ValidationException $exception) {
                $this->registerNotUpdatedDetail(
                    $stats,
                    'noActualizadosDetalle',
                    $data['numero_documento'],
                    $data['nombre_completo'],
                    collect($exception->errors())->flatten()->first() ?: 'No se pudo preparar el contrato con las fechas importadas.'
                );
            }
        }

        return $changed;
    }

    private function fichaDataFromPersonnelRow(array $data): array
    {
        return [
            'nombres' => $data['nombres'],
            'apellido_paterno' => $data['apellido_paterno'],
            'apellido_materno' => $data['apellido_materno'],
            'sexo' => $data['sexo'],
            'estado_civil' => $data['estado_civil'],
            'nacionalidad' => $data['nacionalidad'],
            'nacionalidad_otra' => $data['nacionalidad_otra'],
            'grupo_sanguineo' => $data['grupo_sanguineo'],
            'brevete' => $data['brevete'],
            'tipo_documento' => $data['tipo_documento'],
            'numero_documento' => $data['numero_documento'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'pais_nacimiento' => $data['pais_nacimiento'],
            'pais_nacimiento_otro' => $data['pais_nacimiento_otro'],
            'departamento_nacimiento' => $data['departamento_nacimiento'],
            'provincia_nacimiento' => $data['provincia_nacimiento'],
            'distrito_nacimiento' => $data['distrito_nacimiento'],
            'telefono' => $data['telefono'],
            'correo' => $data['correo'],
            'domicilio_tipo' => $data['domicilio_tipo'],
            'domicilio_direccion' => $data['domicilio_direccion'],
            'domicilio_departamento' => $data['domicilio_departamento'],
            'domicilio_provincia' => $data['domicilio_provincia'],
            'domicilio_distrito' => $data['domicilio_distrito'],
            'puesto' => $data['puesto'],
            'contrato' => $data['contrato'],
            'banco' => $data['banco'],
            'banco_otro' => $data['banco_otro'],
            'numero_cuenta' => $data['numero_cuenta'],
            'cci' => $data['cci'],
            'grado_instruccion' => $data['grado_instruccion'],
            'profesion_oficio' => $data['profesion_oficio'],
            'especialidad' => $data['especialidad'],
            'anio_egreso' => $data['anio_egreso'],
            'anio_experiencia' => $data['anio_experiencia'],
            'carrera' => $data['profesion_oficio'],
            'institucion' => $data['institucion'],
            'remuneracion' => $data['remuneracion'],
            'sistema_pensionario' => $data['sistema_pensionario'],
            'tipo_afp' => $data['tipo_afp'],
            'talla_zapato' => $data['talla_zapato'],
            'talla_polo' => $data['talla_polo'],
            'talla_pantalon' => $data['talla_pantalon'],
            'talla_respirador' => $data['talla_respirador'],
            'fecha_ingreso' => $data['fecha_ingreso'],
            'fecha_inicio_contrato' => $data['fecha_inicio_contrato'],
            'fecha_fin_contrato' => $data['fecha_fin_contrato'],
            'periodo_prueba_inicio' => $data['periodo_prueba_inicio'],
            'periodo_prueba_fin' => $data['periodo_prueba_fin'],
        ];
    }

    private function syncEmergencyContact(PersonalFicha $ficha, array $data): void
    {
        if ($data['emergencia_nombre'] === '' && $data['emergencia_parentesco'] === '' && $data['emergencia_telefono'] === null) {
            return;
        }

        PersonalFichaFamiliar::query()->updateOrCreate(
            [
                'personal_ficha_id' => $ficha->id,
                'contacto_emergencia' => true,
            ],
            [
                'id' => PersonalFichaFamiliar::query()
                    ->where('personal_ficha_id', $ficha->id)
                    ->where('contacto_emergencia', true)
                    ->value('id') ?: (string) Str::uuid(),
                'nombres_apellidos' => $data['emergencia_nombre'],
                'parentesco' => $data['emergencia_parentesco'],
                'telefono' => $data['emergencia_telefono'],
                'vive_con_trabajador' => false,
                'estudia' => false,
                'contacto_emergencia' => true,
            ]
        );
    }

    private function detectPersonnelDataColumns(array $headers): ?array
    {
        $aliases = [
            'nombres' => ['nombres'],
            'apellido_paterno' => ['apellidopaterno'],
            'apellido_materno' => ['apellidomaterno'],
            'sexo' => ['sexo'],
            'estado_civil' => ['estadocivil'],
            'nacionalidad' => ['nacionalidad'],
            'grupo_sanguineo' => ['gruposanguineo', 'gsanguineo'],
            'brevete' => ['brevetelicenciadeconducir', 'brevetelicencia', 'brevete'],
            'tipo_documento' => ['tipodedocumento', 'tipodocumento'],
            'numero_documento' => ['numerodedocumento', 'numerodocumento', 'nrodocumento'],
            'fecha_nacimiento' => ['fechadenacimiento', 'fechanacimiento'],
            'pais_nacimiento' => ['paisdenacimiento', 'paisnacimiento'],
            'departamento_nacimiento' => ['departamentodenacimiento', 'departamentonacimiento'],
            'provincia_nacimiento' => ['provinciadenacimiento', 'provincianacimiento'],
            'distrito_nacimiento' => ['distritodenacimiento', 'distritonacimiento'],
            'telefono' => ['celularparticular'],
            'correo' => ['correoelectronico', 'correo', 'email'],
            'domicilio_direccion' => ['direccion'],
            'domicilio_departamento' => ['departamento'],
            'domicilio_provincia' => ['provincia'],
            'domicilio_distrito' => ['distrito'],
            'puesto' => ['cargopuesto', 'cargo', 'puesto'],
            'contrato' => ['contrato'],
            'banco' => ['banco'],
            'numero_cuenta' => ['cuentasueldo'],
            'cci' => ['ccisueldosueldo', 'ccisuentasueldosueldo', 'ccisuentasueldo', 'cci'],
            'grado_instruccion' => ['gradodeinstruccion', 'gradoinstruccion'],
            'profesion_oficio' => ['profesionyocarrera', 'profesionocarrera', 'profesioncarrera'],
            'titulado' => ['titulado'],
            'institucion' => ['centrodeestudios', 'institucion'],
            'anio_egreso' => ['anodeegreso', 'aniodeegreso'],
            'anio_experiencia' => ['anosdeexperiencia', 'aniosdeexperiencia'],
            'sistema_pensionario' => ['sistemadepension', 'sistemapension'],
            'tipo_afp' => ['elecciondelsistemapensionario', 'eleccionsistemapensionario'],
            'remuneracion' => ['remuneracion'],
            'talla_zapato' => ['zapatos', 'zapato'],
            'talla_polo' => ['camisa'],
            'talla_pantalon' => ['pantalon'],
            'talla_respirador' => ['respirador'],
            'emergencia_nombre' => ['encasodeemergencia'],
            'emergencia_parentesco' => ['parentezco', 'parentesco'],
            'emergencia_telefono' => ['celular'],
            'fecha_ingreso' => ['fechaingreso'],
            'fecha_inicio_contrato' => ['fechadecontrato', 'fechacontrato'],
            'periodo_prueba_fin' => ['fechadeterminodelperiododeprueba', 'fechaterminoperiodoprueba', 'terminoperiodoprueba'],
            'fecha_fin_contrato' => ['fechafin'],
        ];

        $columns = array_fill_keys(array_keys($aliases), null);

        foreach ($headers as $index => $header) {
            $key = PersonalNormalizer::normalizeKey(PersonalNormalizer::text($header));
            if ($key === '') {
                continue;
            }

            foreach ($aliases as $field => $fieldAliases) {
                if ($columns[$field] === null && in_array($key, $fieldAliases, true)) {
                    $columns[$field] = $index;
                    break;
                }
            }
        }

        foreach (['nombres', 'apellido_paterno', 'apellido_materno', 'tipo_documento', 'numero_documento', 'puesto', 'contrato'] as $required) {
            if ($columns[$required] === null) {
                return null;
            }
        }

        return $columns;
    }

    private function importPersonnelDataRows(array $dataRows, array $columns, ?Usuario $user = null): array
    {
        $hasTelefonoColumn = Schema::hasColumn('personal', 'telefono');
        $hasTelefono1Column = Schema::hasColumn('personal', 'telefono_1');
        $hasTelefono2Column = Schema::hasColumn('personal', 'telefono_2');
        $hasCorreoColumn = Schema::hasColumn('personal', 'correo');
        $hasTipoDocumentoColumn = Schema::hasColumn('personal', 'tipo_documento');
        $hasNumeroDocumentoColumn = Schema::hasColumn('personal', 'numero_documento');

        $stats = $this->emptyStats();
        $stats['tipoImportacion'] = 'datos_personal';
        $stats['formatoDetectado'] = 'Excel de datos del personal';
        $stats['filasLeidas'] = count($dataRows);
        $stats['fichasActualizadas'] = 0;
        $stats['contratoDatosActualizados'] = 0;
        $stats['contratosPreparados'] = 0;

        $existingSelect = [
            'id',
            'dni',
            'nombre_completo',
            'puesto',
            'ocupacion',
            'contrato',
            'es_supervisor',
            'fecha_ingreso',
            'estado',
            'pendiente_contrato_firmado',
        ];

        foreach (['telefono', 'telefono_1', 'telefono_2', 'correo', 'tipo_documento', 'numero_documento', 'puesto_id'] as $column) {
            if (Schema::hasColumn('personal', $column)) {
                $existingSelect[] = $column;
            }
        }

        $existing = Personal::query()
            ->with(['fichaColaborador.familiares', 'contratoDatos', 'contratoLaboralActual'])
            ->get(array_values(array_unique($existingSelect)));

        $existingByDocument = $existing->mapWithKeys(function (Personal $personal): array {
            $type = PersonalNormalizer::documentType($personal->tipo_documento ?? 'DNI', $personal->numero_documento ?? $personal->dni);
            $number = PersonalNormalizer::documentNumber($personal->numero_documento ?? $personal->dni);

            return [$this->documentLookupKey($type, $number) => $personal];
        });
        $existingByDni = $existing->keyBy('dni');

        $processedDocuments = [];

        foreach (array_chunk($dataRows, self::IMPORT_BATCH_SIZE) as $chunkRows) {
            DB::transaction(function () use ($chunkRows, $columns, $user, $hasTelefonoColumn, $hasTelefono1Column, $hasTelefono2Column, $hasCorreoColumn, $hasTipoDocumentoColumn, $hasNumeroDocumentoColumn, $existingByDocument, $existingByDni, &$processedDocuments, &$stats): void {
                foreach ($chunkRows as $row) {
                    if (!$this->rowHasContent($row)) {
                        continue;
                    }

                    $rowData = $this->personnelRowData($row, $columns);
                    $type = $rowData['tipo_documento'];
                    $number = $rowData['numero_documento'];
                    $lookupKey = $this->documentLookupKey($type, $number);

                    if (!PersonalNormalizer::isValidDocument($type, $number)) {
                        $stats['omitidos']++;
                        $this->registerNotUpdatedDetail($stats, 'omitidosDetalle', $number, $rowData['nombre_completo'], 'Tipo o numero de documento invalido.');
                        continue;
                    }

                    if (isset($processedDocuments[$lookupKey])) {
                        $stats['duplicados']++;
                        $this->registerNotUpdatedDetail($stats, 'noActualizadosDetalle', $number, $rowData['nombre_completo'], 'Documento duplicado en el archivo.');
                        continue;
                    }
                    $processedDocuments[$lookupKey] = true;

                    $personal = $existingByDocument->get($lookupKey)
                        ?: ($type === 'DNI' ? $existingByDni->get(PersonalNormalizer::dni($number)) : null);

                    $wasNew = false;
                    $updates = [];
                    $workerChanges = [];

                    if (!$personal) {
                        $personal = $this->createPersonalFromData(
                            $rowData,
                            $hasTelefonoColumn,
                            $hasTelefono1Column,
                            $hasTelefono2Column,
                            $hasCorreoColumn,
                            $hasTipoDocumentoColumn,
                            $hasNumeroDocumentoColumn
                        );
                        $wasNew = true;
                        $stats['nuevos']++;
                        $existingByDocument->put($lookupKey, $personal);
                        $existingByDni->put($personal->dni, $personal);

                        if (count($stats['nuevosDetalle']) < self::MAX_CHANGE_DETAILS) {
                            $stats['nuevosDetalle'][] = [
                                'dni' => $number,
                                'nombre' => $rowData['nombre_completo'],
                                'puesto' => $rowData['puesto'] ?: '-',
                                'ocupacion' => $rowData['profesion_oficio'] ?: '-',
                                'contrato' => PersonalNormalizer::contractLabel($rowData['contrato']),
                            ];
                        }
                    } else {
                        $updates = $this->personalUpdatesFromPersonnelData(
                            $personal,
                            $rowData,
                            $workerChanges,
                            $stats,
                            $hasTelefonoColumn,
                            $hasTelefono1Column,
                            $hasTelefono2Column,
                            $hasCorreoColumn,
                            $hasTipoDocumentoColumn,
                            $hasNumeroDocumentoColumn
                        );

                        if (count($updates) > 0) {
                            Personal::query()->where('id', $personal->id)->update($updates);
                            $personal->fill($updates);
                        }
                    }

                    $fichaChanged = $this->syncApprovedFichaFromPersonnelData($personal, $rowData, $user);
                    if ($fichaChanged) {
                        $stats['fichasActualizadas']++;
                    }

                    $contractDataChanged = $this->syncContractDataFromPersonnelData($personal, $rowData, $user, $stats);
                    if ($contractDataChanged) {
                        $stats['contratoDatosActualizados']++;
                    }

                    $stateBefore = strtoupper((string) $personal->estado);
                    $personal = $personal->fresh(['fichaColaborador', 'contratoDatos', 'contratoLaboralActual']) ?: $personal;
                    $stateAfter = app(PersonalService::class)->resolveActiveIntentState($personal);
                    if ($stateAfter !== $stateBefore) {
                        Personal::query()->where('id', $personal->id)->update(['estado' => $stateAfter]);
                        $personal->estado = $stateAfter;
                        $this->registerFieldChange($workerChanges, $stats, 'estado', 'Estado', $stateBefore, $stateAfter);

                        if ($stateAfter === 'ACTIVO') {
                            $stats['reactivados']++;
                            if (count($stats['reactivadosDetalle']) < self::MAX_CHANGE_DETAILS) {
                                $stats['reactivadosDetalle'][] = [
                                    'dni' => $number,
                                    'nombre' => $rowData['nombre_completo'],
                                    'antes' => $stateBefore,
                                    'despues' => 'ACTIVO',
                                ];
                            }
                        } elseif ($stateAfter === PersonalContratoDatoService::PENDING_STATE) {
                            $this->registerBlockedActivation($stats, $number, $rowData['nombre_completo'], $stateBefore, $stateAfter);
                        }
                    } elseif ($stateAfter !== 'ACTIVO') {
                        $this->registerBlockedActivation($stats, $number, $rowData['nombre_completo'], $stateBefore, $stateAfter);
                    }

                    if (!$wasNew && count($workerChanges) > 0) {
                        $stats['actualizados']++;
                        $stats['cambiosDetectadosTotal']++;

                        if (count($stats['cambiosDetectados']) < self::MAX_CHANGE_DETAILS) {
                            $stats['cambiosDetectados'][] = [
                                'dni' => $number,
                                'nombre' => $rowData['nombre_completo'],
                                'cambios' => array_values($workerChanges),
                            ];
                        }
                    }
                }
            });
        }

        $stats['bloquesProcesados'] = count(array_chunk($dataRows, self::IMPORT_BATCH_SIZE));
        $stats['tamanoBloque'] = self::IMPORT_BATCH_SIZE;

        return $stats;
    }

    private function emptyStats(): array
    {
        return [
            'nuevos' => 0,
            'reactivados' => 0,
            'inactivados' => 0,
            'puestosActualizados' => 0,
            'duplicados' => 0,
            'omitidos' => 0,
            'minasDetectadas' => [],
            'minasActivasDetectadas' => 0,
            'minasCreadas' => 0,
            'minasReutilizadas' => 0,
            'minasActualizadas' => 0,
            'relacionesMinaCreadas' => 0,
            'relacionesMinaActualizadas' => 0,
            'relacionesMinaCreadasOActualizadas' => 0,
            'relacionesMinaEliminadas' => 0,
            'actualizados' => 0,
            'camposActualizados' => 0,
            'telefonosDetectados' => 0,
            'trabajadoresCon1Telefono' => 0,
            'trabajadoresCon2Telefonos' => 0,
            'telefonosCasosInvalidosLimpios' => 0,
            'telefonosCasosOmitidos' => 0,
            'telefonosConMasDeDos' => 0,
            'correosInvalidos' => 0,
            'correosInvalidosDetalle' => [],
            'cambiosDetectadosTotal' => 0,
            'cambiosDetectados' => [],
            'omitidosDetalle' => [],
            'noActualizadosDetalle' => [],
            'nuevosDetalle' => [],
            'reactivadosDetalle' => [],
            'inactivadosDetalle' => [],
            'activacionesBloqueadas' => 0,
            'activacionesBloqueadasDetalle' => [],
        ];
    }

    private function addPhoneStats(array &$stats, string $dni, array $phoneData): void
    {
        if ($phoneData['valid_count'] === 1) {
            $stats['trabajadoresCon1Telefono']++;
        }

        if ($phoneData['valid_count'] === 2) {
            $stats['trabajadoresCon2Telefonos']++;
        }

        if ($phoneData['valid_count'] > 0) {
            $stats['telefonosDetectados'] += (int) $phoneData['valid_count'];
        }

        if ($phoneData['had_invalid_cleanup'] || $phoneData['had_duplicates']) {
            $stats['telefonosCasosInvalidosLimpios']++;
        }

        if ($phoneData['raw_has_content'] && $phoneData['valid_count'] === 0) {
            $stats['telefonosCasosOmitidos']++;
        }

        if ($phoneData['had_more_than_two']) {
            $stats['telefonosConMasDeDos']++;

            Log::warning('Import Personal: se detectaron mas de dos telefonos, se conservaron solo dos.', [
                'dni' => $dni,
                'raw' => $phoneData['raw'] ?? null,
                'telefonos_detectados' => $phoneData['all_valid_numbers'] ?? [],
            ]);
        }
    }

    private function normalizeEmail(string $value): ?string
    {
        $email = mb_strtolower(trim($value));
        if ($email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function rowHasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if (PersonalNormalizer::text($cell) !== '') {
                return true;
            }
        }

        return false;
    }

    private function extendImportExecutionWindow(): void
    {
        @ini_set('max_execution_time', (string) self::IMPORT_MAX_EXECUTION_SECONDS);

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::IMPORT_MAX_EXECUTION_SECONDS);
        }
    }

    private function syncFichaWithImportData(Personal $personal, array $importedData): void
    {
        $importedData = array_filter(
            $importedData,
            static fn ($value): bool => PersonalNormalizer::text($value) !== ''
        );

        if ($importedData === []) {
            return;
        }

        $ficha = $personal->fichaColaborador;
        if (!$ficha) {
            return;
        }

        $currentData = is_array($ficha->datos_json ?? null) ? $ficha->datos_json : [];
        $detectedData = is_array($ficha->datos_detectados_json ?? null) ? $ficha->datos_detectados_json : [];

        $ficha->forceFill([
            'datos_json' => $this->mergeFichaData($currentData, $importedData),
            'datos_detectados_json' => $this->mergeFichaData($detectedData, $importedData),
        ])->save();
    }

    private function mergeFichaData(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (PersonalNormalizer::text($value) === '') {
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function mergeFichaDataAllowEmpty(array $base, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            $base[$key] = $value;
        }

        return $base;
    }

    private function buildFichaImportData(
        string $nombreCompleto,
        string $puesto,
        string $ocupacion,
        ?string $contrato,
        ?string $fechaIngreso,
        ?string $telefono,
        ?string $correo
    ): array {
        return array_merge(
            $this->nameFieldsFromFullName($nombreCompleto),
            [
                'puesto' => $puesto,
                'ocupacion' => $ocupacion,
                'contrato' => $contrato,
                'fecha_ingreso' => $fechaIngreso,
                'telefono' => $telefono,
                'correo' => $correo,
            ]
        );
    }

    private function nameFieldsFromFullName(string $nombreCompleto): array
    {
        $nombreCompleto = mb_strtoupper(trim($nombreCompleto), 'UTF-8');
        if ($nombreCompleto === '') {
            return [];
        }

        $parts = preg_split('/\s+/', $nombreCompleto) ?: [];
        if (count($parts) >= 3) {
            return [
                'apellido_paterno' => $parts[0] ?? '',
                'apellido_materno' => $parts[1] ?? '',
                'nombres' => implode(' ', array_slice($parts, 2)),
            ];
        }

        return ['nombres' => $nombreCompleto];
    }

    private function nullableExcelText(mixed $value): string
    {
        $text = $this->numericExcelText($value);
        $normalized = PersonalNormalizer::normalizeKey($text);

        return in_array($normalized, ['', 'na', 'n/a', 'noaplica', 'null'], true) || trim($text) === '-'
            ? ''
            : $text;
    }

    private function numericExcelText(mixed $value): string
    {
        $text = PersonalNormalizer::text($value);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^[+-]?\d+(?:\.\d+)?E[+-]?\d+$/i', $text) === 1) {
            return sprintf('%.0F', (float) $text);
        }

        if (preg_match('/^\d+\.0+$/', $text) === 1) {
            return preg_replace('/\.0+$/', '', $text) ?: $text;
        }

        return $text;
    }

    private function nullableNumericExcelText(mixed $value): string
    {
        $text = $this->numericExcelText($value);

        return trim($text) === '-' ? '' : $text;
    }

    private function yearText(mixed $value): string
    {
        $text = $this->nullableExcelText($value);
        if ($text === '') {
            return '';
        }

        if (preg_match('/^\d{5}$/', $text) === 1) {
            $date = PersonalNormalizer::isoDate($text);

            return $date ? substr($date, 0, 4) : $text;
        }

        return $text;
    }

    private function documentLookupKey(string $type, string $number): string
    {
        return PersonalNormalizer::documentType($type, $number) . ':' . PersonalNormalizer::documentNumber($number);
    }

    private function normalizeSex(string $value): string
    {
        $key = PersonalNormalizer::normalizeKey($value);

        return match ($key) {
            'masculino', 'hombre', 'm' => 'Masculino',
            'femenino', 'mujer', 'f' => 'Femenino',
            default => $value !== '' ? $value : '',
        };
    }

    private function normalizeCivilState(string $value): string
    {
        $key = PersonalNormalizer::normalizeKey($value);

        return match ($key) {
            'soltero', 'soltera' => 'Soltero',
            'casado', 'casada' => 'Casado',
            'conviviente' => 'Conviviente',
            'divorciado', 'divorciada' => 'Divorciado',
            'viudo', 'viuda' => 'Viudo',
            default => $value !== '' ? 'Otro' : '',
        };
    }

    private function normalizeNationality(string $value): string
    {
        $key = PersonalNormalizer::normalizeKey($value);
        if (in_array($key, ['peruano', 'peruana', 'peru'], true)) {
            return 'Peruana';
        }

        if (in_array($key, ['venezolano', 'venezolana'], true)) {
            return 'Venezolana';
        }

        if (in_array($key, ['colombiano', 'colombiana'], true)) {
            return 'Colombiana';
        }

        return $value !== '' ? 'Otra' : '';
    }

    private function normalizeCountry(string $value): string
    {
        $key = PersonalNormalizer::normalizeKey($value);

        return in_array($key, ['peru', 'peruano', 'peruana'], true) ? 'Peru' : ($value !== '' ? 'Otro' : '');
    }

    private function normalizeBank(string $value): array
    {
        $key = PersonalNormalizer::normalizeKey($value);

        if ($key === '') {
            return ['banco' => '', 'banco_otro' => ''];
        }

        if ($key === 'bcp') {
            return ['banco' => 'BCP', 'banco_otro' => ''];
        }

        if (str_contains($key, 'interbank')) {
            return ['banco' => 'Interbank', 'banco_otro' => ''];
        }

        return ['banco' => 'Otro', 'banco_otro' => $value];
    }

    private function normalizePension(string $system, string $choice): array
    {
        $systemKey = PersonalNormalizer::normalizeKey($system);
        $choiceText = $choice;

        if (str_contains($systemKey, 'privado') || str_starts_with(PersonalNormalizer::normalizeKey($choice), 'afp')) {
            return [
                'sistema_pensionario' => 'Sistema Privado de Pensiones',
                'tipo_afp' => $choiceText,
            ];
        }

        if (str_contains($systemKey, 'nacional') || str_contains($systemKey, 'onp')) {
            return [
                'sistema_pensionario' => 'ONP',
                'tipo_afp' => '',
            ];
        }

        return [
            'sistema_pensionario' => $system,
            'tipo_afp' => $choiceText,
        ];
    }

    private function resolveOrCreatePuestoCatalogo(string $puesto): ?PersonalPuesto
    {
        if ($this->canUsePuestoCatalog === null) {
            $this->canUsePuestoCatalog = Schema::hasTable('personal_puestos') && Schema::hasColumn('personal', 'puesto_id');
        }

        if (!$this->canUsePuestoCatalog) {
            return null;
        }

        $nombre = mb_substr(trim($puesto), 0, self::MAX_PUESTO_LENGTH);
        if ($nombre === '') {
            return null;
        }

        $cacheKey = PersonalNormalizer::normalizeKey($nombre);
        if (array_key_exists($cacheKey, $this->puestoCatalogCache)) {
            return $this->puestoCatalogCache[$cacheKey];
        }

        $existing = PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->first();

        if ($existing) {
            if (!$existing->activo) {
                $existing->forceFill(['activo' => true])->save();
            }

            return $this->puestoCatalogCache[$cacheKey] = $existing;
        }

        return $this->puestoCatalogCache[$cacheKey] = PersonalPuesto::query()->create([
            'id' => (string) Str::uuid(),
            'nombre' => $nombre,
            'funciones' => 'Creado desde Excel de datos del personal.',
            'activo' => true,
        ]);
    }

    private function resolvePuestoCatalogo(string $puesto): ?PersonalPuesto
    {
        if (!Schema::hasTable('personal_puestos') || !Schema::hasColumn('personal', 'puesto_id')) {
            return null;
        }

        $nombre = mb_substr(trim($puesto), 0, self::MAX_PUESTO_LENGTH);
        if ($nombre === '') {
            return null;
        }

        return PersonalPuesto::query()
            ->where('nombre', $nombre)
            ->where('activo', true)
            ->first();
    }

    private function resolveHeadersAndDataRows(array $rows): array
    {
        $maxScan = min(10, count($rows));
        $bestIndex = 0;
        $bestScore = -1;

        for ($i = 0; $i < $maxScan; $i++) {
            $candidate = array_map(fn ($item) => PersonalNormalizer::text($item), $rows[$i] ?? []);
            $score = $this->scoreHeaderCandidate($candidate);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $i;
            }
        }

        $headerRow = array_map(fn ($item) => PersonalNormalizer::text($item), $rows[$bestIndex] ?? []);
        $dataRows = array_slice($rows, $bestIndex + 1);

        return [$headerRow, $dataRows];
    }

    private function scoreHeaderCandidate(array $candidate): int
    {
        $score = 0;
        $found = [
            'dni' => false,
            'nombre' => false,
            'puesto' => false,
            'fecha_ingreso' => false,
            'ocupacion' => false,
            'contrato' => false,
        ];

        foreach ($candidate as $cell) {
            $upper = strtoupper(PersonalNormalizer::text($cell));
            if ($upper === '') {
                continue;
            }

            if (!$found['dni'] && str_contains($upper, 'DNI')) {
                $found['dni'] = true;
                $score++;
            }

            if (!$found['nombre'] && (str_contains($upper, 'NOMBRE') || str_contains($upper, 'APELLIDO'))) {
                $found['nombre'] = true;
                $score++;
            }

            if (!$found['puesto'] && (str_contains($upper, 'PUESTO') || str_contains($upper, 'CARGO'))) {
                $found['puesto'] = true;
                $score++;
            }

            if (!$found['fecha_ingreso'] && str_contains($upper, 'FECHA') && str_contains($upper, 'INGRESO')) {
                $found['fecha_ingreso'] = true;
                $score++;
            }

            if (!$found['ocupacion'] && str_contains($upper, 'OCUP')) {
                $found['ocupacion'] = true;
                $score++;
            }

            if (!$found['contrato'] && str_contains($upper, 'CONTRATO')) {
                $found['contrato'] = true;
                $score++;
            }
        }

        return $score;
    }

    private function detectContactColumns(array $headers): ?array
    {
        $columns = [
            'dni' => null,
            'nombre' => null,
            'puesto' => null,
            'telefono' => null,
            'correo' => null,
        ];

        foreach ($headers as $index => $header) {
            $text = PersonalNormalizer::text($header);
            if ($text === '') {
                continue;
            }

            $key = PersonalNormalizer::normalizeKey($text);

            if ($columns['dni'] === null && in_array($key, ['dni', 'documento', 'nrodocumento', 'numerodocumento'], true)) {
                $columns['dni'] = $index;
                continue;
            }

            if ($columns['nombre'] === null && in_array($key, ['nombres', 'nombrecompleto', 'apellidosynombres', 'apellidosnombres'], true)) {
                $columns['nombre'] = $index;
                continue;
            }

            if ($columns['puesto'] === null && in_array($key, ['cargo', 'puesto', 'cargopuesto', 'cargogeneral'], true)) {
                $columns['puesto'] = $index;
                continue;
            }

            if ($columns['telefono'] === null && in_array($key, self::PHONE_ALIASES, true)) {
                $columns['telefono'] = $index;
                continue;
            }

            if ($columns['correo'] === null && in_array($key, ['correo', 'email', 'correoelectronico', 'mailelectronico'], true)) {
                $columns['correo'] = $index;
            }
        }

        foreach (self::CONTACT_FORMAT_REQUIRED_COLUMNS as $required) {
            if ($columns[$required] === null) {
                return null;
            }
        }

        return $columns;
    }

    private function looksLikeContactWorkbook(array $headers): bool
    {
        $found = [
            'dni' => false,
            'nombre' => false,
            'puesto' => false,
            'telefono' => false,
            'correo' => false,
            'fecha_fin' => false,
            'ocupacion' => false,
            'contrato' => false,
        ];

        foreach ($headers as $header) {
            $key = PersonalNormalizer::normalizeKey(PersonalNormalizer::text($header));
            if ($key === '') {
                continue;
            }

            if (in_array($key, ['dni', 'documento', 'nrodocumento', 'numerodocumento'], true)) {
                $found['dni'] = true;
            }

            if (in_array($key, ['nombres', 'nombrecompleto', 'apellidosynombres', 'apellidosnombres'], true)) {
                $found['nombre'] = true;
            }

            if (in_array($key, ['cargo', 'puesto', 'cargopuesto', 'cargogeneral'], true)) {
                $found['puesto'] = true;
            }

            if (in_array($key, self::PHONE_ALIASES, true)) {
                $found['telefono'] = true;
            }

            if (in_array($key, ['correo', 'email', 'correoelectronico', 'mailelectronico'], true)) {
                $found['correo'] = true;
            }

            if (str_contains($key, 'fechafin') || str_contains($key, 'fechadefin') || str_contains($key, 'findecontrato')) {
                $found['fecha_fin'] = true;
            }

            if (str_contains($key, 'ocup')) {
                $found['ocupacion'] = true;
            }

            if (str_contains($key, 'contrato')) {
                $found['contrato'] = true;
            }
        }

        $hasContactCore = $found['dni'] && $found['nombre'] && $found['puesto'] && ($found['telefono'] || $found['correo'] || $found['fecha_fin']);
        $looksLikeMaster = $found['ocupacion'] || $found['contrato'];

        return $hasContactCore && !$looksLikeMaster;
    }

    private function isSupportedMasterFormat(array $headers, array $dataRows, array $strictColumns): bool
    {
        $hasCoreHeaders = $strictColumns['dni'] !== null
            && $strictColumns['nombre'] !== null
            && $strictColumns['puesto'] !== null
            && $strictColumns['fecha_ingreso'] !== null;

        if ($hasCoreHeaders) {
            return true;
        }

        if ($this->scoreHeaderCandidate($headers) >= 3) {
            return true;
        }

        return $this->looksLikeLegacyMasterByPosition($dataRows);
    }

    private function looksLikeLegacyMasterByPosition(array $dataRows): bool
    {
        $sample = array_slice($dataRows, 0, 40);
        $validRows = 0;

        foreach ($sample as $row) {
            $dni = PersonalNormalizer::dni($row[self::DEFAULT_COLUMNS['dni']] ?? null);
            $nombre = PersonalNormalizer::text($row[self::DEFAULT_COLUMNS['nombre']] ?? null);
            $puesto = PersonalNormalizer::text($row[self::DEFAULT_COLUMNS['puesto']] ?? null);

            if (PersonalNormalizer::isValidDni($dni) && $nombre !== '' && $puesto !== '') {
                $validRows++;
            }
        }

        return $validRows >= 3;
    }

    private function readRows(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'xlsx') {
            try {
                // Prefer XML cached values for XLSX to avoid formula-evaluation failures.
                return $this->readXlsxRowsFallback($file->getRealPath());
            } catch (\Throwable) {
                if (!class_exists(IOFactory::class)) {
                    throw new \RuntimeException('No se pudo procesar el XLSX en este entorno.');
                }
            }
        }

        if (class_exists(IOFactory::class)) {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $sheet = $this->resolveSheet($spreadsheet);

            return $sheet->toArray(null, false, true, false);
        }

        throw new \RuntimeException('No hay lector disponible para este tipo de archivo.');
    }

    private function resolveSheet(\PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        foreach ($spreadsheet->getSheetNames() as $name) {
            if (strtoupper(PersonalNormalizer::text($name)) === 'RESUMEN GRAL') {
                return $spreadsheet->getSheetByName($name);
            }
        }

        return $spreadsheet->getSheet(0);
    }

    private function readXlsxRowsFallback(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo XLSX.');
        }

        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            $zip->close();
            throw new \RuntimeException('Estructura XLSX invalida.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetPath = $this->resolveSheetPath($workbookXml, $relsXml);
        $sheetXml = $zip->getFromName($sheetPath);

        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('No se encontro una hoja valida en el XLSX.');
        }

        return $this->parseSheetRows($sheetXml, $sharedStrings);
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXml === false) {
            return [];
        }

        $xml = simplexml_load_string($sharedXml);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }

        $items = [];
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $items[] = (string) $si->t;
                continue;
            }

            $parts = [];
            foreach ($si->r as $r) {
                $parts[] = (string) $r->t;
            }
            $items[] = implode('', $parts);
        }

        return $items;
    }

    private function resolveSheetPath(string $workbookXml, string $relsXml): string
    {
        $workbook = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);

        if (!$workbook instanceof SimpleXMLElement || !$rels instanceof SimpleXMLElement) {
            throw new \RuntimeException('No se pudo leer la estructura del libro XLSX.');
        }

        $workbook->registerXPathNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $sheets = $workbook->xpath('//s:sheets/s:sheet') ?: [];
        if (count($sheets) === 0) {
            throw new \RuntimeException('El libro XLSX no tiene hojas.');
        }

        $selected = $sheets[0];
        foreach ($sheets as $sheet) {
            $name = strtoupper(trim((string) ($sheet['name'] ?? '')));
            if ($name === 'RESUMEN GRAL') {
                $selected = $sheet;
                break;
            }
        }

        $ridAttr = $selected->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($ridAttr['id'] ?? '');
        if ($rid === '') {
            throw new \RuntimeException('No se pudo resolver la hoja seleccionada.');
        }

        foreach ($rels->Relationship as $rel) {
            $id = (string) ($rel['Id'] ?? '');
            if ($id !== $rid) {
                continue;
            }

            $target = (string) ($rel['Target'] ?? '');
            $target = ltrim($target, '/');

            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }

        throw new \RuntimeException('No se encontro la ruta de la hoja XLSX.');
    }

    private function parseSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $xml = simplexml_load_string($sheetXml);
        if (!$xml instanceof SimpleXMLElement || !isset($xml->sheetData)) {
            throw new \RuntimeException('No se pudo leer las filas del XLSX.');
        }

        $rows = [];
        $maxCols = 0;

        foreach ($xml->sheetData->row as $row) {
            $current = [];

            foreach ($row->c as $cell) {
                $cellRef = (string) ($cell['r'] ?? '');
                $colIndex = $this->columnIndexFromCellRef($cellRef);
                $type = (string) ($cell['t'] ?? '');

                $value = null;
                if ($type === 's') {
                    $sharedIndex = (int) ($cell->v ?? 0);
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = (string) ($cell->is->t ?? '');
                } else {
                    $value = isset($cell->v) ? (string) $cell->v : '';
                }

                $current[$colIndex] = $value;
                $maxCols = max($maxCols, $colIndex + 1);
            }

            if (count($current) > 0) {
                ksort($current);
                $rows[] = $current;
            }
        }

        $normalizedRows = [];
        foreach ($rows as $row) {
            $line = array_fill(0, $maxCols, null);
            foreach ($row as $index => $value) {
                $line[(int) $index] = $value;
            }
            $normalizedRows[] = $line;
        }

        return $normalizedRows;
    }

    private function columnIndexFromCellRef(string $cellRef): int
    {
        $letters = preg_replace('/[^A-Z]/', '', strtoupper($cellRef));
        if ($letters === '') {
            return 0;
        }

        $index = 0;
        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max(0, $index - 1);
    }

    private function detectColumns(array $headers, bool $applyFallbacks = true): array
    {
        $indexes = [
            'dni' => null,
            'nombre' => null,
            'puesto' => null,
            'fecha_ingreso' => null,
            'ocupacion' => null,
            'contrato' => null,
            'telefonos' => [],
            'correo' => null,
        ];

        $preferredCell = $headers[12] ?? null;
        if ($preferredCell !== null && in_array(PersonalNormalizer::normalizeKey(PersonalNormalizer::text($preferredCell)), self::PHONE_ALIASES, true)) {
            $indexes['telefonos'][] = 12;
        }

        foreach ($headers as $index => $header) {
            $upper = strtoupper(PersonalNormalizer::text($header));
            if ($upper === '') {
                continue;
            }

            if ($indexes['dni'] === null && str_contains($upper, 'DNI')) {
                $indexes['dni'] = $index;
            }

            if ($indexes['nombre'] === null && (str_contains($upper, 'NOMBRE') || str_contains($upper, 'APELLIDO'))) {
                $indexes['nombre'] = $index;
            }

            if ($indexes['puesto'] === null && (str_contains($upper, 'PUESTO') || str_contains($upper, 'CARGO'))) {
                $indexes['puesto'] = $index;
            }

            if ($indexes['fecha_ingreso'] === null && str_contains($upper, 'FECHA') && str_contains($upper, 'INGRESO')) {
                $indexes['fecha_ingreso'] = $index;
            }

            if ($indexes['ocupacion'] === null && str_contains($upper, 'OCUP')) {
                $indexes['ocupacion'] = $index;
            }

            if ($indexes['contrato'] === null && str_contains($upper, 'CONTRATO')) {
                $indexes['contrato'] = $index;
            }

$normalizedHeader = PersonalNormalizer::normalizeKey($upper);
            $normalizedHeaderLower = mb_strtolower($normalizedHeader);
            $isPhoneColumn = false;

            if (in_array($normalizedHeaderLower, self::PHONE_ALIASES, true)) {
                $isPhoneColumn = true;
            }

            if (!$isPhoneColumn && $upper !== '' && (
                str_starts_with($upper, 'CEL') ||
                str_starts_with($upper, 'TEL') ||
                str_starts_with($upper, 'FON') ||
                str_starts_with($upper, 'MOV')
            )) {
                $isPhoneColumn = true;
            }

            if ($isPhoneColumn && !in_array($index, $indexes['telefonos'], true)) {
                $indexes['telefonos'][] = $index;
            }

            if ($indexes['correo'] === null && (str_contains($upper, 'CORREO') || str_contains($upper, 'EMAIL'))) {
                $indexes['correo'] = $index;
            }
        }

        if ($applyFallbacks) {
            foreach (self::DEFAULT_COLUMNS as $key => $fallback) {
                if ($indexes[$key] === null) {
                    $indexes[$key] = $fallback;
                }
            }

            if (count($indexes['telefonos']) === 0 && isset($headers[12])) {
                $indexes['telefonos'][] = 12;
            }
        }

        return $indexes;
    }

    private function extractPhoneRaw(array $row, array $columns): ?string
    {
        $values = [];

        foreach (($columns['telefonos'] ?? []) as $index) {
            if (!is_int($index)) {
                continue;
            }

            $value = PersonalNormalizer::text($row[$index] ?? null);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        if (count($values) === 0) {
            return null;
        }

        return implode(' / ', $values);
    }

    private function registerNotUpdatedDetail(array &$stats, string $bucket, mixed $dni, mixed $nombre, string $motivo): void
    {
        if (!isset($stats[$bucket]) || !is_array($stats[$bucket])) {
            $stats[$bucket] = [];
        }

        if (count($stats[$bucket]) >= self::MAX_CHANGE_DETAILS) {
            return;
        }

        $dniText = PersonalNormalizer::text($dni);

        $stats[$bucket][] = [
            'dni' => $dniText !== '' ? $dniText : 'Sin DNI',
            'nombre' => PersonalNormalizer::text($nombre) ?: '-',
            'motivo' => $motivo,
        ];
    }

    private function registerInvalidEmailDetail(array &$stats, mixed $dni, mixed $nombre, string $correo): void
    {
        if (!isset($stats['correosInvalidosDetalle']) || !is_array($stats['correosInvalidosDetalle'])) {
            $stats['correosInvalidosDetalle'] = [];
        }

        if (count($stats['correosInvalidosDetalle']) >= self::MAX_CHANGE_DETAILS) {
            return;
        }

        $stats['correosInvalidosDetalle'][] = [
            'dni' => PersonalNormalizer::text($dni) ?: 'Sin DNI',
            'nombre' => PersonalNormalizer::text($nombre) ?: '-',
            'correo' => $correo,
            'motivo' => 'Formato de correo invalido',
        ];
    }

    private function registerBlockedActivation(array &$stats, mixed $dni, mixed $nombre, string $before, string $after): void
    {
        if (!isset($stats['activacionesBloqueadas'])) {
            $stats['activacionesBloqueadas'] = 0;
        }

        if (!isset($stats['activacionesBloqueadasDetalle']) || !is_array($stats['activacionesBloqueadasDetalle'])) {
            $stats['activacionesBloqueadasDetalle'] = [];
        }

        $stats['activacionesBloqueadas']++;

        if (count($stats['activacionesBloqueadasDetalle']) >= self::MAX_CHANGE_DETAILS) {
            return;
        }

        $stats['activacionesBloqueadasDetalle'][] = [
            'dni' => PersonalNormalizer::text($dni) ?: 'Sin DNI',
            'nombre' => PersonalNormalizer::text($nombre) ?: '-',
            'antes' => $before,
            'despues' => $after,
            'motivo' => 'El trabajador no fue activado porque no tiene contrato firmado vigente.',
        ];
    }

    private function registerFieldChange(array &$workerChanges, array &$stats, string $key, string $label, mixed $before, mixed $after): void
    {
        $beforeText = $this->formatChangeValue($before);
        $afterText = $this->formatChangeValue($after);

        if ($beforeText === $afterText) {
            return;
        }

        $workerChanges[] = [
            'campo' => $key,
            'label' => $label,
            'antes' => $beforeText,
            'despues' => $afterText,
        ];

        $stats['camposActualizados']++;
    }

    private function formatChangeValue(mixed $value): string
    {
        if ($value === null) {
            return '-';
        }

        $text = trim((string) $value);

        return $text === '' ? '-' : $text;
    }

    private function detectMineColumns(array $headers, array $rows, array $coreColumns): array
    {
        $fixedHeaders = array_flip(array_map(
            fn (string $item) => PersonalNormalizer::normalizeKey($item),
            self::FIXED_HEADERS
        ));

        $coreIndexSet = array_flip(array_values(array_filter($coreColumns, static fn ($value) => is_int($value))));
        $mineColumns = [];
        $seenMineKeys = [];

        foreach ($headers as $index => $header) {
            $label = PersonalNormalizer::text($header);
            if ($label === '') {
                continue;
            }

            if (isset($coreIndexSet[$index])) {
                continue;
            }

            if (isset($fixedHeaders[PersonalNormalizer::normalizeKey($label)])) {
                continue;
            }

            if (!$this->looksLikeMineColumn($rows, $index)) {
                continue;
            }

            $mineKey = PersonalNormalizer::normalizeKey($label);
            if ($mineKey === '' || isset($seenMineKeys[$mineKey])) {
                continue;
            }

            $seenMineKeys[$mineKey] = true;

            $mineColumns[] = [
                'indice' => $index,
                'nombre' => $label,
            ];
        }

        return $mineColumns;
    }

    private function looksLikeMineColumn(array $rows, int $index): bool
    {
        $sample = array_slice($rows, 0, 80);
        $filled = 0;
        $validStatuses = 0;

        foreach ($sample as $row) {
            $value = PersonalNormalizer::text($row[$index] ?? null);
            if ($value === '') {
                continue;
            }

            $filled++;
            if (PersonalNormalizer::mineStatus($value) !== null) {
                $validStatuses++;
            }
        }

        if ($filled === 0) {
            return false;
        }

        return $validStatuses > 0 && ($validStatuses / $filled) >= 0.2;
    }

    private function syncMines(array $detectedColumns): array
    {
        $created = 0;
        $reused = 0;
        $updated = 0;

        $dbMines = Mina::query()->get(['id', 'nombre', 'unidad_minera', 'estado']);

        $byKey = [];
        foreach ($dbMines as $mine) {
            $byKey[PersonalNormalizer::normalizeKey((string) $mine->unidad_minera)] = $mine;
            $byKey[PersonalNormalizer::normalizeKey((string) $mine->nombre)] = $mine;
        }

        $excelKeys = [];
        $map = [];

        foreach ($detectedColumns as $detected) {
            $name = $detected['nombre'];
            $key = PersonalNormalizer::normalizeKey($name);
            if ($key === '') {
                continue;
            }

            $excelKeys[$key] = true;

            $mine = $byKey[$key] ?? null;
            if ($mine) {
                $reused++;

                $nextData = [
                    'nombre' => $name,
                    'unidad_minera' => $name,
                    'estado' => 'ACTIVO',
                ];

                $needsUpdate = (string) $mine->nombre !== $nextData['nombre']
                    || (string) $mine->unidad_minera !== $nextData['unidad_minera']
                    || strtoupper((string) $mine->estado) !== $nextData['estado'];

                if ($needsUpdate) {
                    $mine->fill($nextData)->save();
                    $updated++;
                }
            } else {
                $mine = Mina::query()->create([
                    'id' => (string) Str::uuid(),
                    'nombre' => $name,
                    'unidad_minera' => $name,
                    'ubicacion' => 'Por definir',
                    'estado' => 'ACTIVO',
                ]);

                $created++;
            }

            $map[] = [
                'id' => $mine->id,
                'nombre' => $mine->nombre,
                'indice' => $detected['indice'],
            ];
        }

        foreach ($dbMines as $mine) {
            $key = PersonalNormalizer::normalizeKey((string) ($mine->unidad_minera ?: $mine->nombre));
            if ($key !== '' && !isset($excelKeys[$key])) {
                if (strtoupper((string) $mine->estado) !== 'INACTIVO') {
                    $mine->update(['estado' => 'INACTIVO']);
                }
            }
        }

        return [
            'map' => $map,
            'stats' => [
                'creadas' => $created,
                'reutilizadas' => $reused,
                'actualizadas' => $updated,
            ],
        ];
    }

    private function syncMineStatuses(Personal $personal, array $row, array $mineMap, array &$stats, array &$workerChanges = []): void
    {
        foreach ($mineMap as $mine) {
            $cellValue = $row[$mine['indice']] ?? null;
            $cellStatus = PersonalNormalizer::mineStatus($cellValue);

            $relation = PersonalMina::query()
                ->where('personal_id', $personal->id)
                ->where('mina_id', $mine['id'])
                ->first();

            $beforeStatus = $relation?->estado;

            if ($cellStatus === null || $cellStatus === 'NO_HABILITADO') {
                $deleted = PersonalMina::query()
                    ->where('personal_id', $personal->id)
                    ->where('mina_id', $mine['id'])
                    ->delete();

                $stats['relacionesMinaEliminadas'] += $deleted;

                if ($deleted > 0) {
                    $this->registerFieldChange(
                        $workerChanges,
                        $stats,
                        'mina_' . $mine['id'],
                        'Mina ' . $mine['nombre'],
                        PersonalNormalizer::mineStatusLabel($beforeStatus),
                        'Sin relación'
                    );
                }

                continue;
            }

            if ($relation) {
                if ($relation->estado !== $cellStatus) {
                    $relation->estado = $cellStatus;
                    $relation->save();
                    $stats['relacionesMinaActualizadas']++;
                    $stats['relacionesMinaCreadasOActualizadas']++;
                    $this->registerFieldChange(
                        $workerChanges,
                        $stats,
                        'mina_' . $mine['id'],
                        'Mina ' . $mine['nombre'],
                        PersonalNormalizer::mineStatusLabel($beforeStatus),
                        PersonalNormalizer::mineStatusLabel($cellStatus)
                    );
                }
            } else {
                PersonalMina::query()->create([
                    'id' => (string) Str::uuid(),
                    'personal_id' => $personal->id,
                    'mina_id' => $mine['id'],
                    'estado' => $cellStatus,
                ]);

                $stats['relacionesMinaCreadas']++;
                $stats['relacionesMinaCreadasOActualizadas']++;
                $this->registerFieldChange(
                    $workerChanges,
                    $stats,
                    'mina_' . $mine['id'],
                    'Mina ' . $mine['nombre'],
                    'Sin relación',
                    PersonalNormalizer::mineStatusLabel($cellStatus)
                );
            }
        }
    }
}
