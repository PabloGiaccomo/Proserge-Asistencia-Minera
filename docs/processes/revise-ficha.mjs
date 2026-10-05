import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const module=catalog.modules.find(m=>m.slug==='personal');
const svc='app/Modules/Personal/Services/PersonalFichaService.php';
const old=module.processes.find(p=>p.slug==='ficha-temporal-revision');
const find=id=>old.nodes.find(n=>n.id===id);
old.title='Completar y revisar ficha temporal';
old.summary='El trabajador abre un enlace ya activo, completa la ficha y RR.HH. aprueba u observa. Activar y enviar el enlace son operaciones anteriores independientes.';
find('Start_Create').name='Trabajador abre enlace temporal';
find('Start_Create').lane='Lane_Worker';
find('Start_Create').documentation=`${svc}::resolveToken`;
find('Task_Link').name='Resolver token, vigencia y modo';
find('Task_Link').documentation=`${svc}::resolveToken: invalid, disabled, expired, readonly o edit; registra acceso. No envia correo en esta solicitud.`;
find('Task_Submit').col=3;
find('Task_Validate').col=4;
find('Task_Review').col=5;
find('Gateway_Result').col=6;
find('Task_Observe').col=7;
find('Task_Approve').col=7;
find('End_Contract').col=8;
if(!find('Gateway_Token')) old.nodes.push({id:'Gateway_Token',type:'exclusiveGateway',name:'Link permite editar?',lane:'Lane_System',col:2,documentation:`${svc}::resolveToken: solo mode edit permite continuar el formulario.`});
if(!find('End_LinkUnavailable')) old.nodes.push({id:'End_LinkUnavailable',type:'endEvent',name:'Link invalido, vencido o solo lectura',lane:'Lane_Worker',col:3,documentation:`${svc}::resolveToken; puede actualizar estado a expirado o inhabilitado.`});
old.flows.find(f=>f.id==='Flow_2').to='Gateway_Token';
if(!old.flows.some(f=>f.id==='Flow_TokenYes')) old.flows.push({id:'Flow_TokenYes',from:'Gateway_Token',to:'Task_Submit',name:'edit'});
if(!old.flows.some(f=>f.id==='Flow_TokenNo')) old.flows.push({id:'Flow_TokenNo',from:'Gateway_Token',to:'End_LinkUnavailable',name:'otro modo'});
old.nodes.find(n=>n.id==='Task_Approve').documentation=`${svc}::approve: transaccion, normaliza fechas, actualiza Personal a pendiente contractual, marca ficha APROBADO e inhabilita link; notificacion posterior.`;
old.nodes.find(n=>n.id==='Task_Observe').documentation=`${svc}::observe: transaccion, marca OBSERVADO; el trabajador puede corregir si su enlace lo permite.`;
function process(slug,title,summary,steps,flows,confirm=[]) {
 const p={slug,title,summary,evidence:[svc,'app/Modules/Personal/Controllers/PersonalFichaController.php','routes/web.php'],confirm,lanes:[{id:'Lane_User',name:'RR.HH.'},{id:'Lane_System',name:'Sistema'}],nodes:steps.map(([id,type,name,lane,col,documentation])=>({id,type,name,lane,col,documentation})),flows:flows.map(([from,to,name],i)=>({id:`Flow_${i}`,from,to,...(name?{name}:{})}))};
 const idx=module.processes.findIndex(x=>x.slug===slug);
 if(idx<0) module.processes.push(p);else module.processes[idx]=p;
}
process('activar-enlace-ficha','Activar enlace temporal de ficha','Crea, renueva o reutiliza un enlace de ficha de Personal; no implica enviarlo por correo.',[
 ['Start','startEvent','Trabajador registrado necesita ficha','Lane_User',0,`${svc}::activateTemporaryLinkForPersonal`],
 ['Request','userTask','Solicitar enlace temporal','Lane_User',1,`PersonalFichaController::activateTemporaryLink`],
 ['Check','businessRuleTask','Validar Personal y estado no CESADO','Lane_System',2,`${svc}::activateTemporaryLinkForPersonal rechaza inexistente o CESADO.`],
 ['Allowed','exclusiveGateway','Activacion permitida?','Lane_System',3,`${svc}::activateTemporaryLinkForPersonal lanza ValidationException.`],
 ['Build','serviceTask','Crear, regularizar o extender link','Lane_System',4,`${svc}::activateTemporaryLinkForPersonal: transaccion; ficha APROBADO crea nueva PENDIENTE, ficha a regularizar asegura link, link activo reutiliza/extiende.`],
 ['Done','endEvent','Enlace disponible para compartir','Lane_User',5,`${svc}::activateTemporaryLinkForPersonal devuelve ficha, link y URL.`],
 ['Reject','endEvent','Sin enlace nuevo','Lane_User',4,`${svc}::activateTemporaryLinkForPersonal: fallo de validacion.`]
],[['Start','Request'],['Request','Check'],['Check','Allowed'],['Allowed','Build','Si'],['Build','Done'],['Allowed','Reject','No']]);
process('enviar-enlace-ficha','Enviar enlace de ficha por correo','Envia un enlace activo a un correo valido y registra cuando se envio; una configuracion mailer log/array impide el envio.',[
 ['Start','startEvent','Ficha con enlace disponible','Lane_User',0,`${svc}::sendLinkByEmail`],
 ['Request','userTask','Solicitar envio al trabajador','Lane_User',1,`PersonalFichaController y ${svc}::sendLinkByEmail.`],
 ['Check','businessRuleTask','Validar mailer, enlace, correo y URL','Lane_System',2,`${svc}::sendLinkByEmail: rechaza mailer log/array, link ausente/inactivo y correo invalido; extiende ventana activa si corresponde.`],
 ['Allowed','exclusiveGateway','Envio posible?','Lane_System',3,`${svc}::sendLinkByEmail lanza ValidationException antes de Mail::send.`],
 ['Send','serviceTask','Enviar correo con URL y registrar envio','Lane_System',4,`${svc}::sendLinkByEmail: Mail::to()->send y luego emailed_at/emailed_to; si falla el envio no registra fecha.`],
 ['Done','endEvent','Correo enviado','Lane_User',5,`${svc}::sendLinkByEmail devuelve email, resent, link y url.`],
 ['Reject','endEvent','Envio rechazado','Lane_User',4,`${svc}::sendLinkByEmail: error de configuracion, correo o enlace.`]
],[['Start','Request'],['Request','Check'],['Check','Allowed'],['Allowed','Send','Si'],['Send','Done'],['Allowed','Reject','No']]);
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
