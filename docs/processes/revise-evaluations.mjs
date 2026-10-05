import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const module=catalog.modules.find(m=>m.slug==='evaluaciones');
const find=slug=>module.processes.find(p=>p.slug===slug);
const node=(p,id)=>p.nodes.find(n=>n.id===id);
function add(p,n) { if(!node(p,n.id)) p.nodes.push(n); }
function flow(p,id,from,to,name) {
 const value={id,from,to,...(name?{name}:{})};
 const old=p.flows.find(f=>f.id===id);
 if(old) Object.assign(old,value); else p.flows.push(value);
}
function note(array,value) {if(!array.includes(value)) array.push(value);}
const daily=find('evaluacion-diaria');
node(daily,'Task_Rules').documentation='EvaluacionDesempenoService::createDaily: permiso evaluar_desempeno; personal_id obligatorio incluso admin; supervisor de encabezado o autor de alguna marca de la asistencia, salvo override administrativo; CERRADO; detalle ESTADOS_REAL; no autoevaluacion. Unicidad se comprueba despues dentro de transaccion.';
node(daily,'Task_Rules').name='Validar permiso, actor, cierre y presencia';
node(daily,'Task_Score').documentation='StoreEvaluacionDiariaRequest::rules: cinco enteros 1..4; descripcion obligatoria si hubo incidencia. Formulario web en EvaluacionDesempenoPageController::storeDaily.';
node(daily,'Task_Save').name='Guardar por detalle; actualizar promedio';
node(daily,'Task_Save').documentation='EvaluacionDesempenoService::createDaily: transaccion con comprobacion duplicado; contexto y fecha desde asistencia, actor desde cuenta; total suma de criterios. refreshForTrabajador ocurre despues del commit, no es atomico con la evaluacion.';
add(daily,{id:'Task_ValidateScore',type:'businessRuleTask',name:'Validar criterios e incidencia',lane:'Lane_System',col:5,documentation:'StoreEvaluacionDiariaRequest::rules. Rechaza nota fuera de 1..4 y descripcion faltante cuando hubo incidencia.'});
add(daily,{id:'Gateway_Score',type:'exclusiveGateway',name:'Datos validos?',lane:'Lane_System',col:6,documentation:'StoreEvaluacionDiariaRequest::rules; validacion HTTP anterior a createDaily.'});
add(daily,{id:'End_Invalid',type:'endEvent',name:'Corregir formulario; sin guardar',lane:'Lane_Evaluator',col:7,documentation:'StoreEvaluacionDiariaRequest; fallo de validacion impide llamar al servicio.'});
node(daily,'Task_Save').col=7;
node(daily,'End_Done').col=8;
flow(daily,'Flow_5','Task_Score','Task_ValidateScore');
flow(daily,'Flow_Validate','Task_ValidateScore','Gateway_Score');
flow(daily,'Flow_Valid','Gateway_Score','Task_Save','Si');
flow(daily,'Flow_Invalid','Gateway_Score','End_Invalid','No');
daily.confirm=daily.confirm.filter(x=>!x.startsWith('Por confirmar: tratamiento de evaluaciones existentes'));
module.inconsistencies=module.inconsistencies.filter(x=>!x.startsWith('La reapertura anula faltas automaticas'));
note(module.inconsistencies,'Regla confirmada 2026-09-27, pendiente de implementar: si una asistencia reabierta cambia a AUSENTE, conservar evaluacion, dejarla pendiente de revision y excluirla de indicadores. AsistenciaCierreService::reabrir no realiza este tratamiento; el BPMN actual no lo presenta como implementado.');
daily.confirm=daily.confirm.filter(x=>!x.startsWith('Quien resuelve la revision'));
note(module.inconsistencies,'Responsable confirmado por usuario: solo el evaluador original resuelve la revision de la evaluacion afectada; no se acordo excepcion administrativa. Regla pendiente de implementacion.');
note(daily.confirm,'Bajo que condiciones una evaluacion revisada puede volver a los indicadores; que ocurre si el evaluador original ya no tiene acceso.');

const supervisor=find('evaluacion-supervisor');
node(supervisor,'Task_Authorize').documentation='EvaluacionesPolicy::canEvaluateType y canEvaluateSupervisorTarget: permiso y actor residente/override, es_supervisor, scope; si MINA sin grupo acepta relacion minera activa o grupo historico. No prueba subordinacion directa al residente. Fuera de MINA no exige esa relacion; admin override omite relacion, no marca es_supervisor.';
node(supervisor,'Task_Calc').documentation='EvaluacionSupervisorService::create y SupervisorEvaluationTemplate: normaliza respuestas y calcula resultado; actor desde cuenta autenticada, estado REGISTRADA. Sin personal_id solo isPrivileged. No requiere asistencia o parada.';
node(supervisor,'Task_Score').documentation='SupervisorEvaluationTemplate: bloques A, B y C separados. StoreEvaluacionSupervisorRequest valida respuestas; identidad no se toma del formulario.';
add(supervisor,{id:'Task_Duplicate',type:'businessRuleTask',name:'Comprobar duplicado si hay grupo',lane:'Lane_System',col:5,documentation:'EvaluacionSupervisorService::hasDuplicate: evaluado_id + grupo_trabajo_id + fecha; sin grupo devuelve false.'});
add(supervisor,{id:'Gateway_Duplicate',type:'exclusiveGateway',name:'Duplicado detectado?',lane:'Lane_System',col:6,documentation:'EvaluacionSupervisorService::create: EVAL_SUP_DUPLICATED.'});
add(supervisor,{id:'End_Duplicate',type:'endEvent',name:'Rechazado: mismo contexto',lane:'Lane_Resident',col:7,documentation:'EvaluacionSupervisorService::hasDuplicate; no se garantiza unicidad sin grupo.'});
node(supervisor,'Task_Calc').col=7;
node(supervisor,'End_Saved').col=8;
flow(supervisor,'Flow_5','Task_Score','Task_Duplicate');
flow(supervisor,'Flow_DuplicateCheck','Task_Duplicate','Gateway_Duplicate');
flow(supervisor,'Flow_DuplicateNo','Gateway_Duplicate','Task_Calc','No / sin grupo');
flow(supervisor,'Flow_DuplicateYes','Gateway_Duplicate','End_Duplicate','Si');
note(supervisor.confirm,'Permitir varias evaluaciones del mismo supervisor y fecha cuando no hay grupo: actualmente hasDuplicate no lo bloquea.');

const resident=find('evaluacion-residente');
node(resident,'Task_Authorize').name='Validar permiso, Gerencia y residente';
node(resident,'Task_Authorize').documentation='EvaluacionesPolicy::isManagementActor: ADMIN/SUPERADMIN, rol que contiene GERENTE u override. canEvaluateResidentTarget exige permiso y puesto con RESIDENTE. createResidente permite actor sin Personal solo si isPrivileged.';
node(resident,'Gateway_Allowed').name='Actor y destino autorizados?';
node(resident,'End_Duplicate').name='Acceso o identidad rechazados';
node(resident,'Task_Calc').documentation='EvaluacionDesempenoService::createResidente; ResidentEvaluationTemplate::calculate, periodo startOfMonth, destino null, actor autenticado, REGISTRADA. No depende de mina, grupo o asistencia.';
add(resident,{id:'Task_Period',type:'businessRuleTask',name:'Normalizar mes y buscar duplicado',lane:'Lane_System',col:5,documentation:'EvaluacionDesempenoService::createResidente: residente_id + periodo_mes. Comprueba exists antes de create, no una transaccion con bloqueo en este metodo.'});
add(resident,{id:'Gateway_Period',type:'exclusiveGateway',name:'Periodo disponible?',lane:'Lane_System',col:6,documentation:'EvaluacionDesempenoService::createResidente: EVAL_RESIDENT_DUPLICATED.'});
add(resident,{id:'End_Period',type:'endEvent',name:'Rechazado: residente y mes repetidos',lane:'Lane_Management',col:7,documentation:'EvaluacionDesempenoService::createResidente.'});
node(resident,'Task_Calc').col=7;
node(resident,'End_Saved').col=8;
flow(resident,'Flow_5','Task_Score','Task_Period');
flow(resident,'Flow_PeriodCheck','Task_Period','Gateway_Period');
flow(resident,'Flow_PeriodYes','Gateway_Period','Task_Calc','Si');
flow(resident,'Flow_PeriodNo','Gateway_Period','End_Period','No');
module.inconsistencies=module.inconsistencies.filter(x=>!x.startsWith('EvaluacionDesempenoService conserva'));
note(module.inconsistencies,'Existe createSupervisor legacy en EvaluacionDesempenoService, pero las rutas actuales inspeccionadas usan EvaluacionSupervisorController y el servicio dedicado. No se presenta el metodo legacy como endpoint activo.');
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
