# RQ Mina: auditoria de experiencia, guardado e integraciones

Fecha: 2026-11-04, America/Lima.

Registro historico del diagnostico previo a las correcciones. Se implementaron
correcciones en el repositorio local, sin despliegue FTP. Las descripciones y
limitaciones del diagnostico que siguen corresponden al estado inicial.
El comportamiento actualizado y los permisos se documentan
en [Planes operativos](../../rq-mina-planes.md) y
[Listado de paradas](../../rq-mina-listado-ui.md).

## Objetivo y decision del usuario

El usuario necesita comprender el programa de una parada, trabajar sin saltos
innecesarios y tener certeza de que sus cambios quedaron guardados.

Decision expresada durante esta revision: **RR. HH. debe empezar a atender desde
el primer guardado con cargos validos, sin una accion posterior de envio**.
Los datos generales deben poder guardarse antes de completar los cargos.

Esta decision se implemento en el guardado web reutilizando el permiso de envio
existente, sin ampliar la matriz de permisos. La reorganizacion afecta un flujo compartido
con RQ Proserge, Man Power, Logistica y Transporte; requiere tratar guardado,
atencion e historial como una operacion funcional coherente.

## Alcance y metodo

Tres agentes revisaron independientemente guardado, navegacion e integraciones.
La revision principal contrasto sus resultados, recorrio la interfaz publicada
y ejecuto reproducciones aisladas. Se consultaron AGENTS.md, CODEX_CONTEXT,
mapas de arquitectura y archivos, reglas UX, documentacion de planes y procesos
y el grafo existente, verificando conclusiones contra codigo fuente.

Se preservaron los numerosos cambios locales preexistentes, incluidos los de
RQ Mina. La evidencia de codigo corresponde al checkout actual; no se verifico
que el codigo desplegado tenga exactamente la misma revision.

En Chrome se observaron listado, detalle, formulario de edicion, dashboard,
plan existente e importacion. No se guardaron formularios, enviaron pedidos,
recalcularon indicadores ni alteraron registros de negocio en produccion.
El plan y la importacion se abrieron con un plan_id existente: sin ese parametro,
su GET puede crear el plan inicial si falta (RQMinaPlanService.php:222-238).
El editor puede escribir automaticamente su copia local del navegador.

## Conclusion principal

Hay problemas reales de integridad y de comprension, ademas de exceso de controles.
Cambiar colores o distribuir botones no resuelve la confianza en el guardado.

Hoy guardar y enviar ya son operaciones distintas: guardar conserva BORRADOR;
enviar crea/sincroniza RQ Proserge y cambia a ENVIADO. Guardar un RQ ya enviado
actualiza RR. HH. automaticamente. El usuario eligio eliminar el paso manual de
envio; esto exige llevar esa sincronizacion al primer guardado valido.

No se demostro perdida de datos reales durante esta auditoria. Si se reprodujo
que una copia local antigua reemplaza visualmente datos nuevos y que descartarla
puede recrearla. Tambien se identificaron rutas de sobrescritura y eliminacion
que deben corregirse antes de simplificar mas el guardado.

## Recorrido actual, paso por paso

| Paso | Lo que hace el usuario | Comportamiento actual | Problema |
|---|---|---|---|
| 1 | Busca una parada | Tarjetas con Abrir plan, Dashboard, Ver, Editar y Mas | No hay una entrada unica al trabajo; los resumenes pueden contradecir el plan |
| 2 | Crea una parada | Guarda datos generales y plan inicial en transaccion, como BORRADOR | No inicia RR. HH.; los errores devueltos no se presentan correctamente |
| 3 | Entra al plan | Ve resumen, acciones administrativas, selector, tarjeta y otro resumen del mismo plan | Los cargos y las actividades quedan debajo de configuracion repetida |
| 4 | Completa personal y actividades | Pedido global de la parada y contenido de un plan comparten formulario | No queda claro que datos cambian con el plan seleccionado |
| 5 | Guarda plan operativo | Persiste en transaccion; si ya estaba ENVIADO, sincroniza RR. HH. | Copia local puede ocultar respuesta del servidor; falta control de revision |
| 6 | Quiere iniciar RR. HH. | Debe volver a detalle, despues listado, abrir Mas y Enviar | La accion final esta fuera del trabajo y exige pasos sin valor |
| 7 | Planifica transporte | Sale a Transporte con parada, plan, fecha y turno | El retorno ofrecido va a Logistica, sin regreso explicito al plan |
| 8 | Intenta importar | Abre otra pantalla | Archivo e Importar estan deshabilitados; no existe el motor |
| 9 | Consulta detalle | Ve actividades y transportes agrupados por parada | Puede mezclar planes distintos sin identificarlos |

Referencias principales: RQMinaPageController.php:291-367, :370-464;
RQMinaService.php:208-236, :395-434; index.blade.php:420-444;
plan.blade.php:36-41, :97-174, :200-222; transporte/planificacion.blade.php:81.
Todos los nombres anteriores se ubican en app/Modules/RQMina o
resources/views/rq-mina, salvo la vista de Transporte indicada.

## Hallazgos prioritarios

P1: resolver antes de ampliar o automatizar guardado. P2: corregir en la
reorganizacion del flujo. La prioridad expresa riesgo, no incidentes reales
confirmados en produccion.

### H01. P1: el borrador local puede reemplazar datos mas nuevos

- Evidencia: resources/views/rq-mina/plan.blade.php:191, :338-377;
  app/Modules/RQMina/Controllers/RQMinaPageController.php:364-367;
  resources/views/rq-mina/show.blade.php:529-533.
- El editor usa la clave rq_mina_plan_draft:rq:plan. El exito redirige al plan,
  pero la limpieza esta en detalle y borra una clave sin el sufijo del plan.
- Al entrar se restaura la copia automaticamente, sin comparar revision del
  servidor. La copia antigua se considera estado inicial sin cambios pendientes.
- Reproduccion aislada con el script real: datos nuevos del servidor aparecen
  sustituidos por datos viejos; isDirty resulta false.
- Mejora: limpiar la clave exacta tras confirmar commit, conservar revision base
  y ofrecer recuperar una copia pendiente cuando sea compatible, sin aplicarla
  silenciosamente sobre cambios posteriores.

### H02. P1: Descartar borrador puede recrear lo descartado

- Evidencia: resources/views/rq-mina/plan.blade.php:398-418.
- Descartar borra localStorage y recarga; beforeunload ejecuta saveDraft y vuelve
  a escribir la misma copia. Reproducido con los listeners reales del script.
- Mejora: suspender guardado local durante descarte y exito, y cancelar cualquier
  temporizador pendiente. Distinguir copia local de guardado en el sistema.

### H03. P1: dos editores pueden sobrescribirse sin conflicto visible

- Evidencia: app/Modules/RQMina/Services/RQMinaService.php:418-434, :1490-1502.
- El guardado reemplaza estructura completa y elimina grupos ausentes sin
  comparar una revision de contenido. Si B guarda una version anterior a la de
  A, los grupos nuevos de A pueden desaparecer del conjunto enviado por B.
- Es una ruta comprobada por lectura; no se ejecuto concurrencia sobre BD.
- Mejora: revision monotona, bloqueo y relectura coordinados para los escritores
  de la misma parada/plan; rechazar contenido obsoleto conservando el trabajo.
  El campo version actual del plan no cumple por si solo esta funcion.

### H04. P1: guardar puede eliminar asignaciones e historial

- Evidencia: app/Modules/RQMina/Controllers/RQMinaPageController.php:813-825;
  app/Modules/RQMina/Services/RQMinaService.php:1142-1195;
  app/Modules/RQProserge/Services/RQProsergeService.php:433-463.
- La normalizacion omite cargos vacios o cantidades no positivas. Esto puede
  interpretar un error de escritura como eliminacion del puesto.
- Al reducir demanda se eliminan fisicamente filas de rq_proserge_detalle,
  incluso cuando RR. HH. dispone de un retiro con estado, actor, fecha y motivo.
  Man Power puede perder su referencia segun las FK previstas en la migracion
  2026_07_23_000400_add_man_power_traceability.php:156, :277.
- El recorte consulta todas las asignaciones, sin filtrar activas
  (RQMinaService.php:1179-1183), y se llama incluso con cantidades sin cambios
  (:1087). Si hay retirados/reemplazados y el historial acumulado supera el
  cupo, un guardado ordinario tambien puede eliminar filas historicas o activas.
- El test RQMinaApiTest.php:960 exige actualmente eliminacion fisica; hay que
  actualizar el contrato y su regresion para conservar historial.
- Mejora: validar filas parciales; quitar debe ser una accion explicita. Si ya
  hay personal asignado, presentar afectados y motivo, conservar retiros e
  historial y revisar referencias de ejecucion. No retirar automaticamente
  personas por una cantidad temporalmente vacia en el formulario.

### H05. P1: programacion semanal sin identidad clara de fecha

- Evidencia: resources/views/rq-mina/partials/plan-operativo-editor.blade.php:103-109,
  :181-202; app/Modules/RQMina/Services/RQMinaService.php:1397-1418,
  :1657-1690; app/Modules/ManPower/Services/ManPowerPlanningService.php:121-129.
- El editor genera Lun-Dom con fecha vacia, aunque reciba turnos fechados.
  Cuando existen varios turnos del mismo dia de semana, selecciona el primero.
  El guardado borra los turnos de la actividad y reinserta los del formulario.
- Reproduccion aislada con activityTemplate y helpers reales: una semana mas
  el lunes siguiente produce siete filas sin fecha y pierde la cantidad del
  segundo lunes. Si solo se proporcionan dos lunes, el fallback por posicion
  coloca la cantidad del segundo en Martes. Se comprobo el HTML generado;
  la posterior persistencia se deduce del servicio y no se ejecuto sobre BD.
- Man Power interpreta fecha nula mas dia de semana como patron recurrente.
  Por eso no basta cambiar el titulo de Semana: el guardado puede convertir
  una fecha particular en recurrencia y reducir varias semanas a siete filas.
- En interfaz publicada se observo un rango de varias semanas con encabezado
  de una sola semana, sin selector semanal y sin fechas visibles junto a Lun-Dom.
- Mejora: conservar fechas al editar y definir expresamente si cada plan usa
  fechas concretas o un patron semanal. Para programacion fechada, selector de
  semana dentro del rango y dia con fecha; proteger excepciones ya registradas.
  No convertir datos existentes ni repetir cantidades sin una regla confirmada.
- La recurrencia semanal es una modalidad intencional existente
  (docs/man-power-planificacion.md:53 y ManPowerApiTest.php:1144). Tambien se
  admiten fechas concretas (RQMinaApiTest.php:213). Hay que conservar ambas
  semanticas; no resolver el defecto imponiendo fechas a todos los datos legacy.

### H06. P1: guardado/atencion de RR. HH. necesita atomicidad

- Evidencia: app/Modules/RQMina/Services/RQMinaService.php:395-409;
  app/Modules/RQProserge/Services/RQProsergeService.php:117-161;
  app/Modules/RQMina/Policies/RQMinaPolicy.php:77-95.
- Enviar guarda ENVIADO antes de sincronizar RR. HH. sin transaccion comun.
  Un fallo puede dejar estado parcial y el reintento normal exige BORRADOR.
- Sin cargos, la sincronizacion retorna null y send no comprueba ese resultado;
  puede comunicar envio correcto sin crear pedido RR. HH. Deducido del codigo,
  sin ensayo de escritura en produccion.
- Mejora elegida: incorporar primer guardado valido y sincronizacion a una
  misma transaccion, con bloqueo e idempotencia; confirmar exito solamente al
  completar ambas escrituras. Notificar despues del commit sin duplicados.

### H07. P2: errores de validacion invisibles y datos que no se restituyen

- Evidencia: app/Modules/RQMina/Controllers/RQMinaPageController.php:402, :455;
  resources/views/rq-mina/create.blade.php:60-102.
- Se devuelve withErrors/withInput, pero Nueva y Editar rellenan desde copyData,
  no usan old() ni muestran los errores del formulario.
- Mejora: conservar campos, mostrar resumen y error junto al campo, llevar foco
  al primer problema y confirmar por separado cuando el guardado si termino.

### H08. P2: cambiar fechas generales puede dejar planes fuera del rango

- Evidencia: app/Modules/RQMina/Controllers/RQMinaPageController.php:774-792;
  app/Modules/RQMina/Services/RQMinaService.php:371-384;
  app/Modules/RQMina/Services/RQMinaPlanService.php:266-290.
- La web comprueba presencia, pero updateGeneral no valida orden ni todos los
  planes existentes. Acortar la parada puede confirmar exito y bloquear despues
  el guardado del plan. Fechas invertidas al crear pueden generar excepcion.
- Mejora: validacion compartida web/API y comprobacion del impacto en planes,
  turnos y transportes antes de guardar nuevas fechas.

### H09. P2: navegacion sin origen ni filtros

- Evidencia: resources/views/rq-mina/index.blade.php:420;
  plan.blade.php:41, :220; show.blade.php:83; create.blade.php:30, :126;
  resources/views/transporte/planificacion.blade.php:81, :99.
- Volver fuerza pantallas intermedias y pierde busqueda/pagina. Volver y Cancelar
  pueden tener destinos distintos. Transporte retorna a Logistica.
- Mejora: una entrada Abrir parada, secciones dentro de su contexto y retorno
  explicito con filtros. Los retornos entre modulos deben validar destinos
  internos permitidos; no aceptar cualquier URL proporcionada por el cliente.

### H10. P2: resumenes de plan y transporte contradictorios

- Evidencia: resources/views/rq-mina/index.blade.php:68, :316-318, :382-406;
  app/Modules/RQMina/Controllers/RQMinaPageController.php:700;
  resources/views/rq-mina/show.blade.php:249.
- Con plan puede significar solo que existe el plan inicial vacio. Transporte
  se cuenta desde la estructura legacy y omite requerimientos de los planes.
  Detalle recorre grupos de todos los planes sin separar identidad/estado.
- Confirmacion visual: una tarjeta indicaba Sin transporte y su plan mostraba
  un requerimiento. Esto no demuestra que hubiera un servicio ya asignado.
- Mejora: una lectura preparada por servicio con origen y estado explicitos:
  requerimientos, servicios asignados, planes con actividades y plan consultado.

### H11. P2: controles disponibles que no completan una tarea

- Importar es una accion destacada, pero destino tiene archivo e Importar
  deshabilitados. Confirmado en navegador y docs/rq-mina-planes.md.
- Areas/grupos y dos campos Area cambian de significado; Agregar area termina
  mostrando Quitar grupo. Evidencia: partial plan-operativo-editor.blade.php:212,
  :355-361, :498.
- El pedido global se edita dentro de un plan; en archivado puede seguir
  pareciendo editable aunque no haya forma de guardarlo.
- Mejora: retirar el acceso operativo a funciones no implementadas, llevar
  configuracion de planes a Acciones y separar pedido global de actividades.

### H12. P2: contrato del listado API roto y autorizacion por revisar

- Evidencia: app/Modules/RQMina/Services/RQMinaService.php:124-130;
  app/Modules/RQMina/Controllers/RQMinaController.php:29-54;
  app/Modules/RQMina/Resources/RQMinaResource.php:13.
- El servicio devuelve contenedor paginado y el controlador intenta convertir
  todos sus campos en Resources de modelos. Reproduccion aislada, sin BD:
  Exception: Property [id] does not exist on this collection instance.
- Index tampoco comprueba explicitamente rq_mina.ver; aplica alcance de mina.
  No se realizo una prueba HTTP de explotacion ni se comprobo version desplegada.
- Mejora: serializar items y metadata por separado, validar permiso de lectura
  y probar scope antes de reutilizar esta API para navegacion dinamica.

## Validacion visual desktop y mobile

Desktop observado: viewport 1920 x 855. El pedido empezaba a 835 px, actividades
a 1258 px y Guardar a 2624 px, con una sola actividad. La primera pantalla estaba
dominada por configuracion y informacion repetida del plan.

Mobile emulado: 390 x 844, menu lateral ya cerrado al estabilizar el layout.
El pedido empezaba aproximadamente a 1526 px. Tablas de personal y turnos
requerian 820 y 760 px respectivamente dentro del ancho del celular. Se confirma
el desplazamiento horizontal; no se afirmaron solapamientos permanentes a partir
de la transicion de tamano. No sustituye pruebas tactiles en dispositivo real.
Se restauro el viewport original al terminar.

## Propuesta funcional para revisar

Recomendacion: mantener los servicios y estructuras existentes cuando conserven
historial, reorganizando el trabajo en un unico espacio de parada.

1. Listado con una accion principal **Abrir parada** y filtros conservados.
2. Cabecera con lugar, nombre, fechas y estado entendible; **Volver a paradas**.
3. Secciones: **Resumen**, **Pedido de personal**, **Programa de trabajo**,
   **Transporte** y **Seguimiento**. Pedido pertenece a parada; programa y
   requerimientos de transporte muestran siempre el plan consultado.
4. Selector de plan compacto. Nuevo, Editar datos, Duplicar y Archivar en
   **Acciones del plan**. Actividades y fechas visibles primero.
5. Accion **Guardar cambios** visible mientras se trabaja. Estado separado:
   **Cambios pendientes**, **Guardando**, **Guardado a las HH:mm** o
   **No se pudo guardar; tus cambios siguen aqui**.
6. Con datos generales solamente, persiste sin inventar cargos. Primer guardado
   con cargos validos crea el pedido RR. HH. automaticamente. Confirmacion:
   **Guardado. RR. HH. ya puede atender el pedido de personal**. Se elimina el
   paso manual Enviar del recorrido normal, conforme a la decision del usuario.
   En la transicion inicial se puede conservar ENVIADO como estado interno del
   RQ cuando exista RQ Proserge sincronizado; el usuario ve **Pedido registrado
   en RR. HH.**. BORRADOR permanece para preparacion sin pedido valido. El estado
   de cada plan sigue siendo independiente.
7. Guardados posteriores actualizan demanda con revision y protecciones. Un
   cargo incompleto no se elimina en silencio. Reducir o quitar personal ya
   atendido muestra impacto y conserva historial; esa confirmacion de cambio
   sensible no debe convertirse en un envio obligatorio para cada guardado.
8. Transporte ofrece **Volver al programa de la parada** con el mismo plan,
   fecha y turno. Seguimiento presenta pendientes y siguiente responsable.

```mermaid
flowchart LR
    A[Paradas] --> B[Abrir parada]
    B --> C[Guardar datos generales]
    C --> D[Preparar pedido y programa]
    D --> E[Guardar cambios con cargos validos]
    E --> F[Confirmar parada y pedido RR. HH. juntos]
    F --> G[RR. HH. atiende y el usuario ve seguimiento]
    F --> D
```

Alternativas valoradas: corregir solo defectos mantiene la fragmentacion;
reconstruir todo el modulo aumenta alcance y riesgo. La opcion recomendada es
corregir fiabilidad primero y consolidar el recorrido sobre servicios existentes.
La regla de guardado elegida ya esta incorporada; quedan por confirmar patron
semanal frente a fechas concretas y responsables autorizados de iniciar atencion.

## Permisos e integraciones que la propuesta debe resolver

- No equiparar actualizar con enviar silenciosamente para cualquier rol:
  hoy son permisos independientes. Antes de habilitar guardado automatico con
  atencion RR. HH., definir y probar la capacidad autorizada de esos operadores,
  revisar roles existentes y planear una transicion explicita sin ampliarlos
  automaticamente. Como base conservadora, iniciar atencion desde Guardar debe
  reutilizar la comprobacion backend de enviar hasta acordar una capacidad
  equivalente. Los usuarios que solo preparan deben conocer esa limitacion y
  nunca recibir una confirmacion falsa de atencion. No se modificaron permisos
  durante esta auditoria.
- BORRADOR no significa privado para todos los modulos: LogisticaDashboardService
  (:1722-1724), TransportePlanningService (:449-458) y ParadaHerramientaService
  (:48-55, :346-353) leen segun otros criterios. No prometer invisibilidad global.
- Conservar alcance por mina, propiedad y pertenencia del plan, rangos, archivo
  de consulta, IDs, documentos privados y eventos de atencion logistica.
- Guardar demanda no debe borrar evidencia de Logistica. Quitar un transporte
  atendido requiere una operacion trazable; revisar omisiones en el payload
  (RQMinaService.php:1510-1518).
- Duplicar copia estructura sin asignaciones, ejecucion, placas ni evidencia.
- Estado laboral y contratos siguen perteneciendo exclusivamente a Personal.

## Orden de trabajo y archivos previstos

| Entrega | Contenido | Archivos principales previstos |
|---|---|---|
| 1. Guardado confiable | Copia local, errores, fechas, revision, preservacion de turnos e historial | RQMinaPageController, RQMinaService, RQMinaPlanService, create/plan y parciales de edicion |
| 2. Guardar inicia RR. HH. | Transaccion, idempotencia, permisos acordados, confirmacion y notificaciones | RQMinaService, RQProsergeService, controladores web/API, policies y pruebas de permisos |
| 3. Recorrido unico | Entrada a parada, secciones, botones, retornos y resumenes coherentes | index/show/plan/dashboard, vista de Transporte, servicios de lectura y rutas |
| 4. Programa y mobile | Fechas/semana, densidad, navegacion por grupos y tablas utilizables | partial plan-operativo-editor, CSS de RQ Mina, JS del modulo y pruebas de navegador |

Una revision persistente de contenido puede requerir migracion incremental;
no se debe asumir que updated_at o version del plan ofrecen la granularidad
necesaria. Revisar migrations y database/setup antes de decidirla.

## Verificacion ejecutada y limites del diagnostico inicial

- Diagnostico Node: storage/app/rq-mina-audit/2026-11-04/verify-plan-draft.cjs.
  Extrae y ejecuta el script real con DOM/editor/localStorage sinteticos.
  Tres comportamientos reproducidos, salida 0. No prueba escrituras MySQL.
- Diagnostico Node: storage/app/rq-mina-audit/2026-11-04/verify-plan-calendar.cjs.
  Ejecuta el generador real de HTML con dos conjuntos sinteticos de fechas;
  reproduce fechas vacias, segunda semana omitida y lunes convertido en martes.
  Salida 0; no ejecuta persistencia PHP ni modifica archivos al correr.
- Diagnostico PHP sin BD: contenedor paginado sintetico contra RQMinaResource;
  reproduce la excepcion de serializacion. No prueba endpoint desplegado.
- php -l: PageController, RQMinaService, RQMinaPlanService y RQMinaController,
  sin errores de sintaxis. No equivale a validar reglas.
- Conexion comprobada con configuracion forzada a testing y proserge_app_test:
  MySQL de prueba no accesible. No se ejecutaron suites Feature ni migraciones.
- No se ejecutaron escrituras ni fallos simulados en produccion. Atomicidad,
  concurrencia, reducciones, fechas y permisos necesitan regresiones con BD
  aislada antes de implementar y desplegar.
- No se ejecuto build ni view:cache: esta entrega no cambia Blade, CSS, JS de
  producto ni dependencias. No hay despliegue ni migracion que aplicar por ella.
- git diff --check no reporto errores en los cambios versionados existentes;
  el informe nuevo se reviso adicionalmente por separado.

Reproducciones locales, desde la raiz del proyecto:

```text
node storage/app/rq-mina-audit/2026-11-04/verify-plan-draft.cjs
node storage/app/rq-mina-audit/2026-11-04/verify-plan-calendar.cjs
```

Una salida PASS de estos diagnosticos significa que se reprodujo el defecto
actual con las condiciones sinteticas descritas; no significa que este corregido.

Pruebas existentes relevantes: RQMinaApiTest, RQMinaPlanManagementTest,
RQMinaPlanEditorUiTest, RQMinaListUiTest, RQMinaDeletionSafetyTest y pruebas de
RQ Proserge/Man Power/Transporte. Sus resultados no se presuponen verdes.

Casos de aceptacion necesarios: guardar/reabrir con mismos valores; recuperar y
descartar copia; conflicto entre editores; error con datos conservados; multiples
fechas del mismo dia de semana; primer guardado valido crea un solo RQ Proserge;
fallo revierte toda la operacion; doble clic no duplica; roles sin capacidad no
inician atencion; reduccion conserva historial; guardar la misma cantidad con
asignaciones activas y retiradas/reemplazadas no elimina filas;
plan archivado es consulta;
regreso mantiene filtros/plan/fecha/turno; desktop y mobile con varios grupos.

## Entregables de esta auditoria

Se agrego este informe. Los diagnosticos locales en storage estan ignorados por Git.
No se corrigio todavia el producto ni se alteraron datos reales. El siguiente
resultado revisable es el diseno de la primera entrega con las decisiones de
calendario y permisos resueltas; la primera prioridad es la confianza del guardado.
