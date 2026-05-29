<?php

use App\Modules\Auth\Controllers\LoginPageController;
use App\Modules\Evaluaciones\Controllers\EvaluacionSupervisorPageController;
use App\Modules\Evaluaciones\Controllers\EvaluacionDesempenoPageController;
use App\Modules\Personal\Controllers\PersonalPageController;
use App\Modules\Personal\Controllers\PersonalImportController;
use App\Modules\Personal\Controllers\PersonalDocumentoController;
use App\Modules\Personal\Controllers\PersonalFichaController;
use App\Modules\Personal\Controllers\PublicPersonalFichaController;
use App\Modules\MiAsistencia\Controllers\MiAsistenciaPageController;
use App\Modules\Bienestar\Controllers\BienestarPageController;
use App\Modules\ManPower\Controllers\ManPowerPageController;
use App\Modules\ParadaHerramientas\Controllers\ParadaHerramientaPageController;
use App\Modules\RQMina\Controllers\RQMinaPageController;
use App\Modules\RQProserge\Controllers\RQProsergePageController;
use App\Modules\Asistencia\Controllers\AsistenciaPageController;
use App\Modules\Faltas\Controllers\FaltasPageController;
use App\Modules\Seguridad\Controllers\UsuarioPageController;
use App\Modules\Seguridad\Controllers\RolPageController;
use App\Modules\Seguridad\Controllers\PermisoPageController;
use App\Modules\Catalogos\Controllers\CatalogoHubController;
use App\Modules\Catalogos\Controllers\MinaPageController;
use App\Modules\Catalogos\Controllers\TallerPageController;
use App\Modules\Catalogos\Controllers\OficinaPageController;
use App\Modules\Catalogos\Controllers\ParaderoPageController;
use App\Modules\Perfil\Controllers\PerfilPageController;
use App\Modules\Notificaciones\Controllers\NotificacionPageController;
use Illuminate\Support\Facades\Route;

Route::get('/favicon.ico', function () {
    foreach ([public_path('favicon.ico'), public_path('img/LogoProserge.png'), base_path('img/LogoProserge.png')] as $path) {
        if (is_file($path) && filesize($path) > 0) {
            return response()->file($path, [
                'Cache-Control' => 'public, max-age=604800',
            ]);
        }
    }

    abort(404);
})->name('favicon');

Route::get('/img/LogoProserge.png', function () {
    foreach ([public_path('img/LogoProserge.png'), base_path('img/LogoProserge.png')] as $path) {
        if (is_file($path) && filesize($path) > 0) {
            return response()->file($path, [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=604800',
            ]);
        }
    }

    abort(404);
})->name('logo-proserge');

Route::get('/', function () {
    if (session('auth_token')) {
        return redirect()->route('inicio');
    }
    return redirect()->route('login');
})->name('home');

Route::get('/login', [LoginPageController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginPageController::class, 'login'])->name('login.post');
Route::post('/logout', [LoginPageController::class, 'logout'])->name('logout');

Route::get('/ficha-colaborador/{token}', [PublicPersonalFichaController::class, 'show'])->name('ficha-colaborador.show');
Route::post('/ficha-colaborador/{token}', [PublicPersonalFichaController::class, 'submit'])->name('ficha-colaborador.submit');

Route::middleware('web.auth')->group(function (): void {
    // Inicio - Home principal
    Route::get('/inicio', [PersonalPageController::class, 'home'])->middleware('web.permission:inicio,ver')->name('inicio');
    
    // Perfil
    Route::get('/perfil', [PerfilPageController::class, 'index'])->middleware('web.permission:perfil,ver')->name('perfil.index');

    // Notificaciones
    Route::get('/notificaciones', [NotificacionPageController::class, 'index'])->middleware('web.permission:notificaciones,ver')->name('notificaciones.index');
    Route::get('/notificaciones/count', [NotificacionPageController::class, 'count'])->middleware('web.permission:notificaciones,ver')->name('notificaciones.count');
    Route::get('/notificaciones/poll', [NotificacionPageController::class, 'poll'])->middleware('web.permission:notificaciones,ver')->name('notificaciones.poll');
    Route::post('/notificaciones/marcar-todas-leidas', [NotificacionPageController::class, 'markAllRead'])->middleware('web.permission:notificaciones,actualizar')->name('notificaciones.mark-all-read');
    Route::post('/notificaciones/{recipientId}/leer', [NotificacionPageController::class, 'markRead'])->middleware('web.permission:notificaciones,actualizar')->name('notificaciones.mark-read');
    Route::post('/notificaciones/{recipientId}/archivar', [NotificacionPageController::class, 'archive'])->middleware('web.permission:notificaciones,actualizar')->name('notificaciones.archive');
    Route::get('/notificaciones/{recipientId}/accion', [NotificacionPageController::class, 'openAction'])->middleware('web.permission:notificaciones,ver')->name('notificaciones.action');
    
    // Personal
    Route::get('/personal', [PersonalPageController::class, 'index'])->middleware('web.permission:personal,ver')->name('personal.index');
    Route::get('/personal/exportar', [PersonalPageController::class, 'exportForm'])->middleware('web.permission:personal,exportar')->name('personal.export.form');
    Route::post('/personal/exportar', [PersonalPageController::class, 'exportDownload'])->middleware('web.permission:personal,exportar')->name('personal.export.download');
    Route::get('/personal/crear', [PersonalPageController::class, 'create'])->middleware('web.permission:personal,crear')->name('personal.create');
    Route::post('/personal/crear', [PersonalPageController::class, 'store'])->middleware('web.permission:personal,crear')->name('personal.store');
    Route::get('/personal/fichas/importar', [PersonalFichaController::class, 'importForm'])->middleware('web.permission:personal,importar')->name('personal.fichas.import');
    Route::post('/personal/fichas/importar', [PersonalFichaController::class, 'parseMacro'])->middleware('web.permission:personal,importar')->name('personal.fichas.parse');
    Route::post('/personal/fichas/generar-link', [PersonalFichaController::class, 'generateLink'])->middleware('web.permission:personal,crear')->name('personal.fichas.generate-link');
    Route::post('/personal/fichas/cancelar-importacion', [PersonalFichaController::class, 'cancelImport'])->middleware('web.permission:personal,importar')->name('personal.fichas.cancel-import');
    Route::get('/personal/fichas/temporales', [PersonalFichaController::class, 'temporales'])->middleware('web.permission:personal,ver')->name('personal.fichas.temporales');
    Route::post('/personal/fichas/correo-envio', [PersonalFichaController::class, 'updateEmailTemplate'])->middleware('web.permission:personal,editar')->name('personal.fichas.email-template.update');
    Route::post('/personal/fichas/enviar-correos-masivo', [PersonalFichaController::class, 'sendBulkTemporalEmails'])->middleware('web.permission:personal,editar')->name('personal.fichas.send-bulk-email');
    Route::post('/personal/fichas/ampliar-links-activos', [PersonalFichaController::class, 'extendBulkActiveLinks'])->middleware('web.permission:personal,editar')->name('personal.fichas.extend-bulk-active');
    Route::post('/personal/fichas/{id}/extender', [PersonalFichaController::class, 'extendTemporal'])->middleware('web.permission:personal,editar')->name('personal.fichas.extend');
    Route::post('/personal/fichas/{id}/enviar-correo', [PersonalFichaController::class, 'sendTemporalEmail'])->middleware('web.permission:personal,editar')->name('personal.fichas.send-email');
    Route::post('/personal/fichas/{id}/regularizar-link', [PersonalFichaController::class, 'regularizeLink'])->middleware('web.permission:personal,editar')->name('personal.fichas.regularize-link');
    Route::post('/personal/fichas/{id}/eliminar', [PersonalFichaController::class, 'destroyTemporal'])->middleware('web.permission:personal,eliminar')->name('personal.fichas.destroy');
    Route::get('/personal/fichas/{id}/revisar', [PersonalFichaController::class, 'review'])->middleware('web.permission:personal,ver')->name('personal.fichas.review');
    Route::get('/personal/fichas/archivos/{id}/descargar', [PersonalFichaController::class, 'downloadArchivo'])->middleware('web.permission:personal,ver')->name('personal.fichas.archivos.download');
    Route::post('/personal/fichas/{id}/aprobar', [PersonalFichaController::class, 'approve'])->middleware('web.permission:personal,aprobar')->name('personal.fichas.approve');
    Route::post('/personal/fichas/{id}/observar', [PersonalFichaController::class, 'observe'])->middleware('web.permission:personal,aprobar')->name('personal.fichas.observe');
    Route::get('/personal/fichas/{id}/pdf', [PersonalFichaController::class, 'pdf'])->middleware('web.permission:personal,exportar')->name('personal.fichas.pdf');
    Route::post('/personal/fichas/exportar/excel', [PersonalFichaController::class, 'exportExcel'])->middleware('web.permission:personal,exportar')->name('personal.fichas.export.excel');
    Route::post('/personal/fichas/exportar/pdf/iniciar', [PersonalFichaController::class, 'startPdfExport'])->middleware('web.permission:personal,exportar')->name('personal.fichas.export.pdf.start');
    Route::post('/personal/fichas/exportar/pdf/{jobId}/procesar', [PersonalFichaController::class, 'processPdfExport'])->middleware('web.permission:personal,exportar')->name('personal.fichas.export.pdf.process');
    Route::get('/personal/fichas/exportar/pdf/{jobId}/descargar', [PersonalFichaController::class, 'downloadPdfExport'])->middleware('web.permission:personal,exportar')->name('personal.fichas.export.pdf.download');
    Route::get('/personal/{id}/documentos', [PersonalDocumentoController::class, 'index'])->middleware('web.permission:personal,ver')->name('personal.documentos.index');
    Route::post('/personal/{id}/documentos', [PersonalDocumentoController::class, 'store'])->middleware('web.permission:personal,actualizar')->name('personal.documentos.store');
    Route::get('/personal/{id}/gestacion/{bloqueoId}/pdf', [PersonalDocumentoController::class, 'gestacionPdf'])->middleware('web.permission:personal,ver')->name('personal.documentos.gestacion.pdf');
    Route::get('/personal/{id}/editar', [PersonalPageController::class, 'edit'])->middleware('web.permission:personal,editar')->name('personal.edit');
    Route::put('/personal/{id}', [PersonalPageController::class, 'update'])->middleware('web.permission:personal,actualizar')->name('personal.update');
    Route::post('/personal/{id}/cesar', [PersonalPageController::class, 'cease'])->middleware('web.permission:personal,actualizar')->name('personal.cease');
    Route::post('/personal/{id}/eliminar', [PersonalPageController::class, 'destroy'])->middleware('web.permission:personal,eliminar')->name('personal.destroy');
    Route::get('/personal/importar', [PersonalImportController::class, 'showImportForm'])->middleware('web.permission:personal,importar')->name('personal.importar');
    Route::post('/personal/importar', [PersonalImportController::class, 'import'])->middleware('web.permission:personal,importar')->name('personal.importar.post');
    Route::get('/personal/{id}', [PersonalPageController::class, 'show'])->middleware('web.permission:personal,ver')->name('personal.show');
    
    // Mi Asistencia
    Route::get('/mi-asistencia', [MiAsistenciaPageController::class, 'index'])->middleware('web.permission:mi_asistencia,ver')->name('mi-asistencia.index');
    Route::get('/mi-asistencia/{id}', [MiAsistenciaPageController::class, 'show'])->middleware('web.permission:mi_asistencia,ver')->name('mi-asistencia.show');
    
    // Man Power
    Route::get('/man-power', [ManPowerPageController::class, 'index'])->middleware('web.permission:man_power,ver')->name('man-power.index');
    Route::get('/man-power/paradas', [ManPowerPageController::class, 'paradas'])->middleware('web.permission:man_power,ver')->name('man-power.paradas');
    Route::get('/man-power/paradas/{rqMinaId}', [ManPowerPageController::class, 'paradaDetalle'])->middleware('web.permission:man_power,ver')->name('man-power.parada-detalle');
    Route::get('/man-power/grupos/crear', [ManPowerPageController::class, 'crearGrupo'])->middleware('web.permission:man_power,crear')->name('man-power.grupo-crear');
    Route::get('/man-power/grupos', [ManPowerPageController::class, 'grupos'])->middleware('web.permission:man_power,ver')->name('man-power.grupos');
    Route::get('/man-power/grupos/{id}', [ManPowerPageController::class, 'grupoDetalle'])->middleware('web.permission:man_power,ver')->name('man-power.grupo-detalle');

    // Herramientas por parada
    Route::get('/herramientas-parada', [ParadaHerramientaPageController::class, 'index'])->middleware('web.permission:herramientas,ver')->name('herramientas-parada.index');
    Route::get('/herramientas-parada/{rqMinaId}', [ParadaHerramientaPageController::class, 'show'])->middleware('web.permission:herramientas,ver')->name('herramientas-parada.show');
    Route::post('/herramientas-parada/{rqMinaId}', [ParadaHerramientaPageController::class, 'save'])->middleware('web.permission:herramientas,actualizar')->name('herramientas-parada.save');
    Route::post('/herramientas-parada/{rqMinaId}/pedido', [ParadaHerramientaPageController::class, 'updatePedido'])->middleware('web.permission:herramientas,actualizar')->name('herramientas-parada.pedido');
    Route::post('/herramientas-parada/{rqMinaId}/enviar', [ParadaHerramientaPageController::class, 'enviar'])->middleware('web.permission:herramientas,actualizar')->name('herramientas-parada.enviar');
    Route::post('/herramientas-parada/{rqMinaId}/grupos/{grupoId}/recordatorio-supervisor', [ParadaHerramientaPageController::class, 'recordarSupervisor'])->middleware('web.permission:herramientas,actualizar')->name('herramientas-parada.recordar-supervisor');

    // RQ Mina
    Route::get('/rq-mina', [RQMinaPageController::class, 'index'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.index');
    Route::get('/rq-mina/personal/buscar', [RQMinaPageController::class, 'buscarPersonal'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.personal.buscar');
    Route::get('/rq-mina/opciones-campo', [RQMinaPageController::class, 'opcionesCampo'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.opciones-campo.index');
    Route::post('/rq-mina/opciones-campo', [RQMinaPageController::class, 'guardarOpcionCampo'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.opciones-campo.store');
    Route::delete('/rq-mina/opciones-campo/{optionId}', [RQMinaPageController::class, 'eliminarOpcionCampo'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.opciones-campo.destroy');
    Route::get('/rq-mina/create', [RQMinaPageController::class, 'create'])->middleware('web.permission:rq_mina,crear')->name('rq-mina.create');
    Route::get('/rq-mina/{id}/edit', [RQMinaPageController::class, 'edit'])->middleware('web.permission:rq_mina,editar')->name('rq-mina.edit');
    Route::get('/rq-mina/{id}/plan/importar', [RQMinaPageController::class, 'importarPlan'])->middleware('web.permission:rq_mina,editar')->name('rq-mina.plan.importar');
    Route::get('/rq-mina/{id}/plan', [RQMinaPageController::class, 'plan'])->middleware('web.permission:rq_mina,editar')->name('rq-mina.plan');
    Route::get('/rq-mina/{id}', [RQMinaPageController::class, 'show'])->middleware('web.permission:rq_mina,ver')->name('rq-mina.show');

    // RQ Proserge
    Route::get('/rq-proserge', [RQProsergePageController::class, 'index'])->middleware('web.permission:rq_proserge,ver')->name('rq-proserge.index');
    Route::get('/rq-proserge/create', [RQProsergePageController::class, 'create'])->middleware('web.permission:rq_proserge,crear')->name('rq-proserge.create');
    Route::get('/rq-proserge/{id}/edit', [RQProsergePageController::class, 'edit'])->middleware('web.permission:rq_proserge,editar')->name('rq-proserge.edit');
    Route::get('/rq-proserge/{id}', [RQProsergePageController::class, 'show'])->middleware('web.permission:rq_proserge,ver')->name('rq-proserge.show');

    // Bienestar
    Route::get('/bienestar', [BienestarPageController::class, 'index'])->middleware('web.permission:bienestar,ver')->name('bienestar.index');
    Route::get('/bienestar/bloqueos/nuevo', [BienestarPageController::class, 'createBloqueo'])->middleware('web.permission:bienestar,crear')->name('bienestar.bloqueos.create');
    Route::post('/bienestar/bloqueos', [BienestarPageController::class, 'storeBloqueoGeneral'])->middleware('web.permission:bienestar,crear')->name('bienestar.bloqueos.store-general');
    Route::get('/bienestar/bloqueos/{bloqueoId}/editar', [BienestarPageController::class, 'editBloqueo'])->middleware('web.permission:bienestar,editar')->name('bienestar.bloqueos.edit');
    Route::put('/bienestar/bloqueos/{bloqueoId}', [BienestarPageController::class, 'updateBloqueo'])->middleware('web.permission:bienestar,actualizar')->name('bienestar.bloqueos.update');
    Route::post('/bienestar/bloqueos/{bloqueoId}/anular', [BienestarPageController::class, 'anularBloqueo'])->middleware('web.permission:bienestar,actualizar')->name('bienestar.bloqueos.anular');
    Route::get('/bienestar/{id}', [BienestarPageController::class, 'show'])->middleware('web.permission:bienestar,ver')->name('bienestar.show');
    Route::post('/bienestar/{id}/bloqueos', [BienestarPageController::class, 'storeBloqueo'])->middleware('web.permission:bienestar,crear')->name('bienestar.bloqueos.store');

    // Asistencia (main dashboard)
    Route::get('/asistencia', [AsistenciaPageController::class, 'index'])->middleware('web.permission:asistencias,ver')->name('asistencia.index');
    Route::get('/asistencia/resumen', [AsistenciaPageController::class, 'resumen'])->middleware('web.permission:asistencias,ver')->name('asistencia.resumen');
    Route::get('/asistencia/mina', [AsistenciaPageController::class, 'mina'])->middleware('web.permission:asistencias,ver')->name('asistencia.mina');
    Route::get('/asistencia/parada', [AsistenciaPageController::class, 'parada'])->middleware('web.permission:asistencias,ver')->name('asistencia.parada');
    Route::get('/asistencia/supervisor', [AsistenciaPageController::class, 'supervisor'])->middleware('web.permission:asistencias,ver')->name('asistencia.supervisor');
    Route::get('/asistencia/personal', [AsistenciaPageController::class, 'personal'])->middleware('web.permission:asistencias,ver')->name('asistencia.personal');
    Route::get('/asistencia/alertas', [AsistenciaPageController::class, 'alertas'])->middleware('web.permission:asistencias,ver')->name('asistencia.alertas');
    Route::get('/asistencia/grupos', [AsistenciaPageController::class, 'grupos'])->middleware('web.permission:asistencias,ver')->name('asistencia.grupos');
    Route::get('/asistencia/grupos/{grupoId}', [AsistenciaPageController::class, 'show'])->middleware('web.permission:asistencias,ver')->name('asistencia.show');
    Route::get('/asistencia/grupos/{grupoId}/marcar', [AsistenciaPageController::class, 'marcar'])->middleware('web.permission:asistencias,editar')->name('asistencia.marcar');
    Route::get('/asistencia/grupos/{grupoId}/masivo', [AsistenciaPageController::class, 'masivo'])->middleware('web.permission:asistencias,editar')->name('asistencia.masivo');

    // Faltas
    Route::get('/faltas', [FaltasPageController::class, 'index'])->middleware('web.permission:faltas,ver')->name('faltas.index');
    Route::get('/faltas/{id}', [FaltasPageController::class, 'show'])->middleware('web.permission:faltas,ver')->name('faltas.show');
    Route::get('/faltas/{id}/corregir', [FaltasPageController::class, 'corregir'])->middleware('web.permission:faltas,editar')->name('faltas.corregir');

    // Evaluaciones
    Route::get('/evaluaciones', [EvaluacionDesempenoPageController::class, 'index'])->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.index');
    Route::get('/evaluaciones/desempeno', [EvaluacionDesempenoPageController::class, 'index'])->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.desempeno.index');
    Route::get('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoPageController::class, 'show'])->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.desempeno.show');
    Route::get('/evaluaciones/desempeno/create', [EvaluacionDesempenoPageController::class, 'create'])->middleware('web.permission:evaluaciones,crear')->name('evaluaciones.desempeno.create');
    Route::get('/evaluaciones/desempeno/{id}/edit', [EvaluacionDesempenoPageController::class, 'edit'])->middleware('web.permission:evaluaciones,editar')->name('evaluaciones.desempeno.edit');
    Route::get('/evaluaciones/desempeno/promedios', [EvaluacionDesempenoPageController::class, 'promedios'])->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.desempeno.promedios');
    Route::get('/evaluaciones/desempeno/comparacion', [EvaluacionDesempenoPageController::class, 'comparacion'])->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.desempeno.comparacion');
    
    Route::get('/evaluaciones/supervisor', EvaluacionSupervisorPageController::class)->middleware('web.permission:evaluaciones,ver')->name('evaluaciones.supervisor');

    // Usuarios
    Route::get('/usuarios', [UsuarioPageController::class, 'index'])->middleware('web.permission:usuarios,ver')->name('usuarios.index');
    Route::get('/usuarios/crear', [UsuarioPageController::class, 'create'])->middleware('web.permission:usuarios,crear')->name('usuarios.create');
    Route::post('/usuarios', [UsuarioPageController::class, 'store'])->middleware('web.permission:usuarios,crear')->name('usuarios.store');
    Route::get('/usuarios/{id}', [UsuarioPageController::class, 'show'])->middleware('web.permission:usuarios,ver')->name('usuarios.show');
    Route::put('/usuarios/{id}', [UsuarioPageController::class, 'update'])->middleware('web.permission:usuarios,actualizar')->name('usuarios.update');
    Route::post('/usuarios/{id}/estado', [UsuarioPageController::class, 'toggleEstado'])->middleware('web.permission:usuarios,administrar')->name('usuarios.toggle-estado');
    Route::post('/usuarios/{id}/password', [UsuarioPageController::class, 'updatePassword'])->middleware('web.permission:usuarios,administrar')->name('usuarios.password');
    Route::post('/usuarios/{id}/notificaciones', [UsuarioPageController::class, 'updateNotificationPreferences'])->middleware('web.permission:usuarios,administrar')->name('usuarios.notificaciones');
    Route::get('/usuarios/{usuarioId}/scope', [UsuarioPageController::class, 'editarScope'])->middleware('web.permission:usuarios,administrar')->name('usuarios.scope');

    // Catálogos - Hub
    Route::get('/catalogos', [CatalogoHubController::class, 'index'])->middleware('web.permission:catalogos,ver')->name('catalogos.index');
    Route::get('/catalogos/minas', [MinaPageController::class, 'index'])->middleware('web.permission:minas,ver')->name('catalogos.minas.index');
    Route::get('/catalogos/minas/crear', [MinaPageController::class, 'create'])->middleware('web.permission:minas,crear')->name('catalogos.minas.create');
    Route::post('/catalogos/minas', [MinaPageController::class, 'store'])->middleware('web.permission:minas,crear')->name('catalogos.minas.store');
    Route::get('/catalogos/minas/{id}', [MinaPageController::class, 'show'])->middleware('web.permission:minas,ver')->name('catalogos.minas.show');
    Route::get('/catalogos/minas/{id}/editar', [MinaPageController::class, 'edit'])->middleware('web.permission:minas,editar')->name('catalogos.minas.edit');
    Route::put('/catalogos/minas/{id}', [MinaPageController::class, 'update'])->middleware('web.permission:minas,actualizar')->name('catalogos.minas.update');
    Route::post('/catalogos/minas/{id}/inactivar', [MinaPageController::class, 'inactivate'])->middleware('web.permission:minas,eliminar')->name('catalogos.minas.inactivate');
    Route::get('/catalogos/talleres', [TallerPageController::class, 'index'])->middleware('web.permission:talleres,ver')->name('catalogos.talleres.index');
    Route::get('/catalogos/talleres/crear', [TallerPageController::class, 'create'])->middleware('web.permission:talleres,crear')->name('catalogos.talleres.create');
    Route::post('/catalogos/talleres', [TallerPageController::class, 'store'])->middleware('web.permission:talleres,crear')->name('catalogos.talleres.store');
    Route::get('/catalogos/talleres/{id}', [TallerPageController::class, 'show'])->middleware('web.permission:talleres,ver')->name('catalogos.talleres.show');
    Route::get('/catalogos/talleres/{id}/editar', [TallerPageController::class, 'edit'])->middleware('web.permission:talleres,editar')->name('catalogos.talleres.edit');
    Route::put('/catalogos/talleres/{id}', [TallerPageController::class, 'update'])->middleware('web.permission:talleres,actualizar')->name('catalogos.talleres.update');
    Route::post('/catalogos/talleres/{id}/eliminar', [TallerPageController::class, 'destroy'])->middleware('web.permission:talleres,eliminar')->name('catalogos.talleres.destroy');
    Route::get('/catalogos/oficinas', [OficinaPageController::class, 'index'])->middleware('web.permission:oficinas,ver')->name('catalogos.oficinas.index');
    Route::get('/catalogos/oficinas/crear', [OficinaPageController::class, 'create'])->middleware('web.permission:oficinas,crear')->name('catalogos.oficinas.create');
    Route::post('/catalogos/oficinas', [OficinaPageController::class, 'store'])->middleware('web.permission:oficinas,crear')->name('catalogos.oficinas.store');
    Route::get('/catalogos/oficinas/{id}', [OficinaPageController::class, 'show'])->middleware('web.permission:oficinas,ver')->name('catalogos.oficinas.show');
    Route::get('/catalogos/oficinas/{id}/editar', [OficinaPageController::class, 'edit'])->middleware('web.permission:oficinas,editar')->name('catalogos.oficinas.edit');
    Route::put('/catalogos/oficinas/{id}', [OficinaPageController::class, 'update'])->middleware('web.permission:oficinas,actualizar')->name('catalogos.oficinas.update');
    Route::post('/catalogos/oficinas/{id}/eliminar', [OficinaPageController::class, 'destroy'])->middleware('web.permission:oficinas,eliminar')->name('catalogos.oficinas.destroy');
    Route::get('/catalogos/paraderos', [ParaderoPageController::class, 'index'])->middleware('web.permission:minas,ver')->name('catalogos.paraderos.index');
    Route::get('/catalogos/paraderos/{id}', [ParaderoPageController::class, 'show'])->middleware('web.permission:minas,ver')->name('catalogos.paraderos.show');

    // Roles y Permisos
    Route::get('/seguridad/roles', [RolPageController::class, 'index'])->middleware('web.permission:roles,ver')->name('seguridad.roles.index');
    Route::get('/seguridad/roles/crear', [RolPageController::class, 'create'])->middleware('web.permission:roles,crear')->name('seguridad.roles.create');
    Route::post('/seguridad/roles', [RolPageController::class, 'store'])->middleware('web.permission:roles,crear')->name('seguridad.roles.store');
    Route::get('/seguridad/roles/{id}', [RolPageController::class, 'show'])->middleware('web.permission:roles,ver')->name('seguridad.roles.show');
    Route::get('/seguridad/roles/{id}/editar', [RolPageController::class, 'edit'])->middleware('web.permission:roles,editar')->name('seguridad.roles.edit');
    Route::put('/seguridad/roles/{id}', [RolPageController::class, 'update'])->middleware('web.permission:roles,actualizar')->name('seguridad.roles.update');
    Route::post('/seguridad/roles/{id}/duplicar', [RolPageController::class, 'duplicate'])->middleware('web.permission:roles,crear')->name('seguridad.roles.duplicate');
    Route::post('/seguridad/roles/{id}/estado', [RolPageController::class, 'toggleEstado'])->middleware('web.permission:roles,administrar')->name('seguridad.roles.toggle');
    Route::get('/seguridad/permisos', [PermisoPageController::class, 'index'])->middleware('web.permission:roles,ver')->name('seguridad.permisos.index');

    // POST Routes
    Route::post('/rq-mina', [RQMinaPageController::class, 'store'])->middleware('web.permission:rq_mina,crear')->name('rq-mina.store');
    Route::put('/rq-mina/{id}', [RQMinaPageController::class, 'update'])->middleware('web.permission:rq_mina,actualizar')->name('rq-mina.update');
    Route::put('/rq-mina/{id}/plan', [RQMinaPageController::class, 'updatePlan'])->middleware('web.permission:rq_mina,actualizar')->name('rq-mina.plan.update');
    Route::post('/rq-mina/{id}/enviar', [RQMinaPageController::class, 'enviar'])->middleware('web.permission:rq_mina,actualizar')->name('rq-mina.enviar');
    Route::post('/rq-mina/{id}/eliminar', [RQMinaPageController::class, 'destroy'])->middleware('web.permission:rq_mina,eliminar')->name('rq-mina.destroy');

    Route::post('/rq-proserge', [RQProsergePageController::class, 'store'])->middleware('web.permission:rq_proserge,crear')->name('rq-proserge.store');
    Route::put('/rq-proserge/{id}', [RQProsergePageController::class, 'update'])->middleware('web.permission:rq_proserge,actualizar')->name('rq-proserge.update');
    Route::post('/rq-proserge/{id}/asignar', [RQProsergePageController::class, 'asignar'])->middleware('web.permission:rq_proserge,asignar')->name('rq-proserge.asignar');
    Route::post('/rq-proserge/{id}/desasignar', [RQProsergePageController::class, 'desasignar'])->middleware('web.permission:rq_proserge,asignar')->name('rq-proserge.desasignar');

    Route::post('/man-power/grupos', [ManPowerPageController::class, 'storeGrupo'])->middleware('web.permission:man_power,crear')->name('man-power.guardar-grupo');
    Route::put('/man-power/grupos/{id}', [ManPowerPageController::class, 'updateGrupo'])->middleware('web.permission:man_power,actualizar')->name('man-power.actualizar-grupo');
    Route::post('/man-power/grupos/{id}/quitar-personal', [ManPowerPageController::class, 'quitarPersonal'])->middleware('web.permission:man_power,asignar')->name('man-power.quitar-personal');

    Route::post('/asistencia/grupos/{grupoId}/marcar', [AsistenciaPageController::class, 'marcarPost'])->middleware('web.permission:asistencias,actualizar')->name('asistencia.marcar-post');
    Route::post('/asistencia/grupos/{grupoId}/marcar-masivo', [AsistenciaPageController::class, 'marcarMasivoPost'])->middleware('web.permission:asistencias,actualizar')->name('asistencia.marcar-masivo-post');
    Route::post('/asistencia/grupos/{grupoId}/cerrar', [AsistenciaPageController::class, 'cerrar'])->middleware('web.permission:asistencias,cerrar')->name('asistencia.cerrar');
    Route::post('/asistencia/grupos/{grupoId}/reabrir', [AsistenciaPageController::class, 'reabrir'])->middleware('web.permission:asistencias,actualizar')->name('asistencia.reabrir');

    Route::post('/faltas/{id}/corregir-asistencia', [FaltasPageController::class, 'corregirPost'])->middleware('web.permission:faltas,actualizar')->name('faltas.corregir-post');
    Route::post('/faltas/{id}/anular', [FaltasPageController::class, 'anular'])->middleware('web.permission:faltas,eliminar')->name('faltas.anular');

    Route::post('/evaluaciones/desempeno', [EvaluacionDesempenoPageController::class, 'store'])->middleware('web.permission:evaluaciones,crear')->name('evaluaciones.desempeno.store');
    Route::put('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoPageController::class, 'update'])->middleware('web.permission:evaluaciones,actualizar')->name('evaluaciones.desempeno.update');

    Route::put('/usuarios/{usuarioId}/mina-scope', [UsuarioPageController::class, 'syncScope'])->middleware('web.permission:usuarios,administrar')->name('usuarios.scope-update');
});
