<?php

use App\Modules\Auth\Controllers\LoginPageController;
use App\Modules\Evaluaciones\Controllers\EvaluacionSupervisorPageController;
use App\Modules\Evaluaciones\Controllers\EvaluacionDesempenoPageController;
use App\Modules\Personal\Controllers\PersonalPageController;
use App\Modules\Personal\Controllers\PersonalImportController;
use App\Modules\MiAsistencia\Controllers\MiAsistenciaPageController;
use App\Modules\Bienestar\Controllers\BienestarPageController;
use App\Modules\ManPower\Controllers\ManPowerPageController;
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
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (session('auth_token')) {
        return redirect()->route('inicio');
    }
    return redirect()->route('login');
})->name('home');

Route::get('/login', [LoginPageController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginPageController::class, 'login'])->name('login.post');
Route::post('/logout', [LoginPageController::class, 'logout'])->name('logout');

Route::middleware('web.auth')->group(function (): void {
    // Inicio - Home principal
    Route::get('/inicio', [PersonalPageController::class, 'home'])->name('inicio');
    
    // Perfil
    Route::get('/perfil', [PerfilPageController::class, 'index'])->name('perfil.index');
    
    // Personal
    Route::get('/personal', [PersonalPageController::class, 'index'])->name('personal.index');
    Route::get('/personal/exportar', [PersonalPageController::class, 'exportForm'])->name('personal.export.form');
    Route::post('/personal/exportar', [PersonalPageController::class, 'exportDownload'])->name('personal.export.download');
    Route::get('/personal/crear', [PersonalPageController::class, 'create'])->name('personal.create');
    Route::post('/personal/crear', [PersonalPageController::class, 'store'])->name('personal.store');
    Route::get('/personal/{id}/editar', [PersonalPageController::class, 'edit'])->name('personal.edit');
    Route::put('/personal/{id}', [PersonalPageController::class, 'update'])->name('personal.update');
    Route::get('/personal/importar', [PersonalImportController::class, 'showImportForm'])->name('personal.importar');
    Route::post('/personal/importar', [PersonalImportController::class, 'import'])->name('personal.importar.post');
    Route::get('/personal/{id}', [PersonalPageController::class, 'show'])->name('personal.show');
    
    // Mi Asistencia
    Route::get('/mi-asistencia', [MiAsistenciaPageController::class, 'index'])->name('mi-asistencia.index');
    Route::get('/mi-asistencia/{id}', [MiAsistenciaPageController::class, 'show'])->name('mi-asistencia.show');
    
    // Man Power
    Route::get('/man-power', [ManPowerPageController::class, 'index'])->name('man-power.index');
    Route::get('/man-power/paradas', [ManPowerPageController::class, 'paradas'])->name('man-power.paradas');
    Route::get('/man-power/paradas/{rqMinaId}', [ManPowerPageController::class, 'paradaDetalle'])->name('man-power.parada-detalle');
    Route::get('/man-power/grupos/crear', [ManPowerPageController::class, 'crearGrupo'])->name('man-power.grupo-crear');
    Route::get('/man-power/grupos', [ManPowerPageController::class, 'grupos'])->name('man-power.grupos');
    Route::get('/man-power/grupos/{id}', [ManPowerPageController::class, 'grupoDetalle'])->name('man-power.grupo-detalle');

    // RQ Mina
    Route::get('/rq-mina', [RQMinaPageController::class, 'index'])->name('rq-mina.index');
    Route::get('/rq-mina/{id}', [RQMinaPageController::class, 'show'])->name('rq-mina.show');
    Route::get('/rq-mina/create', [RQMinaPageController::class, 'create'])->name('rq-mina.create');
    Route::get('/rq-mina/{id}/edit', [RQMinaPageController::class, 'edit'])->name('rq-mina.edit');

    // RQ Proserge
    Route::get('/rq-proserge', [RQProsergePageController::class, 'index'])->name('rq-proserge.index');
    Route::get('/rq-proserge/{id}', [RQProsergePageController::class, 'show'])->name('rq-proserge.show');
    Route::get('/rq-proserge/create', [RQProsergePageController::class, 'create'])->name('rq-proserge.create');
    Route::get('/rq-proserge/{id}/edit', [RQProsergePageController::class, 'edit'])->name('rq-proserge.edit');

    // Bienestar
    Route::get('/bienestar', [BienestarPageController::class, 'index'])->name('bienestar.index');
    Route::get('/bienestar/{id}', [BienestarPageController::class, 'show'])->name('bienestar.show');

    // Asistencia (main dashboard)
    Route::get('/asistencia', [AsistenciaPageController::class, 'index'])->name('asistencia.index');
    Route::get('/asistencia/resumen', [AsistenciaPageController::class, 'resumen'])->name('asistencia.resumen');
    Route::get('/asistencia/mina', [AsistenciaPageController::class, 'mina'])->name('asistencia.mina');
    Route::get('/asistencia/parada', [AsistenciaPageController::class, 'parada'])->name('asistencia.parada');
    Route::get('/asistencia/supervisor', [AsistenciaPageController::class, 'supervisor'])->name('asistencia.supervisor');
    Route::get('/asistencia/personal', [AsistenciaPageController::class, 'personal'])->name('asistencia.personal');
    Route::get('/asistencia/alertas', [AsistenciaPageController::class, 'alertas'])->name('asistencia.alertas');
    Route::get('/asistencia/grupos', [AsistenciaPageController::class, 'grupos'])->name('asistencia.grupos');
    Route::get('/asistencia/grupos/{grupoId}', [AsistenciaPageController::class, 'show'])->name('asistencia.show');
    Route::get('/asistencia/grupos/{grupoId}/marcar', [AsistenciaPageController::class, 'marcar'])->name('asistencia.marcar');
    Route::get('/asistencia/grupos/{grupoId}/masivo', [AsistenciaPageController::class, 'masivo'])->name('asistencia.masivo');

    // Faltas
    Route::get('/faltas', [FaltasPageController::class, 'index'])->name('faltas.index');
    Route::get('/faltas/{id}', [FaltasPageController::class, 'show'])->name('faltas.show');
    Route::get('/faltas/{id}/corregir', [FaltasPageController::class, 'corregir'])->name('faltas.corregir');

    // Evaluaciones
    Route::get('/evaluaciones', [EvaluacionDesempenoPageController::class, 'index'])->name('evaluaciones.index');
    Route::get('/evaluaciones/desempeno', [EvaluacionDesempenoPageController::class, 'index'])->name('evaluaciones.desempeno.index');
    Route::get('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoPageController::class, 'show'])->name('evaluaciones.desempeno.show');
    Route::get('/evaluaciones/desempeno/create', [EvaluacionDesempenoPageController::class, 'create'])->name('evaluaciones.desempeno.create');
    Route::get('/evaluaciones/desempeno/{id}/edit', [EvaluacionDesempenoPageController::class, 'edit'])->name('evaluaciones.desempeno.edit');
    Route::get('/evaluaciones/desempeno/promedios', [EvaluacionDesempenoPageController::class, 'promedios'])->name('evaluaciones.desempeno.promedios');
    Route::get('/evaluaciones/desempeno/comparacion', [EvaluacionDesempenoPageController::class, 'comparacion'])->name('evaluaciones.desempeno.comparacion');
    
    Route::get('/evaluaciones/supervisor', EvaluacionSupervisorPageController::class)->name('evaluaciones.supervisor');

    // Usuarios
    Route::get('/usuarios', [UsuarioPageController::class, 'index'])->name('usuarios.index');
    Route::get('/usuarios/crear', [UsuarioPageController::class, 'create'])->name('usuarios.create');
    Route::post('/usuarios', [UsuarioPageController::class, 'store'])->name('usuarios.store');
    Route::get('/usuarios/{id}', [UsuarioPageController::class, 'show'])->name('usuarios.show');
    Route::put('/usuarios/{id}', [UsuarioPageController::class, 'update'])->name('usuarios.update');
    Route::post('/usuarios/{id}/estado', [UsuarioPageController::class, 'toggleEstado'])->name('usuarios.toggle-estado');
    Route::post('/usuarios/{id}/password', [UsuarioPageController::class, 'updatePassword'])->name('usuarios.password');
    Route::get('/usuarios/{usuarioId}/scope', [UsuarioPageController::class, 'editarScope'])->name('usuarios.scope');

    // Catálogos - Hub
    Route::get('/catalogos', [CatalogoHubController::class, 'index'])->name('catalogos.index');
    Route::get('/catalogos/minas', [MinaPageController::class, 'index'])->name('catalogos.minas.index');
    Route::get('/catalogos/minas/crear', [MinaPageController::class, 'create'])->name('catalogos.minas.create');
    Route::post('/catalogos/minas', [MinaPageController::class, 'store'])->name('catalogos.minas.store');
    Route::get('/catalogos/minas/{id}', [MinaPageController::class, 'show'])->name('catalogos.minas.show');
    Route::get('/catalogos/minas/{id}/editar', [MinaPageController::class, 'edit'])->name('catalogos.minas.edit');
    Route::put('/catalogos/minas/{id}', [MinaPageController::class, 'update'])->name('catalogos.minas.update');
    Route::post('/catalogos/minas/{id}/inactivar', [MinaPageController::class, 'inactivate'])->name('catalogos.minas.inactivate');
    Route::get('/catalogos/talleres', [TallerPageController::class, 'index'])->name('catalogos.talleres.index');
    Route::get('/catalogos/talleres/crear', [TallerPageController::class, 'create'])->name('catalogos.talleres.create');
    Route::post('/catalogos/talleres', [TallerPageController::class, 'store'])->name('catalogos.talleres.store');
    Route::get('/catalogos/talleres/{id}', [TallerPageController::class, 'show'])->name('catalogos.talleres.show');
    Route::get('/catalogos/talleres/{id}/editar', [TallerPageController::class, 'edit'])->name('catalogos.talleres.edit');
    Route::put('/catalogos/talleres/{id}', [TallerPageController::class, 'update'])->name('catalogos.talleres.update');
    Route::post('/catalogos/talleres/{id}/eliminar', [TallerPageController::class, 'destroy'])->name('catalogos.talleres.destroy');
    Route::get('/catalogos/oficinas', [OficinaPageController::class, 'index'])->name('catalogos.oficinas.index');
    Route::get('/catalogos/oficinas/crear', [OficinaPageController::class, 'create'])->name('catalogos.oficinas.create');
    Route::post('/catalogos/oficinas', [OficinaPageController::class, 'store'])->name('catalogos.oficinas.store');
    Route::get('/catalogos/oficinas/{id}', [OficinaPageController::class, 'show'])->name('catalogos.oficinas.show');
    Route::get('/catalogos/oficinas/{id}/editar', [OficinaPageController::class, 'edit'])->name('catalogos.oficinas.edit');
    Route::put('/catalogos/oficinas/{id}', [OficinaPageController::class, 'update'])->name('catalogos.oficinas.update');
    Route::post('/catalogos/oficinas/{id}/eliminar', [OficinaPageController::class, 'destroy'])->name('catalogos.oficinas.destroy');
    Route::get('/catalogos/paraderos', [ParaderoPageController::class, 'index'])->name('catalogos.paraderos.index');
    Route::get('/catalogos/paraderos/{id}', [ParaderoPageController::class, 'show'])->name('catalogos.paraderos.show');

    // Roles y Permisos
    Route::get('/seguridad/roles', [RolPageController::class, 'index'])->name('seguridad.roles.index');
    Route::get('/seguridad/roles/crear', [RolPageController::class, 'create'])->name('seguridad.roles.create');
    Route::post('/seguridad/roles', [RolPageController::class, 'store'])->name('seguridad.roles.store');
    Route::get('/seguridad/roles/{id}', [RolPageController::class, 'show'])->name('seguridad.roles.show');
    Route::get('/seguridad/roles/{id}/editar', [RolPageController::class, 'edit'])->name('seguridad.roles.edit');
    Route::put('/seguridad/roles/{id}', [RolPageController::class, 'update'])->name('seguridad.roles.update');
    Route::post('/seguridad/roles/{id}/duplicar', [RolPageController::class, 'duplicate'])->name('seguridad.roles.duplicate');
    Route::post('/seguridad/roles/{id}/estado', [RolPageController::class, 'toggleEstado'])->name('seguridad.roles.toggle');
    Route::get('/seguridad/permisos', [PermisoPageController::class, 'index'])->name('seguridad.permisos.index');

    // POST Routes
    Route::post('/rq-mina', [RQMinaPageController::class, 'store'])->name('rq-mina.store');
    Route::put('/rq-mina/{id}', [RQMinaPageController::class, 'update'])->name('rq-mina.update');
    Route::post('/rq-mina/{id}/enviar', [RQMinaPageController::class, 'enviar'])->name('rq-mina.enviar');

    Route::post('/rq-proserge', [RQProsergePageController::class, 'store'])->name('rq-proserge.store');
    Route::put('/rq-proserge/{id}', [RQProsergePageController::class, 'update'])->name('rq-proserge.update');
    Route::post('/rq-proserge/{id}/asignar', [RQProsergePageController::class, 'asignar'])->name('rq-proserge.asignar');
    Route::post('/rq-proserge/{id}/desasignar', [RQProsergePageController::class, 'desasignar'])->name('rq-proserge.desasignar');

    Route::post('/man-power/grupos', [ManPowerPageController::class, 'storeGrupo'])->name('man-power.guardar-grupo');
    Route::put('/man-power/grupos/{id}', [ManPowerPageController::class, 'updateGrupo'])->name('man-power.actualizar-grupo');
    Route::post('/man-power/grupos/{id}/quitar-personal', [ManPowerPageController::class, 'quitarPersonal'])->name('man-power.quitar-personal');

    Route::post('/asistencia/grupos/{grupoId}/marcar', [AsistenciaPageController::class, 'marcarPost'])->name('asistencia.marcar-post');
    Route::post('/asistencia/grupos/{grupoId}/marcar-masivo', [AsistenciaPageController::class, 'marcarMasivoPost'])->name('asistencia.marcar-masivo-post');
    Route::post('/asistencia/grupos/{grupoId}/cerrar', [AsistenciaPageController::class, 'cerrar'])->name('asistencia.cerrar');
    Route::post('/asistencia/grupos/{grupoId}/reabrir', [AsistenciaPageController::class, 'reabrir'])->name('asistencia.reabrir');

    Route::post('/faltas/{id}/corregir-asistencia', [FaltasPageController::class, 'corregirPost'])->name('faltas.corregir-post');
    Route::post('/faltas/{id}/anular', [FaltasPageController::class, 'anular'])->name('faltas.anular');

    Route::post('/evaluaciones/desempeno', [EvaluacionDesempenoPageController::class, 'store'])->name('evaluaciones.desempeno.store');
    Route::put('/evaluaciones/desempeno/{id}', [EvaluacionDesempenoPageController::class, 'update'])->name('evaluaciones.desempeno.update');

    Route::put('/usuarios/{usuarioId}/mina-scope', [UsuarioPageController::class, 'syncScope'])->name('usuarios.scope-update');
});
