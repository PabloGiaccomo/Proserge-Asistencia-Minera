<?php

namespace Database\Seeders;

use App\Models\Personal;
use App\Models\PersonalContrato;
use App\Models\PersonalContratoDato;
use App\Models\PersonalFicha;
use App\Models\PersonalMina;
use App\Models\Mina;
use App\Modules\Personal\Services\PersonalService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TestPersonalSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(PersonalService::class);

        $minas = Mina::query()->get(['id', 'nombre']);
        $minaIds = $minas->pluck('id')->toArray();

        $workers = [
            // 1. ACTIVO - trabajador activo con contrato firmado y vigente
            [
                'nombre_completo' => 'GARCIA LOPEZ JUAN CARLOS',
                'dni' => '45123678',
                'puesto' => 'OPERARIO MINERO',
                'ocupacion' => 'Operario',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => false,
                'fecha_ingreso' => '2024-03-15',
                'correo' => 'juan.garcia@proserge.com',
                'telefono_1' => '951123456',
                'target_estado' => 'ACTIVO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2024-03-15',
                    'fecha_fin_contrato' => '2026-03-15',
                    'sueldo_num' => '3500',
                    'puesto' => 'OPERARIO MINERO',
                    'funciones' => 'Operaciones mineras generales, perforacion y voladura.',
                ],
                'contrato_estado' => 'ACTIVO',
                'contrato_tipo' => 'PERMANENTE',
                'minas' => array_slice($minaIds, 0, 2),
            ],

            // 2. ACTIVO SUPERVISOR - supervisor activo
            [
                'nombre_completo' => 'MAMANI QUISPE PEDRO ANTONIO',
                'dni' => '41234567',
                'puesto' => 'SUPERVISOR DE OPERACIONES',
                'ocupacion' => 'Supervisor',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => true,
                'fecha_ingreso' => '2023-06-01',
                'correo' => 'pedro.mamani@proserge.com',
                'telefono_1' => '951234567',
                'target_estado' => 'ACTIVO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2023-06-01',
                    'fecha_fin_contrato' => '2025-12-31',
                    'sueldo_num' => '5500',
                    'puesto' => 'SUPERVISOR DE OPERACIONES',
                    'funciones' => 'Supervision de cuadrillas, control de asistencia y seguridad.',
                ],
                'contrato_estado' => 'ACTIVO',
                'contrato_tipo' => 'PERMANENTE',
                'minas' => array_slice($minaIds, 0, 1),
            ],

            // 3. ACTIVO - trabajador temporal activo
            [
                'nombre_completo' => 'HUAMAN TORRES MARIA ELENA',
                'dni' => '46321789',
                'puesto' => 'ASISTENTE ADMINISTRATIVO',
                'ocupacion' => 'Administrativo',
                'contrato' => 'TEMPORAL',
                'es_supervisor' => false,
                'fecha_ingreso' => '2025-08-01',
                'correo' => 'maria.huaman@proserge.com',
                'telefono_1' => '951345678',
                'target_estado' => 'ACTIVO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2025-08-01',
                    'fecha_fin_contrato' => '2025-11-30',
                    'sueldo_num' => '2800',
                    'puesto' => 'ASISTENTE ADMINISTRATIVO',
                    'funciones' => 'Control documental, archivo y seguimiento de fichas.',
                ],
                'contrato_estado' => 'ACTIVO',
                'contrato_tipo' => 'TEMPORAL',
                'minas' => [],
            ],

            // 4. FALTA_CONTRATO - trabajador sin contrato
            [
                'nombre_completo' => 'CCAPA HUAQUI RICARDO ANDRES',
                'dni' => '47890123',
                'puesto' => 'OPERARIO DE MAQUINARIA',
                'ocupacion' => 'Operario',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => null,
                'telefono_1' => '951456789',
                'target_estado' => 'FALTA_CONTRATO',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => array_slice($minaIds, 1, 1),
            ],

            // 5. NO_FIRMO_CONTRATO - tiene contrato pero no lo firmo
            [
                'nombre_completo' => 'RAMOS FLORES CLAUDIA PATRICIA',
                'dni' => '43456789',
                'puesto' => 'TECNICO DE SEGURIDAD',
                'ocupacion' => 'Tecnico',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => false,
                'fecha_ingreso' => '2025-07-15',
                'correo' => 'claudia.ramos@proserge.com',
                'telefono_1' => '951567890',
                'target_estado' => 'NO_FIRMO_CONTRATO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2025-07-15',
                    'fecha_fin_contrato' => '2027-07-15',
                    'sueldo_num' => '4200',
                    'puesto' => 'TECNICO DE SEGURIDAD',
                    'funciones' => 'Supervision SSMA, control de EPP y capacitaciones.',
                ],
                'contrato_estado' => 'PREPARACION',
                'contrato_tipo' => 'PERMANENTE',
                'minas' => [],
            ],

            // 6. PENDIENTE_COMPLETAR_FICHA - nuevo trabajador pendiente
            [
                'nombre_completo' => 'TLASCOYA ORTIZ DIEGO FERNANDO',
                'dni' => '48901234',
                'puesto' => 'AYUDANTE GENERAL',
                'ocupacion' => 'Ayudante',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => null,
                'telefono_1' => '951678901',
                'target_estado' => 'PENDIENTE_COMPLETAR_FICHA',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],

            // 7. FICHA_ENVIADA - trabajador que envio su ficha
            [
                'nombre_completo' => 'SERRANO BUSTAMANTE ANA LUCIA',
                'dni' => '44567890',
                'puesto' => 'ENFERMERA INDUSTRIAL',
                'ocupacion' => 'Profesional',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => 'ana.serrano@proserge.com',
                'telefono_1' => '951789012',
                'target_estado' => 'FICHA_ENVIADA',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],

            // 8. OBSERVADO - trabajador con ficha observada
            [
                'nombre_completo' => 'CHAVEZ QUISPE LUIS ALBERTO',
                'dni' => '42678901',
                'puesto' => 'CHOFER DE TRANSPORTE',
                'ocupacion' => 'Conductor',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => 'luis.chavez@proserge.com',
                'telefono_1' => '951890123',
                'target_estado' => 'OBSERVADO',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],

            // 9. CESADO - trabajador cesado
            [
                'nombre_completo' => 'QUISPE HUANCA JORGE LUIS',
                'dni' => '39789012',
                'puesto' => 'OPERARIO DE PERFORACION',
                'ocupacion' => 'Operario',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => false,
                'fecha_ingreso' => '2022-01-10',
                'correo' => null,
                'telefono_1' => '951901234',
                'target_estado' => 'CESADO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2022-01-10',
                    'fecha_fin_contrato' => '2024-01-10',
                    'sueldo_num' => '3200',
                    'puesto' => 'OPERARIO DE PERFORACION',
                    'funciones' => 'Perforacion, voladura y trasporte de material.',
                ],
                'contrato_estado' => 'CESADO',
                'contrato_tipo' => 'PERMANENTE',
                'fecha_cese' => '2024-01-10',
                'motivo_cese' => 'FIN_DE_CONTRATO',
                'minas' => array_slice($minaIds, 0, 1),
            ],

            // 10. INACTIVO - trabajador inactivo
            [
                'nombre_completo' => 'TUPA MCCOY SANDRO VINICIO',
                'dni' => '40890123',
                'puesto' => 'TECNICO DE MANTENIMIENTO',
                'ocupacion' => 'Tecnico',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => '2023-09-01',
                'correo' => null,
                'telefono_1' => '951012345',
                'target_estado' => 'INACTIVO',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],

            // 11. ACTIVO - trabajador con contrato proximo a vencer
            [
                'nombre_completo' => 'FLORES MANRIQUE KARINA VANESSA',
                'dni' => '45901234',
                'puesto' => 'AUXILIAR CONTABLE',
                'ocupacion' => 'Contable',
                'contrato' => 'TEMPORAL',
                'es_supervisor' => false,
                'fecha_ingreso' => '2025-01-15',
                'correo' => 'karina.flores@proserge.com',
                'telefono_1' => '951123457',
                'target_estado' => 'ACTIVO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2025-01-15',
                    'fecha_fin_contrato' => '2025-09-30',
                    'sueldo_num' => '3800',
                    'puesto' => 'AUXILIAR CONTABLE',
                    'funciones' => 'Registro contable, conciliaciones bancarias y reportes.',
                ],
                'contrato_estado' => 'ACTIVO',
                'contrato_tipo' => 'TEMPORAL',
                'minas' => [],
            ],

            // 12. ACTIVO - trabajador de logistica
            [
                'nombre_completo' => 'APAZA condori SERGIO ROLANDO',
                'dni' => '44012345',
                'puesto' => 'OPERARIO DE LOGISTICA',
                'ocupacion' => 'Logistica',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => false,
                'fecha_ingreso' => '2024-06-01',
                'correo' => 'sergio.apaza@proserge.com',
                'telefono_1' => '951234568',
                'target_estado' => 'ACTIVO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2024-06-01',
                    'fecha_fin_contrato' => '2026-06-01',
                    'sueldo_num' => '3000',
                    'puesto' => 'OPERARIO DE LOGISTICA',
                    'funciones' => 'Control de inventario, despacho y recepcion de materiales.',
                ],
                'contrato_estado' => 'ACTIVO',
                'contrato_tipo' => 'PERMANENTE',
                'minas' => count($minaIds) > 2 ? array_slice($minaIds, 2, 1) : [],
            ],

            // 13. CESADO - cesado por renuncia
            [
                'nombre_completo' => 'YUCRA CCOLQQUE MIGUEL ANGEL',
                'dni' => '38123456',
                'puesto' => 'SUPERVISOR DE MINAS',
                'ocupacion' => 'Supervisor',
                'contrato' => 'PERMANENTE',
                'es_supervisor' => true,
                'fecha_ingreso' => '2021-04-01',
                'correo' => null,
                'telefono_1' => '951345679',
                'target_estado' => 'CESADO',
                'contrato_data' => [
                    'fecha_inicio_contrato' => '2021-04-01',
                    'fecha_fin_contrato' => '2023-04-01',
                    'sueldo_num' => '6000',
                    'puesto' => 'SUPERVISOR DE MINAS',
                    'funciones' => 'Supervision directa de operaciones mineras subterraneas.',
                ],
                'contrato_estado' => 'CESADO',
                'contrato_tipo' => 'PERMANENTE',
                'fecha_cese' => '2023-10-15',
                'motivo_cese' => 'RENUNCIA_VOLUNTARIA',
                'minas' => array_slice($minaIds, 0, 2),
            ],

            // 14. RECHAZADO - ficha rechazada
            [
                'nombre_completo' => 'PARI CAHUI FELIX ROLANDO',
                'dni' => '46789012',
                'puesto' => 'AYUDANTE DE MINAS',
                'ocupacion' => 'Ayudante',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => 'felix.pari@proserge.com',
                'telefono_1' => '951456780',
                'target_estado' => 'RECHAZADO',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],

            // 15. APROBADO - ficha aprobada, esperando contrato
            [
                'nombre_completo' => 'CONDORI MAMANI WILMA ROSARIO',
                'dni' => '47234567',
                'puesto' => 'CHOFER',
                'ocupacion' => 'Conductor',
                'contrato' => null,
                'es_supervisor' => false,
                'fecha_ingreso' => null,
                'correo' => 'wilma.condori@proserge.com',
                'telefono_1' => '951567891',
                'target_estado' => 'APROBADO',
                'contrato_data' => null,
                'contrato_estado' => null,
                'contrato_tipo' => null,
                'minas' => [],
            ],
        ];

        $created = 0;
        $skipped = 0;

        foreach ($workers as $workerData) {
            $existing = Personal::query()
                ->where('dni', $workerData['dni'])
                ->orWhere('numero_documento', $workerData['dni'])
                ->first();

            if ($existing) {
                $this->command?->info("  Omitido (ya existe): {$workerData['nombre_completo']} (DNI: {$workerData['dni']})");
                $skipped++;
                continue;
            }

            try {
                $personal = $service->create([
                    'nombre_completo' => $workerData['nombre_completo'],
                    'dni' => $workerData['dni'],
                    'numero_documento' => $workerData['dni'],
                    'puesto' => $workerData['puesto'],
                    'ocupacion' => $workerData['ocupacion'],
                    'contrato' => $workerData['contrato'],
                    'es_supervisor' => $workerData['es_supervisor'],
                    'fecha_ingreso' => $workerData['fecha_ingreso'],
                    'telefono_1' => $workerData['telefono_1'],
                    'correo' => $workerData['correo'],
                    'origen_registro' => 'NUEVO',
                ]);

                $targetState = $workerData['target_estado'];

                // Crear contrato si aplica (ANTES de actualizar estado, para ACTIVO)
                if (!empty($workerData['contrato_data']) && !empty($workerData['contrato_estado'])) {
                    $this->createContratoForTest($personal, $workerData);
                }

                // Actualizar estado al deseado (despues de crear contrato si es necesario)
                $currentState = strtoupper((string) $personal->estado);

                if ($currentState !== $targetState) {
                    $updateData = ['estado' => $targetState];

                    if ($targetState === 'CESADO' && !empty($workerData['fecha_cese'])) {
                        $updateData['fecha_cese'] = $workerData['fecha_cese'];
                        $updateData['motivo_cese'] = $workerData['motivo_cese'] ?? 'OTRO';
                        $updateData['cesado_at'] = now();
                    }

                    $personal->forceFill($updateData)->save();
                    $personal = $personal->fresh();
                }

                // Crear ficha si aplica
                if (in_array($targetState, ['FICHA_ENVIADA', 'OBSERVADO', 'APROBADO', 'RECHAZADO'], true)) {
                    $fichaEstado = match ($targetState) {
                        'FICHA_ENVIADA' => PersonalFicha::ESTADO_ENVIADA,
                        'OBSERVADO' => PersonalFicha::ESTADO_OBSERVADO,
                        'APROBADO' => PersonalFicha::ESTADO_APROBADO,
                        'RECHAZADO' => PersonalFicha::ESTADO_RECHAZADO,
                        default => PersonalFicha::ESTADO_PENDIENTE,
                    };

                    $ficha = PersonalFicha::query()->create([
                        'id' => (string) Str::uuid(),
                        'personal_id' => $personal->id,
                        'estado' => $fichaEstado,
                        'tipo_documento' => 'DNI',
                        'numero_documento' => $workerData['dni'],
                        'datos_json' => $this->buildFichaData($workerData),
                    ]);

                    if ($targetState === 'FICHA_ENVIADA') {
                        $ficha->forceFill(['submitted_at' => now()])->save();
                    } elseif ($targetState === 'OBSERVADO') {
                        $ficha->forceFill([
                            'submitted_at' => now()->subDays(3),
                            'observed_at' => now()->subDay(),
                            'observaciones_revision' => 'Falta documentacion familiar. Adjuntar acta de nacimiento.',
                        ])->save();
                    } elseif ($targetState === 'APROBADO') {
                        $ficha->forceFill([
                            'submitted_at' => now()->subDays(5),
                            'approved_at' => now()->subDay(),
                        ])->save();
                    } elseif ($targetState === 'RECHAZADO') {
                        $ficha->forceFill([
                            'submitted_at' => now()->subDays(7),
                            'rejected_at' => now()->subDays(2),
                            'observaciones_revision' => 'Datos personales inconsistentes con DNI presentado.',
                        ])->save();
                    }
                }

                // Asignar minas si aplica
                if (!empty($workerData['minas'])) {
                    foreach ($workerData['minas'] as $minaId) {
                        PersonalMina::query()->firstOrCreate(
                            ['personal_id' => $personal->id, 'mina_id' => $minaId],
                            [
                                'id' => (string) Str::uuid(),
                                'estado' => 'EN_PROCESO',
                            ]
                        );
                    }
                }

                $created++;
                $this->command?->info("  Creado: {$workerData['nombre_completo']} (DNI: {$workerData['dni']}) -> Estado: {$targetState}");
            } catch (\Throwable $e) {
                $this->command?->error("  Error creando {$workerData['nombre_completo']}: {$e->getMessage()}");
            }
        }

        $this->command?->info('');
        $this->command?->info("Resumen: {$created} creados, {$skipped} omitidos de " . count($workers) . " definidos.");
    }

    private function createContratoForTest(Personal $personal, array $workerData): void
    {
        $contratoData = $workerData['contrato_data'];
        $contratoEstado = $workerData['contrato_estado'];

        $lastNumero = PersonalContrato::query()
            ->where('personal_id', $personal->id)
            ->max('contrato_numero') ?? 0;

        $snapshotData = [
            'nombre_completo' => $personal->nombre_completo,
            'dni' => $personal->dni,
            'puesto' => $contratoData['puesto'],
            'sueldo' => $contratoData['sueldo_num'],
            'tipo_contrato' => $workerData['contrato_tipo'] ?? 'PERMANENTE',
            'fecha_inicio' => $contratoData['fecha_inicio_contrato'],
            'fecha_fin' => $contratoData['fecha_fin_contrato'],
        ];

        $contrato = PersonalContrato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'contrato_numero' => $lastNumero + 1,
            'estado' => $contratoEstado,
            'tipo_contrato' => $workerData['contrato_tipo'] ?? 'PERMANENTE',
            'puesto' => $contratoData['puesto'],
            'es_supervisor' => $personal->es_supervisor,
            'fecha_inicio' => $contratoData['fecha_inicio_contrato'],
            'fecha_fin' => $contratoData['fecha_fin_contrato'],
            'es_historico' => in_array($contratoEstado, ['CESADO', 'CERRADO', 'NO_RENOVADO', 'ANULADO'], true),
            'snapshot_inicial_json' => $snapshotData,
            'snapshot_json' => $snapshotData,
        ]);

        if ($contratoEstado === 'ACTIVO') {
            $contrato->forceFill([
                'activado_at' => now(),
            ])->save();
        } elseif ($contratoEstado === 'CESADO') {
            $contrato->forceFill([
                'cerrado_at' => now(),
                'motivo_cese' => $workerData['motivo_cese'] ?? 'FIN_DE_CONTRATO',
            ])->save();
        }

        // Crear contrato datos
        PersonalContratoDato::query()->create([
            'id' => (string) Str::uuid(),
            'personal_id' => $personal->id,
            'fecha_inicio_contrato' => $contratoData['fecha_inicio_contrato'],
            'fecha_fin_contrato' => $contratoData['fecha_fin_contrato'],
            'sueldo_num' => $contratoData['sueldo_num'],
            'puesto' => $contratoData['puesto'],
            'funciones' => $contratoData['funciones'] ?? null,
        ]);
    }

    private function buildFichaData(array $workerData): array
    {
        return [
            'nombre_completo' => $workerData['nombre_completo'],
            'dni' => $workerData['dni'],
            'puesto' => $workerData['puesto'],
            'ocupacion' => $workerData['ocupacion'] ?? '',
            'correo' => $workerData['correo'] ?? '',
            'telefono' => $workerData['telefono_1'] ?? '',
            'fecha_ingreso' => $workerData['fecha_ingreso'] ?? '',
            'estado_civil' => 'SOLTERO',
            'direccion' => 'Av. Principal 123, Oruro',
            'fecha_nacimiento' => '1990-05-15',
            'sexo' => 'M',
            'grupo_sanguineo' => 'O+',
        ];
    }
}
