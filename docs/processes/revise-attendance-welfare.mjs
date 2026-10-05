import fs from 'node:fs';

const file = new URL('./process-catalog.json', import.meta.url);
const catalog = JSON.parse(fs.readFileSync(file, 'utf8'));
const attendance = catalog.modules.find((module) => module.slug === 'asistencia-faltas');
const welfare = catalog.modules.find((module) => module.slug === 'bienestar');

function add(module, slug, title, summary, evidence, lanes, nodes, paths, confirm = []) {
    const value = {
        slug, title, summary, evidence, confirm, lanes,
        nodes: nodes.map(([id, type, name, lane, col, documentation]) => ({ id, type, name, lane, col, documentation })),
        flows: paths.map(([from, to, name], index) => ({ id: `Flow_${index}`, from, to, ...(name ? { name } : {}) })),
    };
    const index = module.processes.findIndex((process) => process.slug === slug);
    if (index < 0) module.processes.push(value);
    else module.processes[index] = value;
}

const attendanceService = 'app/Modules/Asistencia/Services/AsistenciaService.php';
const closeService = 'app/Modules/Asistencia/Services/AsistenciaCierreService.php';
const attendanceLanes = [{ id: 'Lane_User', name: 'Responsable o usuario autorizado' }, { id: 'Lane_System', name: 'Sistema' }];
attendance.processes = attendance.processes.filter((process) => process.slug !== 'marcar-cerrar-reabrir-asistencia');
attendance.scope = 'Marcacion, cierre, reapertura y tratamiento de ausencias; cada accion tiene inicio propio.';

add(attendance, 'marcar-asistencia', 'Marcar asistencia de grupo',
    'Registra una marca individual o masiva para un grupo permitido y no cerrado; recalcula metricas.',
    ['routes/web.php', attendanceService, 'app/Modules/Asistencia/Policies/AsistenciaPolicy.php'], attendanceLanes,
    [
        ['Start', 'startEvent', 'Se solicita marca individual o masiva', 'Lane_User', 0, 'routes/web.php: POST /asistencia/grupos/{grupoId}/marcar o marcar-masivo.'],
        ['Enter', 'userTask', 'Elegir trabajadores y estado', 'Lane_User', 1, `${attendanceService}::marcar / marcarMasivo`],
        ['Validate', 'businessRuleTask', 'Validar permiso, grupo, responsable y horario', 'Lane_System', 2, `${attendanceService}::marcar/marcarMasivo: canRegisterGrupo, grupo no CANCELADO, responsable, horario y payload.`],
        ['Allowed', 'exclusiveGateway', 'Marca permitida?', 'Lane_System', 3, `${attendanceService}::marcar/marcarMasivo`],
        ['Persist', 'serviceTask', 'Crear encabezado y guardar detalles', 'Lane_System', 4, `${attendanceService}::getOrCreateEncabezado; rechaza CERRADO; marcar usa lockForUpdate, masivo usa DB::transaction.`],
        ['Metrics', 'serviceTask', 'Recalcular metricas del grupo', 'Lane_System', 5, `${attendanceService}::marcar/marcarMasivo: recalculateGrupo tras confirmar marcas.`],
        ['Done', 'endEvent', 'Marcas registradas', 'Lane_User', 6, `${attendanceService}::marcar/marcarMasivo devuelve grupo y resumen.`],
        ['Reject', 'endEvent', 'Marcacion rechazada', 'Lane_User', 4, 'Permiso, grupo, horario, detalle o estado invalido.'],
    ],
    [['Start', 'Enter'], ['Enter', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Persist', 'Si'], ['Allowed', 'Reject', 'No'], ['Persist', 'Metrics'], ['Metrics', 'Done']]);

add(attendance, 'cerrar-asistencia', 'Cerrar asistencia de grupo',
    'Cierra el encabezado, completa detalles faltantes como AUSENTE y crea faltas automaticas sin duplicarlas.',
    ['routes/web.php', attendanceService, closeService], attendanceLanes,
    [
        ['Start', 'startEvent', 'Se solicita cierre de grupo', 'Lane_User', 0, 'routes/web.php: POST /asistencia/grupos/{grupoId}/cerrar.'],
        ['Request', 'userTask', 'Confirmar cierre y reporte', 'Lane_User', 1, `${attendanceService}::cerrar`],
        ['Validate', 'businessRuleTask', 'Validar permiso, grupo y registrador', 'Lane_System', 2, `${attendanceService}::cerrar: canCloseGrupo, no CANCELADO, responsable. ${closeService}::cerrar requiere registrador y encabezado no CERRADO.`],
        ['Allowed', 'exclusiveGateway', 'Cierre permitido?', 'Lane_System', 3, `${attendanceService}::cerrar / ${closeService}::cerrar`],
        ['Persist', 'serviceTask', 'Completar detalle y pasar a CERRADO', 'Lane_System', 4, `${closeService}::cerrar: DB::transaction; ensureDetalleCompleto crea AUSENTE por defecto y guarda encabezado CERRADO.`],
        ['Absences', 'serviceTask', 'Crear faltas por ausencias no registradas', 'Lane_System', 5, `${closeService}::cerrar: crea Falta ACTIVA motivo INASISTENCIA_ASISTENCIA vinculada al detalle; omite duplicados.`],
        ['Metrics', 'serviceTask', 'Recalcular metricas', 'Lane_System', 6, `${attendanceService}::cerrar: recalculateGrupo tras transaccion de cierre.`],
        ['Done', 'endEvent', 'Asistencia cerrada', 'Lane_User', 7, `${attendanceService}::cerrar`],
        ['Reject', 'endEvent', 'Cierre rechazado', 'Lane_User', 4, 'Permiso, responsable, grupo o estado impiden cierre.'],
    ],
    [['Start', 'Request'], ['Request', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Persist', 'Si'], ['Allowed', 'Reject', 'No'], ['Persist', 'Absences'], ['Absences', 'Metrics'], ['Metrics', 'Done']]);

add(attendance, 'reabrir-asistencia', 'Reabrir asistencia de grupo',
    'Desde CERRADO devuelve a REGISTRADO y anula faltas automaticas activas relacionadas.',
    ['routes/web.php', attendanceService, closeService], attendanceLanes,
    [
        ['Start', 'startEvent', 'Usuario solicita reapertura', 'Lane_User', 0, 'routes/web.php: POST /asistencia/grupos/{grupoId}/reabrir.'],
        ['Request', 'userTask', 'Confirmar reapertura', 'Lane_User', 1, `${attendanceService}::reabrir`],
        ['Validate', 'businessRuleTask', 'Validar permiso directo, ambito y CERRADO', 'Lane_System', 2, `${attendanceService}::reabrir: userCanDirect(reabrir), manageGrupo y encabezado existente; ${closeService}::reabrir exige CERRADO.`],
        ['Allowed', 'exclusiveGateway', 'Reapertura permitida?', 'Lane_System', 3, `${attendanceService}::reabrir`],
        ['Persist', 'serviceTask', 'CERRADO a REGISTRADO; anular faltas', 'Lane_System', 4, `${closeService}::reabrir: transaccion; Falta INASISTENCIA_ASISTENCIA ACTIVA vinculada pasa a ANULADA con motivo Reapertura de asistencia.`],
        ['Sync', 'serviceTask', 'Sincronizar padron y metricas', 'Lane_System', 5, `${attendanceService}::reabrir: syncPadron y recalculateGrupo despues de transaccion.`],
        ['Done', 'endEvent', 'Asistencia reabierta', 'Lane_User', 6, `${attendanceService}::reabrir`],
        ['Reject', 'endEvent', 'Reapertura rechazada', 'Lane_User', 4, 'No autorizado, encabezado ausente o no CERRADO.'],
    ],
    [['Start', 'Request'], ['Request', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Persist', 'Si'], ['Allowed', 'Reject', 'No'], ['Persist', 'Sync'], ['Sync', 'Done']],
    ['Por confirmar: motivo operativo obligatorio para reabrir; el metodo actual no recibe motivo.']);

const welfareController = 'app/Modules/Bienestar/Controllers/BienestarPageController.php';
const welfareLanes = [{ id: 'Lane_User', name: 'Bienestar/RR.HH. autorizado' }, { id: 'Lane_System', name: 'Sistema' }];
welfare.processes = welfare.processes.filter((process) => process.slug !== 'gestionar-bloqueo-bienestar');
welfare.scope = 'Registro, edicion y anulacion independientes de bloqueos y restricciones del trabajador.';
welfare.inconsistencies = [...new Set([...welfare.inconsistencies,
    'Al registrar, si falta usuario valido en sesion se usa el primer Usuario de la tabla como actor; afecta trazabilidad.',
    'Al editar no se emite la notificacion que si se envia al registrar o anular.'])];

add(welfare, 'registrar-bloqueo', 'Registrar bloqueo de bienestar',
    'Valida datos, fecha, tipo y trabajador; guarda ACTIVO y solicita notificacion.',
    ['routes/web.php', welfareController, 'app/Modules/Notificaciones/Services/OperationalNotificationService.php'], welfareLanes,
    [
        ['Start', 'startEvent', 'Se necesita registrar restriccion', 'Lane_User', 0, 'routes/web.php: POST /bienestar/bloqueos o /bienestar/{id}/bloqueos.'],
        ['Enter', 'userTask', 'Ingresar tipo, motivo y fechas', 'Lane_User', 1, `${welfareController}::storeBloqueo / storeBloqueoGeneral`],
        ['Validate', 'businessRuleTask', 'Validar permiso, trabajador y datos', 'Lane_System', 2, `${welfareController}: permiso crear, fechas coherentes, tipo otro con detalle; gestacion requiere sexo femenino.`],
        ['Allowed', 'exclusiveGateway', 'Registro valido?', 'Lane_System', 3, `${welfareController}::storeBloqueo / storeBloqueoGeneral`],
        ['Save', 'serviceTask', 'Guardar bloqueo ACTIVO y actor', 'Lane_System', 4, `${welfareController}: crea PersonalBloqueo ACTIVO, visible_para_planner=true; si sesion sin usuario valido toma primer Usuario.`],
        ['Notify', 'serviceTask', 'Solicitar notificacion operativa', 'Lane_System', 5, `${welfareController}: bienestarBloqueoRegistrado si Usuario encontrado.`],
        ['Done', 'endEvent', 'Bloqueo registrado', 'Lane_User', 6, `${welfareController}: redirige a detalle del trabajador.`],
        ['Reject', 'endEvent', 'Registro rechazado', 'Lane_User', 4, 'Error de permiso, trabajador, datos o falta de usuario disponible.'],
    ],
    [['Start', 'Enter'], ['Enter', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Save', 'Si'], ['Allowed', 'Reject', 'No'], ['Save', 'Notify'], ['Notify', 'Done']]);

add(welfare, 'editar-bloqueo', 'Editar bloqueo de bienestar',
    'Actualiza tipo, motivo, detalle y fechas de un bloqueo existente; no cambia estado ni emite notificacion.',
    ['routes/web.php', welfareController], welfareLanes,
    [
        ['Start', 'startEvent', 'Se solicita editar bloqueo', 'Lane_User', 0, 'routes/web.php: PUT /bienestar/bloqueos/{bloqueoId}.'],
        ['Enter', 'userTask', 'Modificar datos del bloqueo', 'Lane_User', 1, `${welfareController}::updateBloqueo`],
        ['Validate', 'businessRuleTask', 'Validar permiso, bloqueo y datos', 'Lane_System', 2, `${welfareController}::updateBloqueo: permiso editar/actualizar, fechas, tipo y gestacion segun sexo.`],
        ['Allowed', 'exclusiveGateway', 'Edicion valida?', 'Lane_System', 3, `${welfareController}::updateBloqueo`],
        ['Save', 'serviceTask', 'Actualizar tipo, motivo y fechas', 'Lane_System', 4, `${welfareController}::updateBloqueo: no modifica estado, no notifica.`],
        ['Done', 'endEvent', 'Bloqueo actualizado', 'Lane_User', 5, `${welfareController}: redirige a listado.`],
        ['Reject', 'endEvent', 'Edicion rechazada', 'Lane_User', 4, 'Permiso, bloqueo o datos invalidos.'],
    ],
    [['Start', 'Enter'], ['Enter', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Save', 'Si'], ['Allowed', 'Reject', 'No'], ['Save', 'Done']]);

add(welfare, 'anular-bloqueo', 'Anular bloqueo de bienestar',
    'Cambia el estado del bloqueo existente a ANULADO y solicita notificacion.',
    ['routes/web.php', welfareController, 'app/Modules/Notificaciones/Services/OperationalNotificationService.php'], welfareLanes,
    [
        ['Start', 'startEvent', 'Se solicita anulacion', 'Lane_User', 0, 'routes/web.php: POST /bienestar/bloqueos/{bloqueoId}/anular.'],
        ['Request', 'userTask', 'Confirmar anulacion', 'Lane_User', 1, `${welfareController}::anularBloqueo`],
        ['Validate', 'businessRuleTask', 'Validar permiso y existencia', 'Lane_System', 2, `${welfareController}::anularBloqueo: permiso eliminar/anular; findOrFail.`],
        ['Allowed', 'exclusiveGateway', 'Puede anular?', 'Lane_System', 3, `${welfareController}::anularBloqueo`],
        ['Save', 'serviceTask', 'Cambiar estado a ANULADO', 'Lane_System', 4, `${welfareController}::anularBloqueo: update estado=ANULADO; no valida estado previo.`],
        ['Notify', 'serviceTask', 'Solicitar notificacion', 'Lane_System', 5, `${welfareController}: bienestarBloqueoAnulado solo si usuario de sesion existe.`],
        ['Done', 'endEvent', 'Bloqueo anulado', 'Lane_User', 6, `${welfareController}: vuelve a pantalla anterior.`],
        ['Reject', 'endEvent', 'Anulacion rechazada', 'Lane_User', 4, 'Permiso denegado o bloqueo no encontrado.'],
    ],
    [['Start', 'Request'], ['Request', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Save', 'Si'], ['Allowed', 'Reject', 'No'], ['Save', 'Notify'], ['Notify', 'Done']]);

fs.writeFileSync(file, `${JSON.stringify(catalog, null, 2)}\n`);
