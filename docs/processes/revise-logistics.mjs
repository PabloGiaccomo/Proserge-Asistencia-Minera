import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const module=catalog.modules.find(m=>m.slug==='logistica-operativa');
const epp='app/Modules/Epps/Services/EppService.php';
const page='app/Modules/Epps/Controllers/EppPageController.php';
const transport='app/Modules/Transporte/Services/TransportePlanningService.php';
// Replace only reviewed process families; retain unrelated diagrams and stable URLs.
function process(slug,title,summary,evidence,rows,links,confirm=[]) {
 const nodes=rows.map(([id,type,name,lane,col,documentation])=>({id,type,name,lane,col,documentation}));
 const value={slug,title,summary,evidence,confirm,lanes:[{id:'Lane_User',name:'Usuario autorizado / Logistica'},{id:'Lane_System',name:'Sistema'}],nodes,flows:links.map(([from,to,name],i)=>({id:`Flow_${i}`,from,to,...(name?{name}:{})}))};
 const index=module.processes.findIndex(p=>p.slug===slug);
 if(index<0) module.processes.push(value); else module.processes[index]=value;
}
process('entrega-devolucion-epp','Registrar entrega de EPP','Entrega independiente; cierre y reemplazo se documentan en un proceso separado.',[epp,page,'routes/web.php'],[
 ['Start','startEvent','Solicitar entrega','Lane_User',0,`${page}::storeEntrega`],
 ['Select','userTask','Elegir trabajador, EPP, fecha y cantidad','Lane_User',1,`${page}::storeEntrega; ultimaEntrega muestra historial sin constituir una condicion para guardar.`],
 ['Validate','businessRuleTask','Validar permiso y formulario','Lane_System',2,`routes/web.php: web.permission:epps,registrar; ${page}::storeEntrega valida referencias y datos.`],
 ['Valid','exclusiveGateway','Datos validos?','Lane_System',3,`${page}::storeEntrega; validacion previa a deliver.`],
 ['Save','serviceTask','Crear ENTREGADO con snapshot','Lane_System',4,`${epp}::deliver: transaccion, vida util, vencimiento calendario, atributos y registrado_por_usuario_id.`],
 ['Done','endEvent','Entrega registrada','Lane_User',5,`${epp}::deliver. No exige una devolucion posterior en esta solicitud.`],
 ['Reject','endEvent','Rechazo sin entrega','Lane_User',4,`${page}::storeEntrega; middleware o validacion impiden crear.`]
],[['Start','Select'],['Select','Validate'],['Validate','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);

process('cerrar-reemplazar-epp','Cerrar o reemplazar entrega de EPP','Parte de una entrega existente. Un cambio no cierra la anterior hasta confirmar la nueva entrega.',[epp,page,'routes/web.php'],[
 ['Start','startEvent','Seleccionar entrega existente','Lane_User',0,`${page}::closeEntrega`],
 ['Request','userTask','Indicar movimiento, fecha y motivo','Lane_User',1,`${page}::closeEntrega: CAMBIADO, DEVUELTO, USO_INCORRECTO o PERDIDA_OLVIDO.`],
 ['Validate','businessRuleTask','Validar permiso y datos','Lane_System',2,`routes/web.php: epps,registrar; ${page}::closeEntrega: valida estado y fecha. Fallos retornan sin mutacion.`],
 ['Kind','exclusiveGateway','Movimiento solicitado','Lane_System',3,`${page}::closeEntrega distingue CAMBIADO.`],
 ['Close','serviceTask','Cerrar si sigue ENTREGADO','Lane_System',4,`${epp}::closeEntrega: si ya cerrado retorna sin cambios; en otro caso guarda estado, motivo, fecha y actor.`],
 ['CloseEnd','endEvent','Cierre procesado','Lane_User',5,`${epp}::closeEntrega; puede ser no-op si ya cerrado.`],
 ['Prepare','serviceTask','Preparar formulario de reemplazo','Lane_System',4,`${page}::closeEntrega: exige ENTREGADO; retorna withInput, no cierra ni crea entrega aun.`],
 ['Confirm','userTask','Completar y confirmar nueva entrega','Lane_User',5,`${page}::storeEntrega: _epp_replaces_entrega_id enlaza la solicitud. Abandonar formulario no modifica entrega anterior.`],
 ['Check','businessRuleTask','Bloquear anterior y revalidar','Lane_System',6,`${epp}::replaceEntrega: transaccion con lockForUpdate, exige ENTREGADO y mismo trabajador.`],
 ['Valid','exclusiveGateway','Reemplazo valido?','Lane_System',7,`${epp}::replaceEntrega: InvalidArgumentException ante cierre previo o trabajador distinto.`],
 ['Replace','serviceTask','Crear nueva y marcar anterior CAMBIADO','Lane_System',8,`${epp}::replaceEntrega: deliver y cierre anterior dentro de transaccion. Excepcion revierte ambas escrituras.`],
 ['Done','endEvent','Reemplazo registrado','Lane_User',9,`${epp}::replaceEntrega devuelve nueva entrega; conserva anterior.`],
 ['Reject','endEvent','Rechazo; sin reemplazo','Lane_User',8,`${epp}::replaceEntrega: validacion y rollback.`]
],[['Start','Request'],['Request','Validate'],['Validate','Kind'],['Kind','Close','Devolucion / uso incorrecto / perdida'],['Close','CloseEnd'],['Kind','Prepare','CAMBIADO'],['Prepare','Confirm'],['Confirm','Check'],['Check','Valid'],['Valid','Replace','Si'],['Replace','Done'],['Valid','Reject','No']],['Criterio operativo para elegir desgaste, perdida, uso incorrecto o internamiento.']);

process('planificar-transporte','Crear servicio de transporte','Crea servicio PERSONAL o CARGA y alcances. Pasajeros y cambios de estado son operaciones posteriores independientes.',[transport,'app/Models/TransporteServicio.php'],[
 ['Start','startEvent','Necesidad de transporte','Lane_User',0,`${transport}::createServicio`],
 ['Form','userTask','Indicar tipo, fecha, turno y alcances','Lane_User',1,`${transport}::normalizeServicePayload: PERSONAL o CARGA, BORRADOR por defecto.`],
 ['Validate','businessRuleTask','Validar permiso, servicio y alcances','Lane_System',2,`${transport}::createServicio, validateServicePayload, syncAlcances y validateAlcanceBelongs. Permiso crear/administrar; validacion dentro de transaccion.`],
 ['Valid','exclusiveGateway','Solicitud valida?','Lane_System',3,`${transport}::createServicio: error de negocio o excepcion impide completar creacion.`],
 ['Save','serviceTask','Crear servicio y registrar evento','Lane_System',4,`${transport}::createServicio: snapshot conductor, alcances, CREACION y actor. No crea pasajeros.`],
 ['Done','endEvent','Servicio creado','Lane_User',5,`${transport}::createServicio devuelve servicio. No implica confirmado ni finalizado.`],
 ['Reject','endEvent','Creacion rechazada','Lane_User',4,`${transport}::createServicio; errores de validacion y rollback ante excepcion.`]
],[['Start','Form'],['Form','Validate'],['Validate','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);

process('asignar-pasajeros','Asignar pasajeros a transporte','Solo servicios PERSONAL. Valida candidatos y capacidad antes de crear asignaciones.',[transport],[
 ['Start','startEvent','Servicio existente','Lane_User',0,`${transport}::addPasajeros`],
 ['Select','userTask','Seleccionar integrantes de Man Power','Lane_User',1,`${transport}::addPasajeros: ids de detalles o grupo completo.`],
 ['Check','businessRuleTask','Validar permiso, tipo y candidatos','Lane_System',2,`${transport}::addPasajeros: entregar/actualizar/administrar; rechaza CARGA, seleccion vacia, fuera de contexto o ya asignados al mismo tramo.`],
 ['Capacity','businessRuleTask','Comprobar capacidad disponible','Lane_System',3,`${transport}::availableSeats y addPasajeros; si disponible no es null, rechaza exceso.`],
 ['Valid','exclusiveGateway','Asignacion permitida?','Lane_System',4,`${transport}::addPasajeros: validaciones antes del foreach de escritura.`],
 ['Save','serviceTask','Guardar ASIGNADO y evento','Lane_System',5,`${transport}::addPasajeros: transaccion, actor, fecha, tramo; PASAJEROS_ASIGNADOS.`],
 ['Done','endEvent','Pasajeros asignados','Lane_User',6,`${transport}::addPasajeros`],
 ['Reject','endEvent','Sin nuevas asignaciones','Lane_User',5,`${transport}::addPasajeros: error de negocio previo a crear pasajeros.`]
],[['Start','Select'],['Select','Check'],['Check','Capacity'],['Capacity','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);

process('cambiar-estado-transporte','Cambiar estado de transporte','Documenta la validacion existente; no impone una secuencia estricta de estados que el servicio no aplica.',[transport,'app/Models/TransporteServicio.php'],[
 ['Start','startEvent','Solicitar nuevo estado','Lane_User',0,`${transport}::changeEstado`],
 ['Check','businessRuleTask','Validar permiso y confirmacion','Lane_System',1,`${transport}::changeEstado: actualizar/entregar/recepcionar/administrar. validateStateChange solo agrega condiciones si destino CONFIRMADO.`],
 ['Valid','exclusiveGateway','Validacion satisfecha?','Lane_System',2,`${transport}::validateStateChange: para PERSONAL requiere placa, conductor, capacidad positiva y sin sobreocupacion; exige alcance cargado no vacio. Para otros estados retorna ok.`],
 ['Save','serviceTask','Guardar estado y CAMBIO_ESTADO','Lane_System',3,`${transport}::changeEstado: transaccion con actor, estado anterior, siguiente, metricas y observacion.`],
 ['Done','endEvent','Estado actualizado','Lane_User',4,`${transport}::changeEstado. Estados de catalogo: BORRADOR, ASIGNADO, CONFIRMADO, EN_SERVICIO, FINALIZADO, CANCELADO.`],
 ['Reject','endEvent','Cambio rechazado','Lane_User',3,`${transport}::changeEstado retorna validacion sin escritura.`]
],[['Start','Check'],['Check','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']],['Definir si el negocio exige transiciones estrictas entre estados; actualmente validateStateChange no las comprueba salvo requisitos de CONFIRMADO.']);

process('retirar-pasajero','Retirar pasajero de transporte','Retiro de una asignacion activa con motivo y evento; tambien puede ser invocado desde Man Power.',[transport,'app/Models/TransporteServicioPasajero.php'],[
 ['Start','startEvent','Pasajero asignado','Lane_User',0,`${transport}::retirePasajero; retireActivePassengerForDetail lo invoca al retirar integrante de Man Power.`],
 ['Request','userTask','Indicar retiro y motivo','Lane_User',1,`${transport}::retirePasajero recibe id y motivo.`],
 ['Permission','businessRuleTask','Validar permiso actualizar, recepcionar o administrar','Lane_System',2,`${transport}::retirePasajero: TRANSPORTE_PASSENGER_RETIRE_FORBIDDEN.`],
 ['Active','businessRuleTask','Bloquear y comprobar ASIGNADO','Lane_System',3,`${transport}::retirePasajero: lockForUpdate, pertenece al servicio y estado ASIGNADO.`],
 ['Valid','exclusiveGateway','Pasajero activo?','Lane_System',4,`${transport}::retirePasajero: TRANSPORTE_PASSENGER_NOT_FOUND.`],
 ['Save','serviceTask','Registrar RETIRADO, actor, fecha y motivo','Lane_System',5,`${transport}::retirePasajero: transaccion y evento PASAJERO_RETIRADO. Puede usar estado REUBICADO cuando lo llama reubicarPasajero.`],
 ['Done','endEvent','Retiro registrado','Lane_User',6,`${transport}::retirePasajero devuelve servicio actualizado.`],
 ['Reject','endEvent','Sin retiro','Lane_User',5,`${transport}::retirePasajero devuelve error si permiso o asignacion fallan.`]
],[['Start','Request'],['Request','Permission'],['Permission','Active'],['Active','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);

process('reubicar-pasajero','Reubicar pasajero entre servicios','Retira de origen y agrega a destino PERSONAL de la misma fecha y turno. Riesgo de retiro parcial ante errores de negocio posteriores.',[transport,'app/Models/TransporteServicioPasajero.php'],[
 ['Start','startEvent','Solicitar reubicacion','Lane_User',0,`${transport}::reubicarPasajero`],
 ['Select','userTask','Elegir pasajero, destino y motivo','Lane_User',1,`${transport}::reubicarPasajero`],
 ['Check','businessRuleTask','Comprobar destino y pasajero activo','Lane_System',2,`${transport}::reubicarPasajero: destino PERSONAL, misma fecha y turno; origen contiene pasajero ASIGNADO, lockForUpdate.`],
 ['Valid','exclusiveGateway','Contexto valido?','Lane_System',3,`${transport}::reubicarPasajero: TRANSPORTE_RELOCATION_INVALID o TRANSPORTE_PASSENGER_NOT_FOUND.`],
 ['Retire','serviceTask','Marcar origen REUBICADO','Lane_System',4,`${transport}::reubicarPasajero llama retirePasajero dentro de DB::transaction.`],
 ['Add','serviceTask','Intentar asignar al destino','Lane_System',5,`${transport}::reubicarPasajero llama addPasajeros; este valida permiso, candidato y capacidad. Devuelve errores como array, no siempre lanza excepcion.`],
 ['Added','exclusiveGateway','Destino acepto pasajero?','Lane_System',6,`${transport}::addPasajeros puede devolver ok=false; reubicarPasajero devuelve ese array desde la transaccion, sin lanzar excepcion.`],
 ['Done','endEvent','Reubicacion registrada','Lane_User',7,`${transport}::reubicarPasajero, cuando addPasajeros devuelve ok=true.`],
 ['Partial','endEvent','Error tras retiro: revisar origen y destino','Lane_User',7,`${transport}::reubicarPasajero: si addPasajeros devuelve error de negocio, el retiro previo puede quedar confirmado. Requiere correccion funcional; no afirmar rollback.`],
 ['Reject','endEvent','Sin traslado','Lane_User',4,`${transport}::reubicarPasajero: validacion inicial falla antes de retirar.`]
],[['Start','Select'],['Select','Check'],['Check','Valid'],['Valid','Retire','Si'],['Valid','Reject','No'],['Retire','Add'],['Add','Added'],['Added','Done','Si'],['Added','Partial','No']]);

process('copiar-servicio-transporte','Copiar servicio de transporte','Crea un nuevo BORRADOR desde otro servicio, con fecha y turno elegidos; copia placa y conductor solo si se solicita.',[transport,'app/Models/TransporteServicio.php'],[
 ['Start','startEvent','Servicio existente','Lane_User',0,`${transport}::copyServicio`],
 ['Select','userTask','Elegir fecha, turno y opciones de copia','Lane_User',1,`${transport}::copyServicio: copiar_placa y copiar_conductor son opcionales.`],
 ['Permission','businessRuleTask','Validar permiso crear, duplicar o administrar','Lane_System',2,`${transport}::copyServicio: TRANSPORTE_COPY_FORBIDDEN.`],
 ['Build','serviceTask','Preparar BORRADOR y alcances compatibles','Lane_System',3,`${transport}::copyServicio: copyableGrupoTrabajoId limita grupo a nueva fecha y turno; no copia pasajeros.`],
 ['Create','serviceTask','Crear nuevo servicio con validaciones','Lane_System',4,`${transport}::copyServicio delega en createServicio, que vuelve a exigir crear/administrar y valida datos antes de guardar.`],
 ['Valid','exclusiveGateway','Creacion aceptada?','Lane_System',5,`${transport}::createServicio puede retornar error de permiso/validacion.`],
 ['Done','endEvent','Copia BORRADOR creada','Lane_User',6,`${transport}::copyServicio; identidad nueva y evento CREACION.`],
 ['Reject','endEvent','Copia rechazada','Lane_User',6,`${transport}::copyServicio/createServicio no crea nuevo servicio cuando falla permiso o validacion.`]
],[['Start','Select'],['Select','Permission'],['Permission','Build'],['Build','Create'],['Create','Valid'],['Valid','Done','Si'],['Valid','Reject','No']]);
const issue='Reubicacion de pasajero: si el alta en destino devuelve ok=false despues de marcar REUBICADO en origen, la transaccion externa puede confirmar el retiro sin nueva asignacion; revisar tratamiento atomico de errores de negocio.';
if(!module.inconsistencies.includes(issue)) module.inconsistencies.push(issue);
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
