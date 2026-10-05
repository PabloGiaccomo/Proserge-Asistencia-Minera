import fs from 'node:fs';
const file = new URL('./process-catalog.json', import.meta.url);
const catalog = JSON.parse(fs.readFileSync(file, 'utf8'));
function register(module, slug, title, schedule, evidence, steps, confirm = []) {
    const nodes = [{id:'Start_Timer', type:'startEvent', name:schedule, timer:schedule, lane:'Lane_Auto', col:0, documentation:'routes/console.php. Expresion del scheduler Laravel; diagrama descriptivo no ejecutable. Zona horaria efectiva depende de configuracion y despliegue.'}];
    steps.forEach(([name, documentation], i) => nodes.push({id:`Task_${i}`,type:'serviceTask',name,lane:'Lane_Auto',col:i+1,documentation}));
    nodes.push({id:'End_Run',type:'endEvent',name:'Ejecucion finalizada; consultar resumen',lane:'Lane_Auto',col:steps.length+1,documentation:evidence.join('; ')});
    const process = {slug,title,summary:steps.map(s=>s[0]).join('. '),evidence:['routes/console.php',...evidence],confirm,lanes:[{id:'Lane_Auto',name:'Proceso automatico / Sistema'}],nodes,flows:nodes.slice(1).map((n,i)=>({id:`Flow_${i}`,from:nodes[i].id,to:n.id}))};
    const target=catalog.modules.find(m=>m.slug===module);
    const index=target.processes.findIndex(p=>p.slug===slug);
    if(index<0) target.processes.push(process); else target.processes[index]=process;
}
const attendance='app/Modules/Asistencia/Services/AsistenciaService.php::marcarAusentesPorHorario';
register('asistencia-faltas','ausencias-por-horario','Marcar ausencias por horario','* * * * *',[
    'app/Console/Commands/AsistenciaAusentesPorHorarioCommand.php::handle',attendance
],[
    ['Seleccionar grupos PROGRAMADO de hoy sin asistencia cerrada','AsistenciaAusentesPorHorarioCommand::handle; jornada en display_timezone, lotes de 100, scheduler withoutOverlapping.'],
    ['Por grupo: comprobar horario vencido y jornada actual',`${attendance}: devuelve cero si no cumple; no es un cierre de asistencia.`],
    ['En transaccion: bloquear y revalidar; marcar pendientes AUSENTE',`${attendance}: lockForUpdate; omite grupo cancelado, encabezado CERRADO, distribucion vacia o sin pendientes. Guarda origen SISTEMA, actor null y motivo. No sobrescribe marcas resueltas. Excepcion revierte esta transaccion.`],
    ['Recalcular metricas si hubo cambios; reportar errores y continuar',`${attendance}: metricas tras commit. Command::handle captura Throwable por grupo y continua. Un error de metricas no revierte marcas ya confirmadas.`],
    ['Informar cantidades; devolver FAILURE si hubo errores','AsistenciaAusentesPorHorarioCommand::handle; SUCCESS solo cuando failed=0.']
]);
const reminders='app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php::emitDeadlineReminders';
register('logistica-operativa','recordatorio-herramientas','Recordar vencimiento de herramientas','30 7 * * *',[
    'app/Console/Commands/ParadaHerramientasDeadlineReminderCommand.php::handle',reminders
],[
    ['Comprobar tablas; si faltan devolver cero',`${reminders}: notification_types y parada_herramienta_listas.`],
    ['Seleccionar RQ con inicio en nueve dias y lista no enviada',`${reminders}: lista ausente o estado distinto de ENVIADO.`],
    ['Por RQ: emitir solo si limite vence en dos dias',`${reminders}: fecha_limite_envio o deadlineFor; si no coincide omite el RQ.`],
    ['Emitir a jefes de planeamiento con clave de deduplicacion',`${reminders}: tipo lista_herramientas_por_vencer, actor null, clave RQ+fecha. No envia ni modifica la lista.`],
    ['Informar alertas emitidas; devolver SUCCESS','ParadaHerramientasDeadlineReminderCommand::handle. No captura localmente excepciones del servicio: se propagan, sin garantizar rollback global.']
]);
const temporal='app/Modules/Personal/Services/PersonalMinaHabilitacionService.php::syncTemporalStatuses';
register('habilitacion-minera','sincronizar-vigencias','Sincronizar vigencias mineras','10 0 * * *',[
    'app/Console/Commands/HabilitacionMineraSyncTemporalStatusesCommand.php::handle',temporal
],[
    ['Cargar requisitos activos y asignaciones de minas operativas',`${temporal}: PersonalMina activo, mina activeOperational; lotes de 100; scheduler withoutOverlapping.`],
    ['Por asignacion: transaccion, bloqueo y recalculo temporal',`${temporal}: si desaparecio omite; calculateAssignmentStatus actualiza vigencias de examenes y calcula estado.`],
    ['Guardar estado e historial solo si cambia la asignacion',`${temporal}: estado, estado_habilitacion y fecha_habilitacion; recordHistory con actor null. Sin cambio de asignacion conserva actualizaciones de examenes.`],
    ['Capturar error por asignacion y continuar con las restantes',`${temporal}: Throwable revierte transaccion de esa asignacion, report(error), errores++. No hay transaccion global del lote.`],
    ['Informar contadores; FAILURE si errores mayor que cero','HabilitacionMineraSyncTemporalStatusesCommand::handle. Los contadores no sustituyen una auditoria de filas confirmadas.']
]);
const cleanup='app/Console/Commands/NotificationsCleanupExpiredCommand.php::handle';
register('notificaciones','limpiar-notificaciones-vencidas','Vencer y purgar notificaciones','10 2 * * *',[cleanup],[
    ['Resolver plazo de purga: 15 dias por defecto',`${cleanup}: opcion purge-days, minimo uno; ahora del servidor.`],
    ['UNREAD a EXPIRED si expires_at es anterior a ahora',`${cleanup}: actualiza notification_recipients ligados a eventos vencidos.`],
    ['Eliminar destinatarios de eventos vencidos hace mas del plazo',`${cleanup}: delete sin filtrar estado UNREAD; tambien alcanza leidos. No es archivado.`],
    ['Eliminar eventos vencidos antiguos sin destinatarios',`${cleanup}: DELETE notification_events con NOT EXISTS destinatarios.`],
    ['Informar marcados y eliminados; SUCCESS',`${cleanup}: sin transaccion ni captura local; error puede dejar operaciones anteriores aplicadas.`]
]);
fs.writeFileSync(file, JSON.stringify(catalog,null,2)+'\n');
