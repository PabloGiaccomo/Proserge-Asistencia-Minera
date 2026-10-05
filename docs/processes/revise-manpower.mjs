import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const module=catalog.modules.find(m=>m.slug==='man-power');
const service='app/Modules/ManPower/Services/GrupoTrabajoService.php';
function process(slug,title,summary,steps,links) {
 const p={slug,title,summary,evidence:[service,'app/Modules/ManPower/Policies/ManPowerPolicy.php','routes/web.php','routes/api.php'],confirm:[],lanes:[{id:'Lane_User',name:'Planificador operativo'},{id:'Lane_System',name:'Sistema'}],nodes:steps.map(([id,type,name,lane,col,documentation])=>({id,type,name,lane,col,documentation})),flows:links.map(([from,to,name],i)=>({id:`Flow_${i}`,from,to,...(name?{name}:{})}))};
 const idx=module.processes.findIndex(x=>x.slug===slug);
 if(idx<0) module.processes.push(p);else module.processes[idx]=p;
}
process('planificar-grupos-trabajo','Crear grupo de trabajo','Crea un grupo BORRADOR o con el estado solicitado, con integrantes iniciales opcionales; las acciones posteriores tienen diagramas propios.',[
 ['Start','startEvent','Parada y fecha a planificar','Lane_User',0,`${service}::createGrupo`],
 ['Form','userTask','Indicar plan, SAIT, destino e integrantes','Lane_User',1,`${service}::createGrupo: admite integrantes iniciales y supervisor que forme parte del grupo.`],
 ['Check','businessRuleTask','Validar permiso, alcance, rango y asistencia','Lane_System',2,`${service}::createGrupo: crear y manageGrupos; mina accesible; fecha dentro de RQ y plan; rechaza asistencia iniciada o cerrada, plan archivado y destino invalido.`],
 ['Conflict','businessRuleTask','Verificar SAIT y distribucion sin conflictos','Lane_System',3,`${service}::createGrupo: un SAIT en grupo operativo; sin duplicado por fecha/turno ni personal en otro grupo. Revalida conflicto con assignments bloqueados dentro de la transaccion.`],
 ['Valid','exclusiveGateway','Grupo valido?','Lane_System',4,`${service}::createGrupo devuelve errores de negocio antes de crear o revierte excepcion.`],
 ['Save','serviceTask','Guardar grupo, snapshots y detalles','Lane_System',5,`${service}::createGrupo: DB::transaction, snapshots de plan, actividades y asignaciones iniciales.`],
 ['Done','endEvent','Grupo registrado','Lane_User',6,`${service}::createGrupo retorna grupo cargado.`],
 ['Reject','endEvent','Sin grupo nuevo','Lane_User',5,`${service}::createGrupo: permiso, rango, conflicto o asistencia bloquean.`]
],[['Start','Form'],['Form','Check'],['Check','Conflict'],['Conflict','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);
process('reubicar-integrante','Reubicar integrante de Man Power','Mueve la distribucion activa entre grupos de la misma parada y fecha; retira pasajero activo de transporte asociado.',[
 ['Start','startEvent','Integrante en grupo origen','Lane_User',0,`${service}::reubicarDetalle`],
 ['Request','userTask','Elegir grupo destino y motivo','Lane_User',1,`${service}::reubicarDetalle`],
 ['Check','businessRuleTask','Validar permiso, destino y asistencia','Lane_System',2,`${service}::reubicarDetalle: asignar, manageGrupo para origen y destino; misma RQ Mina y fecha; sin asistencia iniciada/cerrada.`],
 ['Conflict','businessRuleTask','Bloquear integrante y comprobar conflicto','Lane_System',3,`${service}::reubicarDetalle: lockForUpdate, hasDistributionConflict para turno destino excluyendo origen.`],
 ['Valid','exclusiveGateway','Reubicacion valida?','Lane_System',4,`${service}::reubicarDetalle devuelve error de negocio antes de guardar, o captura conflicto en transaccion.`],
 ['Save','serviceTask','Marcar origen REUBICADO','Lane_System',5,`${service}::reubicarDetalle: estado_distribucion, actor, fecha, motivo; retira pasajero de transporte si activo.`],
 ['Target','serviceTask','Reactivar o crear detalle en destino','Lane_System',6,`${service}::reubicarDetalle: transaccion; conserva referencias y snapshots de asignacion; estado ASIGNADO, asistencia inicial AUSENTE.`],
 ['Done','endEvent','Destino actualizado','Lane_User',7,`${service}::reubicarDetalle devuelve grupo destino.`],
 ['Reject','endEvent','Sin reubicacion','Lane_User',5,`${service}::reubicarDetalle: validaciones previas o rollback ante excepcion.`]
],[['Start','Request'],['Request','Check'],['Check','Conflict'],['Conflict','Valid'],['Valid','Save','Si'],['Save','Target'],['Target','Done'],['Valid','Reject','No']]);
process('cancelar-grupos-dia','Cancelar grupos del dia','Cancela grupos no cancelados del SAIT, fecha y plan seleccionados cuando ninguno tiene asistencia; retira integrantes y pasajeros activos.',[
 ['Start','startEvent','Dia de grupos preparados','Lane_User',0,`${service}::cancelDayGroups`],
 ['Request','userTask','Seleccionar parada, fecha, plan y SAIT','Lane_User',1,`${service}::cancelDayGroups`],
 ['Check','businessRuleTask','Validar permiso, mina, plan y SAIT','Lane_System',2,`${service}::cancelDayGroups: editar/actualizar, manageGrupos y canAccessMina; actividad pertenece al plan.`],
 ['Attendance','businessRuleTask','Bloquear grupos y comprobar asistencia','Lane_System',3,`${service}::cancelDayGroups: lockForUpdate; si no hay grupos o alguno tiene asistencia, devuelve error sin cancelar.`],
 ['Valid','exclusiveGateway','Cancelacion permitida?','Lane_System',4,`${service}::cancelDayGroups: requiere al menos un grupo y ninguna asistencia registrada.`],
 ['Retire','serviceTask','Retirar integrantes y pasajeros activos','Lane_System',5,`${service}::cancelDayGroups: markDetalleRetired y retireActivePassengerForDetail por cada detalle activo en transaccion.`],
 ['Cancel','serviceTask','Marcar grupos CANCELADO','Lane_System',6,`${service}::cancelDayGroups: estado CANCELADO y updated_by_id dentro de la misma transaccion.`],
 ['Done','endEvent','Resumen de cancelacion','Lane_User',7,`${service}::cancelDayGroups devuelve grupos_cancelados e integrantes_retirados.`],
 ['Reject','endEvent','Sin cancelacion','Lane_User',5,`${service}::cancelDayGroups: error de permiso, contexto o asistencia.`]
],[['Start','Request'],['Request','Check'],['Check','Attendance'],['Attendance','Valid'],['Valid','Retire','Si'],['Retire','Cancel'],['Cancel','Done'],['Valid','Reject','No']]);
process('agregar-integrante','Agregar integrante a grupo de Man Power','Agrega una asignacion aprobada para la parada cuando no hay asistencia iniciada ni conflicto de distribucion.',[
 ['Start','startEvent','Grupo existente','Lane_User',0,`${service}::addPersonal`],
 ['Select','userTask','Elegir persona o asignacion RQ Proserge','Lane_User',1,`${service}::addPersonal`],
 ['Check','businessRuleTask','Validar permiso y asistencia','Lane_System',2,`${service}::addPersonal: man_power.asignar, manageGrupo; bloquea si la mina/fecha tiene asistencia iniciada o el grupo tiene asistencia cerrada.`],
 ['Candidate','businessRuleTask','Validar universo aprobado y conflicto','Lane_System',3,`${service}::resolveSingleAssignment: debe corresponder a RQ Proserge; hasDistributionConflict verifica fecha y turno.`],
 ['Valid','exclusiveGateway','Asignacion permitida?','Lane_System',4,`${service}::addPersonal retorna error de negocio si no cumple.`],
 ['Save','serviceTask','Agregar asignacion al grupo','Lane_System',5,`${service}::addAssignmentToGrupo guarda detalle y snapshots.`],
 ['Done','endEvent','Integrante agregado','Lane_User',6,`${service}::addPersonal devuelve grupo.`],
 ['Reject','endEvent','Sin cambio','Lane_User',5,`${service}::addPersonal retorna error por permiso, asistencia o conflicto.`]
],[['Start','Select'],['Select','Check'],['Check','Candidate'],['Candidate','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);
process('copiar-grupo','Copiar grupo de Man Power','Copia un grupo a fecha/turno destino; omite integrantes inactivos, fuera de rango o con conflicto, y registra resumen.',[
 ['Start','startEvent','Grupo origen existente','Lane_User',0,`${service}::copyGrupo`],
 ['Select','userTask','Elegir fecha, turno y copiar integrantes','Lane_User',1,`${service}::copyGrupo: copiar_integrantes por defecto true.`],
 ['Check','businessRuleTask','Validar permiso y acceso al grupo','Lane_System',2,`${service}::copyGrupo: duplicar o crear, manageGrupo.`],
 ['Filter','serviceTask','Filtrar asignaciones copiables','Lane_System',3,`${service}::copyGrupo: omite retirados, asignacion inactiva, fecha fuera de rango o conflicto de distribucion; supervisor solo si se copia.`],
 ['Create','serviceTask','Delegar creacion de grupo destino','Lane_System',4,`${service}::copyGrupo llama createGrupo, que revalida destino, plan, fecha y asistencia.`],
 ['Valid','exclusiveGateway','Grupo destino creado?','Lane_System',5,`${service}::createGrupo puede devolver error; copyGrupo agrega copiados y omitidos al resultado.`],
 ['Done','endEvent','Copia creada con resumen','Lane_User',6,`${service}::copyGrupo devuelve copiados y omitidos.`],
 ['Reject','endEvent','Copia rechazada','Lane_User',6,`${service}::copyGrupo/createGrupo retorna error.`]
],[['Start','Select'],['Select','Check'],['Check','Filter'],['Filter','Create'],['Create','Valid'],['Valid','Done','Si'],['Valid','Reject','No']]);
process('copiar-grupos-dia','Copiar grupos de un dia','Copia grupos fuente a otra fecha. Por defecto reemplaza los grupos destino del SAIT; omite integrantes no elegibles y devuelve contadores.',[
 ['Start','startEvent','Dia origen preparado','Lane_User',0,`${service}::copyDayGroups`],
 ['Request','userTask','Elegir dia destino, SAIT y sobrescritura','Lane_User',1,`${service}::copyDayGroups: sobrescribir_destino por defecto true.`],
 ['Check','businessRuleTask','Validar permiso, RQ, plan, SAIT y origen','Lane_System',2,`${service}::copyDayGroups: duplicar/crear, manageGrupos, resolveCopyActivity y origen no vacio.`],
 ['Valid','exclusiveGateway','Origen y contexto validos?','Lane_System',3,`${service}::copyDayGroups: devuelve error si no hay grupos fuente o el SAIT es ajeno al plan.`],
 ['Overwrite','exclusiveGateway','Sobrescribir destino?','Lane_System',4,`${service}::copyDayGroups: true si se omite el campo.`],
 ['Cancel','serviceTask','Retirar integrantes y cancelar grupos destino','Lane_System',5,`${service}::copyDayGroups: bloquea grupos destino, marca detalles retirados, retira pasajeros y marca CANCELADO. No hay guard explicito de asistencia en este bloque; createGrupo valida despues y un fallo revierte la transaccion.`],
 ['Skip','serviceTask','Omitir duplicados destino','Lane_System',5,`${service}::copyDayGroups: si no sobrescribe, compara fecha/turno, plan, grupo operativo, SAIT, servicio, area y horario; suma grupos_omitidos.`],
 ['Copy','serviceTask','Copiar cada grupo elegible','Lane_System',6,`${service}::copyDayGroups llama copyGrupo/createGrupo por grupo dentro de DB::transaction; fallo lanza RuntimeException y revierte el lote.`],
 ['Done','endEvent','Resumen de grupos e integrantes','Lane_User',7,`${service}::copyDayGroups devuelve copiados, reemplazados y omitidos.`],
 ['Reject','endEvent','Copia diaria rechazada','Lane_User',4,`${service}::copyDayGroups devuelve error si no hay origen o el lote falla.`]
],[['Start','Request'],['Request','Check'],['Check','Valid'],['Valid','Overwrite','Si'],['Valid','Reject','No'],['Overwrite','Cancel','Si'],['Overwrite','Skip','No'],['Cancel','Copy'],['Skip','Copy'],['Copy','Done']]);
process('copiar-grupos-rango','Copiar grupos a semana o parada','Repite la copia diaria desde el dia siguiente o hoy hasta fin de semana/parada, limitada por el plan.',[
 ['Start','startEvent','Dia origen y alcance','Lane_User',0,`${service}::copyDayGroupsToRange`],
 ['Request','userTask','Elegir SEMANA o parada y sobrescritura','Lane_User',1,`${service}::copyDayGroupsToRange: copiar_integrantes y sobrescribir_destino tienen default true.`],
 ['Check','businessRuleTask','Validar permiso, plan, SAIT y rango futuro','Lane_System',2,`${service}::copyDayGroupsToRange: desde dia siguiente u hoy; hasta domingo o fin operativo; limita por fin del plan y parada.`],
 ['Valid','exclusiveGateway','Quedan dias futuros?','Lane_System',3,`${service}::copyDayGroupsToRange: MANPOWER_RANGE_COPY_EMPTY si rango vacio.`],
 ['Copy','serviceTask','Ejecutar copyDayGroups para cada fecha','Lane_System',4,`${service}::copyDayGroupsToRange: DB::transaction externa; error de un dia se convierte en RuntimeException y revierte el rango.`],
 ['Done','endEvent','Totales por rango','Lane_User',5,`${service}::copyDayGroupsToRange devuelve dias_copiados y totales de grupos/integrantes.`],
 ['Reject','endEvent','Rango sin cambios','Lane_User',4,`${service}::copyDayGroupsToRange retorna error ante rango vacio o fallo del lote.`]
],[['Start','Request'],['Request','Check'],['Check','Valid'],['Valid','Copy','Si'],['Copy','Done'],['Valid','Reject','No']]);
const caution='copyDayGroups usa sobrescribir_destino=true por defecto; revisar grupos destino y su asistencia antes de representar copia masiva como inocua.';
if(!module.inconsistencies.includes(caution)) module.inconsistencies.push(caution);
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
