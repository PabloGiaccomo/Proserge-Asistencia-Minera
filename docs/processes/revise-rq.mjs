import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const mina=catalog.modules.find(m=>m.slug==='rq-mina');
const pro=catalog.modules.find(m=>m.slug==='rq-proserge');
const rq='app/Modules/RQMina/Services/RQMinaService.php';
const ps='app/Modules/RQProserge/Services/RQProsergeService.php';
function process(module,slug,title,summary,evidence,steps,links,confirm=[]) {
 const nodes=steps.map(([id,type,name,lane,col,documentation])=>({id,type,name,lane,col,documentation}));
 const value={slug,title,summary,evidence,confirm,lanes:[{id:'Lane_User',name:module===mina?'Solicitante de mina':'RR.HH. / Operaciones'},{id:'Lane_System',name:'Sistema'}],nodes,flows:links.map(([from,to,name],i)=>({id:`Flow_${i}`,from,to,...(name?{name}:{})}))};
 const index=module.processes.findIndex(p=>p.slug===slug);
 if(index<0) module.processes.push(value); else module.processes[index]=value;
}
const rqEvidence=[rq,'app/Modules/RQMina/Policies/RQMinaPolicy.php','routes/web.php','routes/api.php'];
process(mina,'crear-editar-enviar-requerimiento','Ciclo de RQ Mina','Mapa breve: crear BORRADOR, editar y enviar son operaciones con permisos y momentos distintos. Consultar los BPMN de detalle.',rqEvidence,[
 ['Start','startEvent','Necesidad de RQ Mina','Lane_User',0,`${rq}::create`],
 ['Create','serviceTask','Crear BORRADOR','Lane_System',1,`${rq}::create; transaccion y detalle inicial.`],
 ['Choice','exclusiveGateway','Accion posterior','Lane_User',2,`routes/web.php: editar y enviar son acciones separadas, no obligatorias al crear.`],
 ['Edit','userTask','Editar si corresponde','Lane_User',3,`${rq}::update, updateGeneral; puede repetirse y sincroniza si ya esta ENVIADO.`],
 ['Send','userTask','Solicitar envio','Lane_User',3,`${rq}::send; requiere permiso especifico.`],
 ['Draft','endEvent','BORRADOR conservado','Lane_User',3,`${rq}::create finaliza sin envio.`],
 ['Edited','endEvent','RQ editado','Lane_User',4,`${rq}::update finaliza una edicion independiente.`],
 ['Sent','endEvent','Envio procesado','Lane_User',4,`${rq}::send marca ENVIADO y llama sincronizacion.`]
],[['Start','Create'],['Create','Choice'],['Choice','Edit','Editar'],['Choice','Send','Enviar'],['Choice','Draft','Guardar'],['Edit','Edited'],['Send','Sent']]);
process(mina,'crear-rq-mina','Crear requerimiento de mina','El solicitante crea un BORRADOR con puestos, transporte y plan base.',rqEvidence,[
 ['Start','startEvent','Necesidad de requerimiento','Lane_User',0,`${rq}::create`],
 ['Form','userTask','Ingresar destino, fechas y cantidades','Lane_User',1,`StoreRQMinaRequest y ${rq}::create.`],
 ['Validate','businessRuleTask','Validar destino, permiso y alcance de mina','Lane_System',2,`${rq}::resolveDestination; PermissionMatrix rq_mina.crear; RQMinaPolicy::canAccessMina.`],
 ['Allowed','exclusiveGateway','Autorizado y valido?','Lane_System',3,`${rq}::create retorna null si falla destino/permiso/scope.`],
 ['Save','serviceTask','Crear BORRADOR, detalles y plan base','Lane_System',4,`${rq}::create: DB::transaction, puestos, transporte, ensureDefaultPlan y replacePlanOperativo.`],
 ['Done','endEvent','BORRADOR registrado','Lane_User',5,`${rq}::create; aun no hay envio.`],
 ['Reject','endEvent','Creacion rechazada','Lane_User',4,`${rq}::create retorna null o la validacion HTTP falla.`]
],[['Start','Form'],['Form','Validate'],['Validate','Allowed'],['Allowed','Save','Si'],['Save','Done'],['Allowed','Reject','No']]);
process(mina,'editar-rq-mina','Editar requerimiento de mina','Edita un requerimiento existente; si ya fue enviado, sincroniza los cambios con RQ Proserge.',rqEvidence,[
 ['Start','startEvent','RQ Mina existente','Lane_User',0,`${rq}::update o updateGeneral.`],
 ['Form','userTask','Modificar datos permitidos','Lane_User',1,`${rq}::update y updateGeneral tienen distinto alcance de campos.`],
 ['Validate','businessRuleTask','Validar permiso y destino','Lane_System',2,`RQMinaPolicy::update; ${rq}::resolveDestination y canAccessMina.`],
 ['Allowed','exclusiveGateway','Edicion autorizada?','Lane_System',3,`${rq}::update retorna null si no se permite.`],
 ['Save','serviceTask','Guardar cambios en transaccion','Lane_System',4,`${rq}::update reemplaza detalles/transporte/plan; updateGeneral solo datos generales.`],
 ['Sent','exclusiveGateway','RQ ya ENVIADO?','Lane_System',5,`${rq}::update y updateGeneral comprueban estado ENVIADO despues de guardar.`],
 ['Sync','serviceTask','Sincronizar RQ Proserge','Lane_System',6,`${rq}::update y updateGeneral invocan syncFromRqMina dentro de la transaccion de edicion.`],
 ['Done','endEvent','Cambios guardados','Lane_User',7,`${rq}::update`],
 ['Reject','endEvent','Edicion rechazada','Lane_User',4,`RQMinaPolicy::update o destino fuera de scope.`]
],[['Start','Form'],['Form','Validate'],['Validate','Allowed'],['Allowed','Save','Si'],['Allowed','Reject','No'],['Save','Sent'],['Sent','Sync','Si'],['Sent','Done','No'],['Sync','Done']]);
process(mina,'enviar-rq-mina','Enviar requerimiento a RR. HH.','Marca ENVIADO y sincroniza RQ Proserge; actualmente el metodo no usa una transaccion que cubra ambas escrituras.',[...rqEvidence,`${ps}::syncFromRqMina`],[
 ['Start','startEvent','RQ Mina existente','Lane_User',0,`${rq}::send`],
 ['Request','userTask','Solicitar envio','Lane_User',1,`routes/web.php: rq_mina.enviar; API /rq-mina/{id}/enviar.`],
 ['Check','businessRuleTask','Comprobar permiso y estado','Lane_System',2,`RQMinaPolicy::send; ${rq}::send retorna null si falla.`],
 ['Allowed','exclusiveGateway','Envio permitido?','Lane_System',3,`RQMinaPolicy::send.`],
 ['Mark','serviceTask','Guardar estado ENVIADO y enviado_at','Lane_System',4,`${rq}::send llama save antes de syncFromRqMina.`],
 ['Sync','serviceTask','Sincronizar RQ Proserge','Lane_System',5,`${ps}::syncFromRqMina. No hay DB::transaction que envuelva save y esta llamada en RQMinaService::send.`],
 ['Done','endEvent','RQ enviado y sincronizado','Lane_User',6,`${rq}::send cuando la sincronizacion termina.`],
 ['Reject','endEvent','Envio rechazado','Lane_User',4,`RQMinaPolicy::send retorna false; no se guarda ENVIADO.`]
],[['Start','Request'],['Request','Check'],['Check','Allowed'],['Allowed','Mark','Si'],['Allowed','Reject','No'],['Mark','Sync'],['Sync','Done']],['Una excepcion en syncFromRqMina se propaga; el metodo no produce un fin de error gestionado. Verificar persistencia parcial tras fallos.']);
const issue='RQMinaService::send guarda ENVIADO antes de syncFromRqMina y no abre transaccion comun; fallo de sincronizacion puede dejar estado parcial.';
if(!mina.inconsistencies.includes(issue)) mina.inconsistencies.push(issue);
const proEvidence=[ps,'app/Modules/RQProserge/Policies/RQProsergePolicy.php','app/Modules/RQProserge/Services/RQProsergeCoverageService.php','routes/web.php','routes/api.php'];
process(pro,'atender-requerimiento-personal','Asignar personal a RQ Proserge','Busca trabajador disponible para un puesto y crea una asignacion activa con cobertura recalculada.',proEvidence,[
 ['Start','startEvent','Puesto de RQ Proserge por atender','Lane_User',0,`${ps}::assignPersonal`],
 ['Select','userTask','Seleccionar puesto, persona y fechas','Lane_User',1,`${ps}::assignPersonal; puesto procede de RQMinaDetalle.`],
 ['Check','businessRuleTask','Validar permiso, parada, fechas y disponibilidad','Lane_System',2,`${ps}::assignPersonal: policy->assign, parada no finalizada, detalle pertenece al RQ, fecha en parada, trabajador existente y disponible.`],
 ['Allowed','exclusiveGateway','Candidato asignable?','Lane_System',3,`${ps}::assignPersonal devuelve error de negocio o lanza RuntimeException si hay error tecnico.`],
 ['Lock','serviceTask','Bloquear RQ y puesto; revalidar cupo','Lane_System',4,`${ps}::assignPersonal: DB::transaction, lockForUpdate, limite por puesto, traslape activo y segunda comprobacion de disponibilidad.`],
 ['Save','serviceTask','Crear ASIGNADO y actualizar cobertura','Lane_System',5,`${ps}::assignPersonal: snapshot de puesto, habilitacion/disponibilidad, actor y fecha; recalculateCantidadAtendida y recalculateEstado.`],
 ['Done','endEvent','Asignacion activa registrada','Lane_User',6,`${ps}::assignPersonal devuelve cobertura.`],
 ['Reject','endEvent','Sin asignacion','Lane_User',4,`${ps}::assignPersonal retorna error cuando falla regla antes de insertar.`]
],[['Start','Select'],['Select','Check'],['Check','Allowed'],['Allowed','Lock','Si'],['Allowed','Reject','No'],['Lock','Save'],['Save','Done']]);
process(pro,'actualizar-asignacion','Actualizar asignacion de RQ Proserge','Modifica fechas, posicion, tipo y comentario de una asignacion activa. Recalcula cobertura y estado.',proEvidence,[
 ['Start','startEvent','Asignacion existente','Lane_User',0,`${ps}::updateAssignment`],
 ['Edit','userTask','Cambiar fechas, posicion o comentario','Lane_User',1,`${ps}::updateAssignment`],
 ['Check','businessRuleTask','Validar permiso, parada y asignacion activa','Lane_System',2,`${ps}::updateAssignment: assign o update, parada no finalizada, detalle del RQ, lockForUpdate y isActiva.`],
 ['Dates','businessRuleTask','Validar fechas, traslapes y disponibilidad','Lane_System',3,`${ps}::updateAssignment: rango RQ Mina, traslape excluyendo mismo id, disponibilidad y capacidad de puesto.`],
 ['Allowed','exclusiveGateway','Actualizacion valida?','Lane_System',4,`${ps}::updateAssignment devuelve error antes de guardar si falla una regla.`],
 ['Save','serviceTask','Guardar snapshots y recalcular cobertura','Lane_System',5,`${ps}::updateAssignment: transaccion, actualizado_por_id, recalculateCantidadAtendida, recalculateEstado.`],
 ['Done','endEvent','Asignacion actualizada','Lane_User',6,`${ps}::updateAssignment devuelve cobertura.`],
 ['Reject','endEvent','Sin cambios','Lane_User',5,`${ps}::updateAssignment: rechazo de permiso, estado, rango o disponibilidad.`]
],[['Start','Edit'],['Edit','Check'],['Check','Dates'],['Dates','Allowed'],['Allowed','Save','Si'],['Save','Done'],['Allowed','Reject','No']]);
process(pro,'retirar-asignacion','Retirar asignacion de RQ Proserge','Retira una asignacion activa sin borrar su historial; requiere motivo y actualiza cobertura.',proEvidence,[
 ['Start','startEvent','Asignacion activa','Lane_User',0,`${ps}::retireAssignment`],
 ['Request','userTask','Indicar retiro y motivo','Lane_User',1,`${ps}::retireAssignment`],
 ['Check','businessRuleTask','Validar permiso y parada abierta','Lane_System',2,`${ps}::retireAssignment: policy->unassign y finishedParadaModificationError.`],
 ['Lock','businessRuleTask','Bloquear y comprobar asignacion activa','Lane_System',3,`${ps}::retireAssignment: pertenece al RQ y isActiva; transaccion.`],
 ['Allowed','exclusiveGateway','Retiro permitido?','Lane_System',4,`${ps}::retireAssignment: errores si no encontrada o inactiva.`],
 ['Save','serviceTask','Guardar RETIRADO y recalcular cobertura','Lane_System',5,`${ps}::retireAssignment: retirado_por_id, retirado_at, motivo_retiro; recalculateCantidadAtendida, recalculateEstado.`],
 ['Done','endEvent','Retiro registrado','Lane_User',6,`${ps}::retireAssignment devuelve cobertura.`],
 ['Reject','endEvent','Sin retiro','Lane_User',5,`${ps}::retireAssignment devuelve error antes de guardar.`]
],[['Start','Request'],['Request','Check'],['Check','Lock'],['Lock','Allowed'],['Allowed','Save','Si'],['Save','Done'],['Allowed','Reject','No']]);
process(pro,'reemplazar-asignacion','Reemplazar asignacion de RQ Proserge','En una transaccion marca original REEMPLAZADO y crea nueva ASIGNADO con referencia al original.',proEvidence,[
 ['Start','startEvent','Asignacion activa','Lane_User',0,`${ps}::replaceAssignment`],
 ['Request','userTask','Elegir reemplazo, fechas y motivo','Lane_User',1,`${ps}::replaceAssignment`],
 ['Check','businessRuleTask','Validar permiso, parada y original activo','Lane_System',2,`${ps}::replaceAssignment: policy->assign, parada no finalizada, detalle del RQ lockForUpdate, isActiva.`],
 ['Candidate','businessRuleTask','Validar fechas, candidato y disponibilidad','Lane_System',3,`${ps}::replaceAssignment: rango, traslape, personal existente y disponibilidad; error tecnico lanza RuntimeException.`],
 ['Allowed','exclusiveGateway','Reemplazo valido?','Lane_System',4,`${ps}::replaceAssignment: fallos de negocio retornan antes de marcar original.`],
 ['Save','serviceTask','REEMPLAZADO + nuevo ASIGNADO','Lane_System',5,`${ps}::replaceAssignment: DB::transaction, actor y motivo de retiro, nuevo reemplaza_a_id; recalcula cupo y estado.`],
 ['Done','endEvent','Reemplazo registrado','Lane_User',6,`${ps}::replaceAssignment devuelve cobertura.`],
 ['Reject','endEvent','Sin reemplazo','Lane_User',5,`${ps}::replaceAssignment retorna error antes de modificar original.`]
],[['Start','Request'],['Request','Check'],['Check','Candidate'],['Candidate','Allowed'],['Allowed','Save','Si'],['Save','Done'],['Allowed','Reject','No']]);
pro.inconsistencies=pro.inconsistencies.filter(x=>!x.includes('Coexisten asignar/desasignar heredados'));
pro.inconsistencies.push('Las rutas legacy asignar/desasignar conviven con las operaciones auditables; unassignPersonal delega en retireAssignment y registra motivo heredado.');
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
