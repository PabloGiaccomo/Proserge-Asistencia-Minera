<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Asistencia\Controllers\AsistenciaController;
use App\Modules\Dashboard\Controllers\DashboardController;
use App\Modules\System\Controllers\HealthController;
use App\Modules\System\Controllers\ScopeCheckController;
use App\Modules\Catalogos\Controllers\MinaController;
use App\Modules\Catalogos\Controllers\OficinaController;
use App\Modules\Catalogos\Controllers\ParaderoController;
use App\Modules\Catalogos\Controllers\TallerController;
use App\Modules\Faltas\Controllers\FaltasController;
use App\Modules\Evaluaciones\Controllers\EvaluacionDesempenoController;
use App\Modules\Evaluaciones\Controllers\EvaluacionSupervisorController;
use App\Modules\ManPower\Controllers\ManPowerController;
use App\Modules\RQMina\Controllers\RQMinaController;
use App\Modules\RQProserge\Controllers\RQProsergeController;
use App\Modules\Personal\Controllers\PersonalController;
use App\Modules\Seguridad\Controllers\PermisoController;
use App\Modules\Seguridad\Controllers\RolController;
use App\Modules\Seguridad\Controllers\UsuarioController;
use App\Modules\Seguridad\Controllers\UsuarioMinaScopeController;
use App\Modules\Notificaciones\Controllers\NotificacionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::middleware('auth.token')->get('/me', [AuthController::class, 'me']);
    });

    Route::get('/catalogos/minas', [MinaController::class, 'index']);
    Route::get('/catalogos/minas/{id}', [MinaController::class, 'show']);
    Route::post('/catalogos/minas', [MinaController::class, 'store']);
    Route::put('/catalogos/minas/{id}', [MinaController::class, 'update']);
    Route::post('/catalogos/minas/{id}/inactivar', [MinaController::class, 'inactivate']);
    Route::get('/catalogos/talleres', [TallerController::class, 'index']);
    Route::get('/catalogos/oficinas', [OficinaController::class, 'index']);
    Route::get('/catalogos/paraderos', [ParaderoController::class, 'index']);

    Route::middleware('auth.token')->group(function (): void {
        Route::get('/rq-mina', [RQMinaController::class, 'index']);
        Route::get('/rq-mina/{id}', [RQMinaController::class, 'show']);
        Route::post('/rq-mina', [RQMinaController::class, 'store']);
        Route::put('/rq-mina/{id}', [RQMinaController::class, 'update']);
        Route::post('/rq-mina/{id}/enviar', [RQMinaController::class, 'enviar']);

        Route::get('/rq-proserge', [RQProsergeController::class, 'index']);
        Route::get('/rq-proserge/{id}', [RQProsergeController::class, 'show']);
        Route::post('/rq-proserge', [RQProsergeController::class, 'store']);
        Route::put('/rq-proserge/{id}', [RQProsergeController::class, 'update']);
        Route::post('/rq-proserge/{id}/asignar', [RQProsergeController::class, 'asignar']);
        Route::post('/rq-proserge/{id}/desasignar', [RQProsergeController::class, 'desasignar']);
        Route::get('/rq-proserge/{id}/disponibles', [RQProsergeController::class, 'disponibles']);

        Route::get('/man-power/paradas', [ManPowerController::class, 'paradas']);
        Route::get('/man-power/paradas/{rqMinaId}', [ManPowerController::class, 'paradaDetalle']);
        Route::post('/man-power/grupos', [ManPowerController::class, 'storeGrupo']);
        Route::put('/man-power/grupos/{id}', [ManPowerController::class, 'updateGrupo']);
        Route::post('/man-power/grupos/{id}/agregar-personal', [ManPowerController::class, 'agregarPersonal']);
        Route::post('/man-power/grupos/{id}/quitar-personal', [ManPowerController::class, 'quitarPersonal']);
        Route::get('/man-power/grupos/{id}', [ManPowerController::class, 'showGrupo']);

        Route::get('/asistencia/grupos', [AsistenciaController::class, 'grupos']);
        Route::get('/asistencia/grupos/{grupoId}', [AsistenciaController::class, 'showGrupo']);
        Route::post('/asistencia/grupos/{grupoId}/marcar', [AsistenciaController::class, 'marcar']);
        Route::post('/asistencia/grupos/{grupoId}/marcar-masivo', [AsistenciaController::class, 'marcarMasivo']);
        Route::post('/asistencia/grupos/{grupoId}/cerrar', [AsistenciaController::class, 'cerrar']);
        Route::post('/asistencia/grupos/{grupoId}/reabrir', [AsistenciaController::class, 'reabrir']);

        Route::get('/faltas', [FaltasController::class, 'index']);
        Route::get('/faltas/{id}', [FaltasController::class, 'show']);
        Route::put('/faltas/{id}', [FaltasController::class, 'update']);
        Route::post('/faltas/{id}/corregir-asistencia', [FaltasController::class, 'corregirAsistencia']);
        Route::post('/faltas/{id}/anular', [FaltasController::class, 'anular']);

        Route::get('/evaluaciones/desempeno', [EvaluacionDesempenoController::class, 'index']);
        Route::get('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoController::class, 'show']);
        Route::post('/evaluaciones/desempeno', [EvaluacionDesempenoController::class, 'store']);
        Route::put('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoController::class, 'update']);
        Route::get('/evaluaciones/promedios', [EvaluacionDesempenoController::class, 'promedios']);
        Route::get('/evaluaciones/comparacion', [EvaluacionDesempenoController::class, 'comparacion']);
        Route::get('/evaluaciones/supervisor/plantilla', [EvaluacionSupervisorController::class, 'plantilla']);
        Route::post('/evaluaciones/supervisor/calcular', [EvaluacionSupervisorController::class, 'calcular']);
        Route::get('/evaluaciones/supervisor', [EvaluacionSupervisorController::class, 'index']);
        Route::get('/evaluaciones/supervisor/{id}', [EvaluacionSupervisorController::class, 'show']);
        Route::post('/evaluaciones/supervisor', [EvaluacionSupervisorController::class, 'store']);
        Route::put('/evaluaciones/supervisor/{id}', [EvaluacionSupervisorController::class, 'update']);
        Route::post('/evaluaciones/residente', [EvaluacionDesempenoController::class, 'storeResidente']);

        Route::get('/dashboard/principal', [DashboardController::class, 'principal']);
        Route::get('/dashboard/resumen', [DashboardController::class, 'resumen']);
        Route::get('/dashboard/rq-mina', [DashboardController::class, 'rqMina']);
        Route::get('/dashboard/rq-proserge', [DashboardController::class, 'rqProserge']);
        Route::get('/dashboard/man-power', [DashboardController::class, 'manPower']);
        Route::get('/dashboard/asistencia', [DashboardController::class, 'asistencia']);
        Route::get('/dashboard/faltas', [DashboardController::class, 'faltas']);
        Route::get('/dashboard/evaluaciones', [DashboardController::class, 'evaluaciones']);
        Route::get('/dashboard/alertas', [DashboardController::class, 'alertas']);

        Route::get('/seguridad/usuarios', [UsuarioController::class, 'index']);
        Route::get('/seguridad/roles', [RolController::class, 'index']);
        Route::get('/seguridad/permisos', [PermisoController::class, 'index']);
        Route::get('/seguridad/usuarios/{usuarioId}/mina-scope', [UsuarioMinaScopeController::class, 'index']);
        Route::put('/seguridad/usuarios/{usuarioId}/mina-scope', [UsuarioMinaScopeController::class, 'sync']);

        Route::get('/personal', [PersonalController::class, 'index']);
        Route::post('/personal', [PersonalController::class, 'store']);
        Route::put('/personal/{id}', [PersonalController::class, 'update']);
        Route::post('/personal/importar', [PersonalController::class, 'importar']);
        Route::get('/personal/exportar', [PersonalController::class, 'exportar']);

        Route::get('/notificaciones', [NotificacionController::class, 'index']);
        Route::get('/notificaciones/no-leidas/count', [NotificacionController::class, 'unreadCount']);
        Route::post('/notificaciones/marcar-todas-leidas', [NotificacionController::class, 'markAllRead']);
        Route::post('/notificaciones/{recipientId}/leer', [NotificacionController::class, 'markRead']);
        Route::post('/notificaciones/{recipientId}/archivar', [NotificacionController::class, 'archive']);

        Route::get('/seguridad/scope-check', ScopeCheckController::class)->middleware('mina.scope');
    });
});
