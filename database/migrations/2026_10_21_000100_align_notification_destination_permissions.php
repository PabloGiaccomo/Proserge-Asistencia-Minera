<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_types')) {
            return;
        }

        $destinations = [
            'habilitacion_mina_actualizada' => ['habilitacion_minera', 'habilitacion_minera', 'ver'],
            'habilitacion_mina_habilitado' => ['habilitacion_minera', 'habilitacion_minera', 'ver'],
            'habilitacion_mina_estado_critico' => ['habilitacion_minera', 'habilitacion_minera', 'ver'],
            'habilitacion_examen_programado' => ['habilitacion_minera', 'habilitacion_minera', 'ver'],
            'personal_contrato_firmado' => ['personal_contratos', 'personal', 'ver_contratos'],
            'contrato_no_renovado_cerrado' => ['personal_contratos', 'personal', 'ver_contratos'],
            'contrato_decision_renovacion' => ['vencimientos', 'vencimientos', 'ver'],
            'personal_ficha_aprobada_falta_contrato' => ['personal_contratos', 'personal', 'editar_datos_contrato'],
        ];

        DB::transaction(function () use ($destinations): void {
            foreach ($destinations as $code => [$module, $permissionModule, $action]) {
                DB::table('notification_types')->where('code', $code)->update([
                    'module' => $module,
                    'required_permission_module' => $permissionModule,
                    'required_permission_action' => $action,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Do not restore the unrelated grants that this security correction removes.
    }
};
