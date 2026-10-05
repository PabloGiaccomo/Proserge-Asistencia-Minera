import fs from 'node:fs';
const file=new URL('./process-catalog.json',import.meta.url);
const catalog=JSON.parse(fs.readFileSync(file,'utf8'));
const module=catalog.modules.find(m=>m.slug==='personal');
const importService='app/Modules/Personal/Services/ImportPersonalService.php';
const legacy='app/Modules/Personal/Services/PersonalAntiguoService.php';
function process(slug,title,summary,evidence,steps,links,confirm=[]) {
 const p={slug,title,summary,evidence,confirm,lanes:[{id:'Lane_User',name:'RR.HH. autorizado'},{id:'Lane_System',name:'Sistema'}],nodes:steps.map(([id,type,name,lane,col,documentation])=>({id,type,name,lane,col,documentation})),flows:links.map(([from,to,name],i)=>({id:`Flow_${i}`,from,to,...(name?{name}:{})}))};
 const idx=module.processes.findIndex(x=>x.slug===slug);
 if(idx<0) module.processes.push(p);else module.processes[idx]=p;
}
const importEvidence=[importService,'app/Modules/Personal/Controllers/PersonalController.php','routes/api.php'];
process('importacion-y-regularizacion','Importacion y regularizacion: mapa','Son entradas distintas: Excel muta por lotes; registrar antiguo crea una persona; regularizar existente conserva su identidad.',[importService,legacy],[
 ['Start','startEvent','Solicitud sobre Personal','Lane_User',0,`${importService}::import; ${legacy}::create y regularizeExisting.`],
 ['Kind','exclusiveGateway','Operacion solicitada','Lane_User',1,'Las rutas y servicios distinguen archivo, alta historica y regularizacion de existente.'],
 ['Excel','serviceTask','Importar Excel por lotes','Lane_System',2,`${importService}::import: detecta formato y aplica cambios.`],
 ['Old','serviceTask','Registrar trabajador antiguo','Lane_System',2,`${legacy}::create: valida documento unico y crea Personal, ficha y posible contrato.`],
 ['Regular','serviceTask','Regularizar trabajador existente','Lane_System',2,`${legacy}::regularizeExisting: lockForUpdate, preserva identidad y devuelve advertencias.`],
 ['EndExcel','endEvent','Resumen de importacion','Lane_User',3,`${importService}::import`],
 ['EndOld','endEvent','Trabajador historico registrado','Lane_User',3,`${legacy}::create`],
 ['EndRegular','endEvent','Regularizacion revisada','Lane_User',3,`${legacy}::regularizeExisting`]
],[['Start','Kind'],['Kind','Excel','Archivo'],['Kind','Old','Alta antigua'],['Kind','Regular','Existente'],['Excel','EndExcel'],['Old','EndOld'],['Regular','EndRegular']]);
process('importar-personal-excel','Importar datos de Personal desde Excel','Detecta formato Master General, contactos o datos personales y aplica cambios por lotes con resumen. No hay preview en el metodo inspeccionado.',importEvidence,[
 ['Start','startEvent','Archivo Excel cargado','Lane_User',0,`${importService}::import`],
 ['Read','serviceTask','Leer filas y encabezados','Lane_System',1,`${importService}::import: readRows, resolveHeadersAndDataRows, descarta filas vacias.`],
 ['Format','businessRuleTask','Detectar formato soportado','Lane_System',2,`${importService}::import: datos personales, contactos o Master General; formato desconocido lanza RuntimeException.`],
 ['Valid','exclusiveGateway','Formato reconocido?','Lane_System',3,`${importService}::import rechaza libro vacio o columnas invalidas.`],
 ['Apply','serviceTask','Aplicar importacion por lotes','Lane_System',4,`${importService}::importPersonnelDataRows/importContactRows y master: usan DB::transaction por chunk; no hay transaccion unica del archivo.`],
 ['Done','endEvent','Resumen creado/actualizado/omitido','Lane_User',5,`${importService}::import devuelve estadisticas; pueden existir filas omitidas.`],
 ['Reject','endEvent','Archivo rechazado','Lane_User',4,`${importService}::import lanza error ante libro vacio o formato no reconocido.`]
],[['Start','Read'],['Read','Format'],['Format','Valid'],['Valid','Apply','Si'],['Apply','Done'],['Valid','Reject','No']],['Por confirmar: si la regla de bloqueo total acordada para carga masiva de cursos/EMO tambien debe regir para el importador de Personal. El servicio inspeccionado procesa chunks.']);
process('registrar-personal-antiguo','Registrar trabajador antiguo','Crea un trabajador historico nuevo despues de validar documento y descartar duplicados.',[legacy,'app/Modules/Personal/Services/PersonalService.php','app/Modules/Personal/Services/PersonalContratoService.php'],[
 ['Start','startEvent','Trabajador historico no registrado','Lane_User',0,`${legacy}::create`],
 ['Form','userTask','Indicar documento, ficha y contrato historico','Lane_User',1,`${legacy}::create`],
 ['Check','businessRuleTask','Validar documento y ausencia de duplicado','Lane_System',2,`${legacy}::create: PersonalNormalizer::isValidDocument y guardAgainstDuplicateDocument; DNI existente exige revisar registro, no crear otro.`],
 ['Valid','exclusiveGateway','Documento nuevo valido?','Lane_System',3,`${legacy}::create lanza ValidationException si no cumple.`],
 ['Save','serviceTask','Crear Personal, ficha y contrato controlado','Lane_System',4,`${legacy}::create: DB::transaction; estado CESADO si fue cesado, de otro modo pendiente; no activa sin contrato firmado.`],
 ['Done','endEvent','Historico registrado','Lane_User',5,`${legacy}::create devuelve Personal.`],
 ['Reject','endEvent','Sin alta duplicada','Lane_User',4,`${legacy}::guardAgainstDuplicateDocument.`]
],[['Start','Form'],['Form','Check'],['Check','Valid'],['Valid','Save','Si'],['Save','Done'],['Valid','Reject','No']]);
process('regularizar-personal-existente','Regularizar trabajador existente','Mantiene la identidad de Personal, sincroniza contrato si se solicita y devuelve advertencias y marca pendiente.',[legacy,'app/Modules/Personal/Services/PersonalContratoService.php'],[
 ['Start','startEvent','Trabajador ya registrado','Lane_User',0,`${legacy}::regularizeExisting`],
 ['Form','userTask','Indicar origen y datos historicos','Lane_User',1,`${legacy}::regularizeExisting: origen ANTIGUO, HISTORICO o IMPORTADO; sincronizar_contrato y pendiente_regularizacion opcionales.`],
 ['Check','businessRuleTask','Validar origen y bloquear Personal','Lane_System',2,`${legacy}::regularizeExisting: ValidationException si origen invalido; lockForUpdate en DB::transaction.`],
 ['Sync','exclusiveGateway','Sincronizar contrato?','Lane_System',3,`${legacy}::regularizeExisting: true si se solicita o se adjunta firmado.`],
 ['Contract','serviceTask','Sincronizar contrato legacy','Lane_System',4,`${legacy}::regularizeExisting llama PersonalContratoService::syncLegacyContractForExisting y recoge warning.`],
 ['Evaluate','businessRuleTask','Verificar firmado vigente y advertencias','Lane_System',5,`${legacy}::regularizeExisting: hasSignedContract; si ACTIVO sin firmado genera advertencia; pendiente si solicitud, falta firmado o hay warnings.`],
 ['Done','endEvent','Resumen con pendiente y warnings','Lane_User',6,`${legacy}::regularizeExisting devuelve personal, contrato creado si aplica y advertencias.`]
],[['Start','Form'],['Form','Check'],['Check','Sync'],['Sync','Contract','Si'],['Sync','Evaluate','No'],['Contract','Evaluate'],['Evaluate','Done']]);
const risk='La carga de Personal por Excel aplica cambios por chunks sin preview ni transaccion global. No extender automaticamente a este importador la regla de bloqueo total acordada para cursos/EMO sin confirmacion de negocio.';
if(!module.inconsistencies.includes(risk)) module.inconsistencies.push(risk);
fs.writeFileSync(file,JSON.stringify(catalog,null,2)+'\n');
