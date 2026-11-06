# Auditoria transversal de los demas modulos

Fecha: 2026-11-04, America/Lima.

Estado: revision del checkout local terminada para los recorridos indicados.
Se conservaron los cambios existentes, incluidas las correcciones de RQ Mina.
Esta entrega agrega el diagnostico y sus reproducciones; no implementa las
correcciones propuestas ni realiza despliegue FTP.

## Resultado

Se identificaron **24 hallazgos priorizados: 10 P1 y 14 P2**. P1 indica que debe
resolverse antes de ampliar el flujo o desplegar cambios relacionados. P2 afecta
coherencia, trazabilidad, recuperacion o capacidad de completar una tarea.
Cada detalle distingue reproduccion ejecutada y conclusion por lectura de codigo.
No se demostro que estos casos hayan ocurrido con informacion real en produccion.

La prioridad es proteger datos e historial y hacer coherentes los permisos.
Despues corresponde simplificar los formularios y retornos. Hay problemas que
ocurren aunque el operador siga los pasos normales y otros que requieren una
solicitud directa, un fallo de persistencia o concurrencia.

## Hallazgos de mayor prioridad

| Referencia | Modulo | Problema | Comprobacion |
|---|---|---|---|
| [PER-01](#per-01) | Personal / Habilitacion | Aceptar o regularizar ficha existente puede borrar las minas y su historial asociado. | Cadena de servicios y cascadas verificadas; DELETE reproducido con dobles. |
| [PER-02](#per-02) | Personal | Editar trabajador expone y permite sustituir datos sensibles sin esa capacidad. | Render real con datos sinteticos y permiso sensible false; PUT trazado. |
| [PER-03](#per-03) | Contratos | Regularizar un periodo distinto puede activar usando el firmado anterior. | Ruta, periodos, archivo y activacion verificados en fuente. |
| [PER-04](#per-04) | Ficha publica | Enlace de regularizacion vencido sigue habilitando borradores. | Resolucion de token real con dobles; endpoints de escritura trazados. |
| [R1](#r1) | Transporte | Algunas escrituras omiten permiso o alcance de mina y pueden reemplazar alcances parcialmente. | Servicios reales en SQLite; frontera HTTP verificada en fuente. |
| [R2](#r2) | Logistica | Servicios y alquileres muestra requerimientos e historial de otras minas. | Consulta real con filtro sintetico y ausencia de actor/scope verificadas. |
| [R3](#r3) | Herramientas | Guardar requerimiento acepta cantidades de entrega/recepcion y actor suministrados por cliente. | Servicio real con registrar=false; persiste recepcion ficticia. |
| [OP-01](#op-01) | Asistencia | Ver todas concede marcar/cerrar grupos ajenos sin permisos de escritura. | Politica real con modelo y scope sinteticos. |
| [OP-02](#op-02) | Asistencia / Faltas | Una marca en curso puede escribirse despues del cierre y dejar una falta contradictoria. | Intercalacion deducida de bloqueos/lecturas; pendiente prueba concurrente MySQL. |
| [TR-01](#tr-01) | Dashboard API | Ver Inicio permite consultar nombres y notas de Evaluaciones sin su permiso. | HTTP real en base aislada y revision cruzada independiente. |

Los otros 14 hallazgos se detallan abajo: perdida de documentos ante errores;
reubicacion parcial de pasajeros; historial de retiro sobrescrito; promedios y
ejecucion que no se actualizan; permiso Duplicar dependiente de Crear; formulario
minero que pierde entradas; falta de conductor en el flujo web de Transporte;
error de Mi Asistencia movil; indicadores ficticios en Perfil; guardado de roles
oculto por una falla de notificacion; bitacora de Bienestar insuficiente; y falta
de limite de intentos en el login API.

## Cobertura de los recorridos

| Modulos | Recorrido inspeccionado | Alcance de la comprobacion |
|---|---|---|
| Personal, Contratos y Habilitacion | Buscar/editar, ficha publica, aceptar/observar, documentos, regularizar/renovar, examenes/intentos. | Fuente, render y funciones reales aisladas; tests focalizados. No se recorrio cada variante contractual en navegador. |
| RQ Proserge y Man Power | Asignar, reemplazar/retirar, distribuir por plan/actividad/turno, reubicar, copiar/pegar. | Fuente y reproducciones de servicios; cobertura parcial de disponibilidad e importacion. |
| Asistencia, Mi Asistencia y Faltas | Marcar, masivo, cerrar/reabrir, movil, corregir/anular, consultar ejecucion. | Politicas, servicios y tests; ensayo concurrente pendiente. |
| Evaluaciones | Evaluar desde asistencia/directa, permisos por tipo, promedios, conexion con reapertura. | Fuente, pruebas y repro sintetica; importacion historica no auditada exhaustivamente. |
| Logistica, EPP, Transporte y herramientas | Solicitar, atender, documentos, entrega/devolucion, inventario firmado, pasajeros/estado/recepcion. | Fuente, SQLite, tests PHP/JS y dashboard Logistica visual. Firma, scanner y todas las pantallas moviles no se validaron fisicamente. |
| Seguridad, Auth y Notificaciones | Roles/usuarios, scopes, sesion/token, login, bandeja/poll, destino y preferencias. | Fuente, tests y fallos inyectados; no configuracion remota cPanel/WAF ni correo real. |
| Inicio/Dashboard, Perfil y Bienestar | Permisos de tarjetas y API, indicadores personales, bloqueos/edicion/anulacion. | Fuente, HTTP y modelos sinteticos, tests y navegacion visual limitada. |
| Catalogos y System | Rutas de consulta/escritura, formularios/retornos, permisos, health y scope-check. | Lectura dirigida y tests focalizados. Sin otro defecto de igual prioridad confirmado en los caminos vistos. |

RQ Mina se uso como contexto de integracion. Sus correcciones previas no se
reabrieron ni se confundieron con defectos pendientes de otros servicios.

## Evidencia y limites generales

- Tres agentes revisaron Personal, Operacion y Recursos en paralelo. La revision
  principal cubrio servicios transversales y contrasto los hallazgos; otro agente
  volvio a comprobar PER-01, PER-02 y TR-01 sin editar codigo.
- Se consultaron AGENTS.md, CODEX_CONTEXT, mapas, reglas UX y el grafo de septiembre.
  El grafo sirvio para navegar y no se tomo como prueba del comportamiento actual.
  No se regenero ni se instalo un hook.
- **506 pruebas PHP existentes / 5554 aserciones aprobadas:** 138 / 3104 de
  seguridad, notificaciones, dashboards, Bienestar y catalogo de minas; 368 / 2450
  de Personal/contratos/habilitacion, asistencia/faltas y EPP. Estas suites verdes
  no cubren las regresiones nuevas que se enumeran en cada hallazgo.
- **6 diagnosticos transversales / 42 aserciones** reprodujeron defectos actuales.
  Su salida OK significa que se comprobo el defecto esperado, no que se corrigio.
- Reproducciones de Personal con render/dobles y JavaScript real; cinco casos
  operativos con SQLite en memoria; servicios de recursos con SQLite y archivos
  sinteticos; **9 pruebas JS de EPP** aprobadas.
- **13 comprobaciones visuales** de Inicio/dashboard Logistica, a 1440 x 1000 y
  390 x 844, aprobadas sin errores JavaScript. Renderer: 1 prueba / 35 aserciones,
  bajo transaccion. Se comprobaron tarjeta, destino, retorno, overflow y ausencia
  de POST. Un dashboard vacio no valida formularios operativos ni envios reales.
- MySQL uso exclusivamente una instancia MariaDB de prueba en
  `127.0.0.1:13376`, base `proserge_app_test`, con transacciones. El servidor
  normal no se reparo ni se uso. No hubo escrituras HTTP contra produccion.
- Los scripts, fragmentos y capturas locales quedan en
  `storage/app/module-audit/2026-11-04/`. No contienen datos reales y no se deben
  desplegar. El detalle necesario se conserva en este informe versionable.
- Quedan fuera la certificacion completa de cada pantalla, pruebas tactiles,
  concurrencia MySQL con barreras, volumen extremo, scheduler/correo remotos y
  confirmacion del esquema/version instalados en produccion.

## Orden propuesto para las correcciones

1. Personal: preservar habilitaciones e historial al actualizar fichas, aplicar
   permiso sensible a lectura/escritura y cerrar enlaces vencidos. Probar cada
   ruta de aceptacion y regularizacion con historia existente.
2. Contratos: impedir asociar un firmado anterior a otro periodo y mantener el
   contexto temporal original. No transformar esta correccion en una reparacion
   masiva de contratos sin un analisis y respaldo separados.
3. Transporte, Logistica, herramientas y Asistencia: unificar autorizacion y
   alcance en backend; conservar autoria de entregas/recepciones del servidor;
   cerrar bypass de consulta y acceso API antiguo.
4. Guardados coordinados: revertir reubicacion incompleta, proteger archivos
   frente a rollback, coordinar marca/cierre y actualizar proyecciones tras
   correcciones. Conservar eventos de retiro e historial operativo.
5. Completar recorridos: Mi Asistencia movil, conductor/edicion de Transporte,
   recuperacion del formulario minero, Duplicar independiente, Perfil y confirmacion
   de Roles. Agregar bitacora de decisiones de Bienestar y limite de login API.
6. Repetir los recorridos desktop/mobile con roles limitados y errores provocados;
   solo despues preparar un despliegue acotado con los cambios y comprobaciones.

Cada bloque necesita su regresion antes de implementarse. La revision no altera
roles, estados laborales, contratos ni reglas operativas por inferencia.

## Observaciones de experiencia complementarias

La captura movil de Logistica muestra toda la configuracion de filtros antes
de las primeras metricas: busqueda, mina, parada, EPP, estado, ficha, talla y
fechas ocupan mas de la primera pantalla. Aunque no hay desbordamiento global ni
error JavaScript, el usuario debe desplazarse para empezar a leer el resumen.
Conviene mostrar los filtros activos de forma compacta y llevar los adicionales
a un desplegable que conserve su estado. La visualizacion llamada Heatmap puede
presentarse como Faltantes por mina y EPP, usando lenguaje operativo.

En los recorridos restantes, completar primero conductor/edicion de Transporte,
recuperacion del formulario minero y coherencia de permisos elimina pasos sin
salida. Esas mejoras tienen prioridad sobre cambios de color o redistribucion
visual. La revision tactil y de todas las pantallas sigue pendiente y no queda
acreditada por las capturas parciales de este informe.

<!-- Los informes detallados se incorporan debajo desde artefactos locales. -->

## Detalle: Personal, contratos y habilitacion


Revision de solo lectura del checkout local actual. No demuestra que estos caminos se hayan ejecutado en produccion. No se modifico codigo de producto, no se desplego y no se consultaron ni escribieron datos reales. Se aplico la skill `code-review`.

### Alcance y evidencia

Se leyeron AGENTS.md, CODEX_CONTEXT, ARCHITECTURE_MAP, MAPA_ARCHIVOS, BUSINESS_RULES_RRHH, personal-busqueda y los mapas de procesos de Personal/Habilitacion. GRAPH_REPORT (2026-09-25) se utilizo como orientacion y se confirmaron las conclusiones contra fuente actual.

Trazas revisadas: permisos de entrada; listado/busqueda; edicion de trabajador; ficha publica temporal y de ingreso; autosave y envio; aprobacion/observacion; documentos y reemplazo; datos de contrato, firmado, renovacion/reingreso y regularizacion de existente; asignacion minera, intentos, convalidacion y formularios de resultados. Se inspeccionaron los tests existentes relevantes, sin ejecutar Feature/MySQL desde este subagente.

Ejecuciones aisladas:

- `php storage/app/module-audit/2026-11-04/personal-render-check.php`: render real de `personal.edit` con permiso solo `editar`, modelos sin persistir y acceso DB bloqueado. Resultado: `sensitive_permission=false`, cuenta bancaria/CUSPP/firma/huella sinteticos presentes en HTML.
- `php storage/app/module-audit/2026-11-04/personal-isolated-checks.php`: metodos reales con dobles de modelos/almacenamiento, sin Laravel/BD. Link vencido ya enviado devuelve `mode=edit`; fallo sintetico de INSERT documental deja borrado el archivo anterior; payload minero vacio ejecuta DELETE fisico de asignaciones.
- `node storage/app/module-audit/2026-11-04/personal-mining-form-check.cjs`: ejecuta funciones JS extraidas de la vista real. Nota requerida por snapshot se genera sin `required`; la vista no consume `old()` ni el error bag.

Los dobles comprueban ramas concretas, no sustituyen una prueba Feature de transaccion/cascada. El render fue estatico; no hubo navegador desktop/mobile ni comprobacion visual de solapamientos.

### Hallazgos confirmados (6, ordenados)


<a id="per-01"></a>

#### PER-01 [P1] Aceptar una ficha de un trabajador existente borra sus habilitaciones e historial minero

**Ubicacion principal:** `app/Modules/Personal/Services/PersonalService.php:540` y `:574-575`.

**Cadena:** `PersonalIngresoService.php:418-431` construye el payload de la ficha y llama `PersonalService::update` para el trabajador existente. `personalPayloadFromData`, lineas 681-700, incluye siempre `minas => []`. `update` sincroniza minas incondicionalmente (540); el array vacio ejecuta `PersonalMina::...->delete()` (574-575). No hay condicion de estado laboral, legado, confirmacion minera ni permiso de desasignacion. El camino `PersonalFichaService::approve` (924) tambien usa `personalPayloadFromFicha` (2641-2661), que incluye el mismo array vacio.

**Pasos:** trabajador ya existente con una asignacion y examenes/intentos; recibe una ficha por Ingresos; RR.HH. pulsa Agregar a Personal con periodo contractual valido (el mismo de un contrato equivalente tambien es valido). O marca No firmo contrato, camino que no prepara contrato ni encuentra un conflicto de periodos. Al confirmar, desaparecen las asignaciones mineras aunque esta pantalla solo revisa la ficha.

**Impacto:** el esquema versionado borra en cascada `personal_mina_historial` (`2026_06_06_000100_create_mina_requisitos_and_extend_personal_mina.php:82`), examenes (`2026_06_06_000200_create_mining_exam_flow_tables.php:119`) e intentos (`:147`). Se pierden referencias a resultados, convalidaciones y archivos, ademas del estado operativo. Este efecto es condicional a que el ambiente tenga esas FK del esquema versionado; no se inspecciono el esquema de produccion.

**Solucion sugerida:** actualizar ficha/identidad sin sincronizar minas; distinguir propiedad ausente de orden explicita; toda baja minera debe usar el servicio de Habilitacion, desactivar y conservar historial. Evitar el DELETE fisico incluso para una seleccion explicita vacia.

**Regresion faltante:** ampliar `PersonalIngresoPublicFlowTest::test_public_ingreso_with_existing_document_updates_existing_without_duplicate` con asignacion, historial, examen, intento y archivo existentes; afirmar que aceptacion y No firmo contrato conservan IDs, resultados y referencias. Repetir al aprobar ficha temporal.

**Validacion:** cadena completa confirmada por fuente y migraciones; rama DELETE reproducida con dobles. No se ejecuto cascada en MySQL.


<a id="per-02"></a>

#### PER-02 [P1] Editar trabajador revela y permite reemplazar datos sensibles sin el permiso correspondiente

**Ubicacion principal:** `app/Modules/Personal/Controllers/PersonalPageController.php:411-416`; `resources/views/personal/edit.blade.php:109-179` y `:303-334`.

**Pasos:** usuario con `personal.editar`, sin `personal.ver_datos_sensibles` ni su equivalente de Ingresos, abre `/personal/{id}/editar`. La ruta y `edit` aceptan editar/actualizar/editar_ficha; el controlador entrega todas las secciones, los valores completos, ficha y huella. La vista itera todas las secciones sin filtrar y dibuja firma/huella. El PUT usa `updateManual` sin preservar datos bancarios/pensionarios ni aplicar permiso sensible (`PersonalPageController.php:514-538`, `PersonalFichaService.php:267-310`).

**Comportamiento e impacto:** un permiso de edicion general accede a cuenta bancaria, CUSPP, firma y huella y puede sustituirlos. Contradice la proteccion aplicada en revision de ficha e Ingresos, y vuelve insuficiente la matriz de permisos para esos datos.

**Solucion sugerida:** resolver una capacidad sensible comun para GET y PUT; excluir datos del HTML/view model cuando falta permiso y preservar o rechazar cambios de esos campos/firma/huella en backend. Ocultar visualmente la seccion por si solo no basta.

**Regresion faltante:** agregar GET de editar y PUT de trabajador a `PersonalFichaSensitiveDataPermissionTest`, con permiso de edicion y sin permiso sensible; comprobar ausencia de valores/imagenes y persistencia inalterada. Incluir caso con permiso positivo.

**Validacion:** reproducido en el render real con valores sinteticos y bloqueo explicito de cualquier conexion DB. No se probo una escritura real.


<a id="per-03"></a>

#### PER-03 [P1] La regularizacion activa un periodo nuevo usando el firmado de un contrato anterior

**Ubicacion principal:** `app/Modules/Personal/Services/PersonalContratoService.php:1525-1538` y `:1649-1659`.

**Pasos:** trabajador con contrato anterior cerrado y `personal_contrato_datos` conservando su firmado; abrir Regularizar antiguo; elegir Vigente y un periodo nuevo que no se solape con el anterior; dejar Sincronizar contrato marcado; no adjuntar PDF nuevo. La UI ofrece asociar el firmado anterior (`resources/views/personal/antiguo/regularizar.blade.php`, bloque Contrato firmado PDF).

**Comportamiento real:** aunque no existe contrato equivalente para ese nuevo periodo, el servicio reutiliza `legacySignedFileFromContractData($datos)` sin verificar que las fechas de ese firmado correspondan al periodo seleccionado. Luego cambia `signed_at` al inicio del nuevo periodo, crea/actualiza contrato ACTIVO, activa al trabajador y limpia el cese. Puede registrar como firmado un contrato que el trabajador nunca firmo. El control de solapamiento no impide este caso, porque los periodos son distintos.

**Impacto:** viola la regla expresa de que el firmado anterior no activa el nuevo contrato; altera ademas el contexto temporal de firma y borra la situacion de cese.

**Solucion sugerida:** solo asociar el archivo legado al mismo periodo verificable o al contrato equivalente exacto. Para otro periodo crear preparacion y exigir su propio firmado mediante renovacion/reingreso. Conservar la fecha de firma original.

**Regresion faltante:** `PersonalAntiguoRegularizationTest`: contrato cerrado firmado, datos legados apuntando a ese archivo y periodo nuevo sin archivo; verificar que nunca se copie ese firmado al nuevo contrato ni se active al trabajador. Otro caso para reutilizacion legitima del mismo periodo.

**Validacion:** confirmado mediante lectura de la ruta accesible, servicio y modelo; no ejecutado en BD.


<a id="per-04"></a>

#### PER-04 [P1] Un enlace de regularizacion vencido conserva acceso de escritura a borradores

**Ubicacion principal:** `app/Modules/Personal/Services/PersonalFichaService.php:691-710`.

**Pasos:** ficha ya enviada (`submitted_at` no nulo), que necesita regularizar; RR.HH. habilita otro enlace ACTIVO con fecha de vencimiento (`ensureRegularizationLink`, 1474 y `createSecureLink`, 2099). Esperar al vencimiento y abrirlo o llamar a sus endpoints de datos/archivo-borrador.

**Comportamiento real:** `resolveToken` solo controla `expires_at` cuando la ficha nunca fue enviada. Con ficha enviada y enlace ACTIVO omite ambos bloques y devuelve `edit`, aunque haya vencido. `PublicPersonalFichaController::storeDraftData` (131-193) y `storeDraftArchivo` (87-129) solo comprueban ese modo. Los servicios guardan directamente `datos_json`, familiares, firma y reemplazan archivos; no revalidan la vigencia. El envio final si rechaza, pero las escrituras del borrador ya ocurrieron.

**Impacto:** el poseedor del enlace mantiene lectura de datos y puede modificar informacion/archivos despues del plazo autorizado; RR.HH. puede ver cambios no enviados formalmente.

**Solucion sugerida:** comprobar vencimiento de cualquier enlace de edicion, independientemente de submitted_at; separar la ventana readonly y revalidar enlace/estado/vigencia dentro de la escritura bajo bloqueo.

**Regresion faltante:** `PersonalFichaObservedResubmitTest`: ficha enviada con enlace de regularizacion reactivado; avanzar reloj despues de expires_at; GET no editable y ambos POST de borrador rechazados sin cambios en datos, familiares, firma o archivos. Repetir antes del vencimiento como caso positivo.

**Validacion:** resolveToken real reproducido con dobles: `expired=true`, `mode=edit`. POST confirmado por trazado; no HTTP/BD.


<a id="per-05"></a>

#### PER-05 [P2] Un error al reemplazar un documento elimina el archivo previo aunque la transaccion se revierta

**Ubicacion principal:** `app/Modules/Personal/Services/PersonalFichaService.php:2505-2512`.

**Pasos:** trabajador con documento existente; cargar reemplazo desde Documentos o borrador publico; provocar fallo al crear el nuevo registro o al actualizar su estado documental (por ejemplo, fallo DB posterior al borrado). `replaceFichaArchivo` elimina el archivo anterior y su fila antes de crear la nueva referencia.

**Comportamiento e impacto:** el rollback SQL restaura la referencia anterior, pero el borrado fisico en Storage ya ocurrio. El usuario recibe error y la descarga previa queda rota. La perdida afecta documentos personales y huellas y carece de recuperacion dentro del flujo. El mismo patron aparece en la copia de archivos de Ingresos, pero este hallazgo se ancla al servicio compartido de ficha.

**Solucion sugerida:** conservar el archivo previo hasta que la transaccion complete; guardar primero el nuevo, cambiar referencias y usar afterCommit para la limpieza autorizada, o versionar el anterior. Limpiar el archivo nuevo cuando el guardado falle; respetar historial requerido.

**Regresion faltante:** `PersonalDocumentoEstadoTest`: inyectar excepcion al crear la nueva referencia o marcar estado; comprobar que referencia y bytes anteriores siguen descargables y que no queda archivo nuevo huerfano. Caso exitoso con limpieza posterior al commit.

**Validacion:** metodo real invocado con disco/modelos dobles e INSERT fallido: `old_file_still_exists=false`. No se simulo un rollback MySQL completo; la no transaccionalidad del borrado es directa.


<a id="per-06"></a>

#### PER-06 [P2] Los errores de intentos de cursos mineros cierran el contexto y descartan el formulario que se debe corregir

**Ubicacion principal:** `resources/views/personal/habilitacion-minera/index.blade.php:3814-3821`; `app/Modules/Personal/Controllers/PersonalMinaHabilitacionController.php:747-753`.

**Pasos:** abrir trabajador/mina, Registrar examen realizado, seleccionar APROBADO para un requisito con nota obligatoria, ingresar fecha/observacion/archivo y omitir nota. La UI genera Nota sin required ni indicador condicional; el servicio rechaza correctamente (`PersonalMinaHabilitacionService.php:1566-1567`).

**Comportamiento real:** el controller redirige al indice con `withInput` y un error general, pero las funciones que vuelven a construir el formulario no consumen ese input: abren de nuevo con fecha de hoy y nota/observacion/vencimiento vacios. Tampoco se conserva examen/modal abierto. Las validaciones de request anteriores al try (ej. archivo >10 MB) producen error bag, y la vista principal solo dibuja session(error/success), sin presentar ese bag. El parcial EMO si implementa restauracion condicionada a su propia clave de sesion; storeAttempt de cursos no establece esa clave ni usa ese circuito.

**Impacto:** el operador debe buscar al trabajador/examen y completar de nuevo un formulario que podia corregir; una validacion de archivo puede devolverlo sin explicacion visible. Facilita reintentos con datos distintos y dificulta saber si se guardo.

**Solucion sugerida:** preservar y reabrir trabajador/mina/examen/accion que fallo, hidratar valores no archivables y mostrar errores junto al campo; informar claramente que se debe reseleccionar un archivo. Exponer si nota es obligatoria segun snapshot. Puede usarse submit asincrono con 422 sin reconstruir el contexto.

**Regresion faltante:** prueba browser de intento rechazado por nota y archivo invalido: mismo modal abierto, valores/fecha/observacion retenidos, error visible y correccion que finalmente guarda una sola vez.

**Validacion:** funciones JS reales ejecutadas por Node y trazado de POST/redirect. No se valido la interaccion en navegador.

### Limites y cobertura pendiente

No se tocaron producto, dependencias, migraciones ni builds. Los unicos artefactos nuevos de este subagente estan en esta carpeta (informe, scripts sinteticos y compilados de render). No se hizo login ni captura de datos sensibles. No se afirma que la revision sea exhaustiva: falta recorrer navegador desktop/mobile y ejecutar regresiones de los seis casos sobre el esquema aislado; importadores/exportadores, concurrencia real y catalogo/precios/EMO masivos solo tuvieron lectura parcial. En la muestra de Habilitacion se verificaron el permiso directo de registrar/programar, la confirmacion explicita de convalidacion y sus bloqueos/transacciones; no se identifico en esa muestra una escritura directa de estado laboral.

Tests existentes recomendados al coordinador (no ejecutados aqui): PersonalIngresoPublicFlowTest, PersonalFichaSensitiveDataPermissionTest, PersonalFichaObservedResubmitTest, PersonalAntiguoRegularizationTest, PersonalContratoRenewalTest, PersonalDocumentoEstadoTest y PersonalMinaHabilitacionCorrectedFlowTest. Que pasen no demuestra que los casos nuevos esten cubiertos.

## Detalle: Operacion diaria e integraciones


Fecha: 2026-11-04. Alcance: checkout local actual, incluidos cambios previos sin confirmar. Revision de codigo y recorridos; **no se implementaron cambios ni se realizo despliegue/FTP**. Se preservaron los cambios existentes.

Se encontraron seis defectos accionables: dos P1 y cuatro P2. La prueba aislada reproduce cinco comportamientos; la carrera de marcado/cierre esta confirmada por el orden de lectura/escritura del codigo, pero no se ejecuto una prueba concurrente contra MySQL.

### Hallazgos confirmados


<a id="op-01"></a>

#### OP-01 [P1] Ver todas las asistencias concede marcar y cerrar grupos ajenos

- **Ubicacion principal:** `app/Modules/Asistencia/Policies/AsistenciaPolicy.php:64-65` y `:79-80`.
- **Evidencia adicional:** `canViewAllAttendances` incluye `mi_asistencia.ver_todas_asistencias` en `:51-55`; `routes/web.php:266-268` protege las escrituras de Mi Asistencia solamente con `mi_asistencia,ver`. `MiAsistenciaPageController.php:130-164` delega marcado masivo/cierre en el servicio, que usa esta politica.
- **Disparador/pasos:** crear un rol operativo con `mi_asistencia.ver=true`, `ver_todas_asistencias=true`, scope de una mina, `asistencias.registrar=false` y `cerrar=false`; entrar a un grupo de esa mina cuyo responsable sea otra persona; marcar o cerrar desde Mi Asistencia. No requiere rol administrador ni ser responsable.
- **Resultado/impacto:** el lector obtiene `canRegisterGrupo=true` y `canCloseGrupo=true`. Puede alterar marcas y generar faltas mediante el cierre pese a las casillas de escritura desmarcadas. La excepcion de acceso de lectura termina concediendo acciones criticas.
- **Reproduccion ejecutada:** con modelos ficticios y scope en SQLite `:memory:`, salida: `registrar_directo=false`, `cerrar_directo=false`, `es_responsable=false`, `canRegisterGrupo=true`, `canCloseGrupo=true`. Es prueba de politica; no se hicieron POST sobre informacion real.
- **Mejora propuesta:** conservar el acceso de lectura/alcance por separado de la capacidad de escribir; fuera de la excepcion operativa del responsable, exigir `asistencias.registrar` o `asistencias.cerrar` segun la accion. Revalidarlo en backend y reflejarlo en botones de Mi Asistencia.
- **Regresion recomendable:** usuario lector global y con scope ve grupo ajeno, pero los POST web/API de marcado individual, masivo y cierre devuelven 403 y no escriben. Probar ademas responsable real y usuario con permisos directos.


<a id="op-02"></a>

#### OP-02 [P1] Una marcacion en curso puede modificar una asistencia que acaba de cerrarse

- **Ubicacion principal:** `app/Modules/Asistencia/Services/AsistenciaService.php:384-398`.
- **Evidencia adicional:** la comprobacion de `CERRADO` ocurre antes de esta transaccion en `:369-371`; el masivo repite el patron en `:430-432` y `:450-459`. `getOrCreateEncabezado`, `:634-670`, libera su bloqueo al terminar su propia transaccion. `AsistenciaCierreService.php:27-60` bloquea el encabezado, lo cierra y obtiene los ausentes; los escritores no participan del mismo bloqueo durante su escritura.
- **Disparador/pasos:** solicitud A empieza a marcar PRESENTE/TARDANZA, obtiene encabezado REGISTRADO y pasa el control; solicitud B cierra antes de que A empiece su transaccion de marcado, y genera una falta por el AUSENTE existente; A continua y escribe la marca sin volver a comprobar el encabezado.
- **Resultado/impacto:** quedan encabezado CERRADO, detalle PRESENTE/TARDANZA y falta ACTIVA por inasistencia. El cierre no establece una frontera efectiva frente a los lectores/marcadores que ya estaban en curso. El masivo puede producir el mismo desacuerdo para varios trabajadores.
- **Verificacion:** intercalacion determinista deducida de dos transacciones y del control fuera de ellas; **no se ha ejecutado ensayo concurrente MySQL**. El bloqueo de detalle individual no evita que B cierre primero; falta bloquear/revalidar el encabezado dentro de la misma transaccion del marcado.
- **Mejora propuesta:** usar el encabezado como bloqueo comun en marcado individual, masivo y cierre; dentro de esa transaccion revalidar `estado`, resolver/bloquear detalles y escribir. Mantener un orden de bloqueos consistente. El marcado que llegue despues del cierre debe devolver `ASISTENCIA_ALREADY_CLOSED`.
- **Regresion recomendable:** dos conexiones sobre MySQL aislado con barreras: pausar A tras leer estado abierto, completar B/cierre, reanudar A; exigir rechazo de A y coherencia entre detalles/faltas. Repetir con masivo y con A ganando el bloqueo.


<a id="op-03"></a>

#### OP-03 [P2] Volver a agregar un integrante borra la evidencia de su retiro anterior

- **Ubicacion principal:** `app/Modules/ManPower/Services/GrupoTrabajoService.php:1238-1245`.
- **Evidencia adicional:** `addAssignmentToGrupo` busca solamente grupo+trabajador en `:1226-1229`, sin filtrar la distribucion activa. Reutiliza el mismo UUID, reemplaza `rq_proserge_detalle_id` y snapshots (`:1233-1237`), cambia actor/fecha originales y pone a null los campos del retiro. La UI afirma que el historial se conserva en `resources/views/man-power/workspace.blade.php:899-900`.
- **Disparador/pasos:** distribuir un trabajador en un grupo sin asistencia iniciada; retirarlo (por arrastre o boton, con motivo); agregarlo de nuevo al mismo grupo, sea con la misma asignacion RR.HH. o con una nueva compatible.
- **Resultado/impacto:** la distribucion vuelve a ASIGNADO sobre la fila original. Se pierden motivo, fecha y actor del retiro, y puede perderse la asignacion RR.HH. original. No queda un historial append-only que pruebe el movimiento comunicado al usuario. Tambien afecta a una reubicacion seguida de retorno al grupo original.
- **Reproduccion ejecutada:** invocacion del metodo real sobre esquema SQLite minimo; habia un registro RETIRADO con `assignment-old` y motivo; despues queda **una sola fila**, el mismo `id=distribution`, `assignment-new`, ASIGNADO y los tres campos de retiro null.
- **Mejora propuesta:** conservar la distribucion anterior como evento retirado/reubicado y crear un nuevo evento de ingreso; si el esquema impone grupo+trabajador unico, introducir una bitacora inmutable antes de reutilizar la fila y conservar referencias historicas. No limpiar evidencia sin dejar un registro equivalente.
- **Regresion recomendable:** agregar->retirar->agregar y reubicar->volver conservan actor/fecha/motivo y asignacion originales; solo la distribucion nueva cuenta para disponibilidad y asistencia. Revisar unicidades y relaciones historicas antes de elegir la solucion.


<a id="op-04"></a>

#### OP-04 [P2] Una evaluacion sigue contando tras corregir la asistencia de origen a AUSENTE

- **Ubicacion principal:** `app/Modules/Evaluaciones/Services/PromedioDesempenoService.php:71-75`.
- **Evidencia adicional:** `AsistenciaCierreService.php:121-142` reabre y anula faltas sin gestionar evaluaciones; `EvaluacionesDashboardService.php:77-89` filtra pendientes de supervisor pero no invalida evaluaciones diarias cuyo detalle dejo de ser PRESENTE/TARDANZA. `EvaluacionDesempeno` no tiene estado de revision. La regla pendiente ya esta explicitamente documentada en `docs/processes/evaluaciones/index.md:39-40`.
- **Disparador/pasos:** cerrar asistencia PRESENTE, registrar evaluacion diaria, reabrir, cambiar ese detalle a AUSENTE y volver a cerrar.
- **Resultado/impacto:** se conserva correctamente la fila de evaluacion, pero sigue aportando su nota a promedios e indicadores aunque el trabajador ahora figura ausente. No se marca para revision ni se informa al evaluador original; el historial y los indicadores se contradicen.
- **Reproduccion ejecutada:** servicio real `reabrir`, cambio de estado de detalle aislado y consulta real `PromedioDesempenoService::list`; devuelve `cantidad_evaluaciones=1` y `promedio_total=20` para detalle AUSENTE. Este ensayo no recorrió los POST web ni el segundo cierre; estos tampoco incorporan tratamiento de evaluaciones en fuente.
- **Mejora propuesta:** implementar el estado de revision ya acordado: conservar la evaluacion, excluirla de agregados cuando su asistencia deje de respaldarla, y permitir que el evaluador original resuelva la revision. Aplicar el mismo predicado en promedio persistido, consulta de promedios y dashboards.
- **Regresion recomendable:** PRESENTE->evaluada->reabrir->AUSENTE no elimina historial pero deja de contar; volver a un estado valido no debe reactivar silenciosamente la nota sin la resolucion acordada. Comprobar permisos del evaluador y exclusion uniforme en graficos/API.


<a id="op-05"></a>

#### OP-05 [P2] Corregir una falta no actualiza la ejecucion real de la parada

- **Ubicacion principal:** `app/Modules/Faltas/Services/CorregirFaltaService.php:83-93`.
- **Evidencia adicional:** el servicio cambia el detalle a PRESENTE en `:74-83` y la falta a CORREGIDA en `:85-91`, pero termina sin recalcular ni invalidar la proyeccion. `FaltasService::corregirAsistencia` delega directamente (`:159-165`) y los controladores tampoco recalculan. No se encontraron observers que lo hagan. Los lectores consumen `parada_ejecucion_resumen` persistida: `AsistenciaService.php:602-632` y `RQMina/Services/ParadaDashboardService.php:220-239`.
- **Disparador/pasos:** cerrar grupo con un ausente, de modo que haya falta ACTIVA y resumen calculado; desde Faltas aplicar correccion a PRESENTE; consultar ejecucion de la parada o API de ejecucion sin recalculo manual posterior.
- **Resultado/impacto:** la falta deja de estar activa y la asistencia dice PRESENTE, mientras la ejecucion conserva el ausente anterior y un presente menos. La lectura habitual del resumen no lo reconstruye. La correccion confirmada no se refleja en cobertura/real.
- **Reproduccion ejecutada:** correccion real en SQLite cambia el detalle a PRESENTE y deja intacta la fila de resumen sembrada con presentes=0/ausentes=1. Es ensayo de servicio con esquema minimo, no ensayo completo del dashboard; la lectura del resumen sin recalculo esta verificada en fuente.
- **Mejora propuesta:** coordinar la correccion y el recalculo del grupo/actividad afectado; garantizar que un fallo de proyeccion se recupere de forma fiable y evitar mostrar exito definitivo con datos derivados obsoletos.
- **Regresion recomendable:** luego de corregir una falta cerrada, comprobar presentes/ausentes/brechas del resumen y la respuesta de ejecucion; incluir actividad principal y fallo de recalculo. El test existente `FaltasApiTest::test_corregir_falta_registra_trazabilidad_en_falta_y_asistencia` solo verifica falta/detalle.


<a id="op-06"></a>

#### OP-06 [P2] El permiso Duplicar de Man Power depende silenciosamente de Crear

- **Ubicacion principal:** `app/Modules/ManPower/Services/GrupoTrabajoService.php:613`.
- **Evidencia adicional:** `copyGrupo` admite `duplicar` en `:572-577`, pero llama `createGrupo`, cuyo primer control `:39-40` exige `crear`. `ManPowerPolicy::manageGrupos`, `:17-20`, ni siquiera incluye `duplicar`. Las rutas web de copia exigen `duplicar` (`routes/web.php:502-505`) y la vista muestra los botones con ese permiso (`resources/views/man-power/workspace.blade.php:13`, `:354`, `:368`).
- **Disparador/pasos:** rol con `man_power.ver`, `duplicar` y `asignar`, scope de mina y `crear=false`; copiar/pegar grupos preparados a otro dia. El caso aun mas limitado de solo Ver+Duplicar falla antes por `manageGrupos`.
- **Resultado/impacto:** el usuario ve y puede iniciar Copiar/Pegar, pasa el control de Duplicar, pero recibe `MANPOWER_FORBIDDEN` / No autorizado en el servicio de creacion. La matriz presenta casillas independientes y el flujo no explica el permiso adicional. En copia de dia/rango, la transaccion revierte los cambios, por lo que aqui no se afirma perdida de grupos.
- **Reproduccion ejecutada:** `copyGrupo` real con relaciones ficticias ya cargadas, `copiar_integrantes=false`, duplicar=true/asignar=true/crear=false devuelve `ok=false`, `code=MANPOWER_FORBIDDEN`, `forbidden=true`. No consulta ni modifica datos reales.
- **Mejora propuesta:** separar la autorizacion de accion del metodo interno de creacion; una copia autorizada por Duplicar debe poder usar la persistencia comun sin volver a exigir Crear. Mantener el alcance de mina y todas las validaciones del destino.
- **Regresion recomendable:** matriz de Ver+Duplicar sin Crear (con y sin Asignar), Ver+Crear sin Duplicar y rol sin ambos, tanto en copia de grupo como de dia/rango; comprobar permisos de ruta y servicio coherentes.

### Recorridos revisados

| Flujo | Entrada/filtros y accion principal | Errores, confirmacion y retorno | Revision backend |
|---|---|---|---|
| RQ Proserge | Listado operativo, busqueda de trabajador/rango, puesto, titular/suplente y regular/adicional; asignar, editar, retirar y reemplazar. | Modal de retiro identifica trabajador y exige motivo; el reemplazo conserva motivo y usa buscador; respuesta AJAX actualiza tarjeta; mensajes tecnicos de asignar/reemplazar son genericos. | Politica de mina con prioridad de scope explicito; transaccion y retiro logico; recalculo de cobertura. Se revisaron `RQProsergeService`, `CoverageService`, controladores, requests, politica y vistas de listado/detalle. |
| Man Power | Seleccion parada->plan->SAIT->fecha; turnos Dia/Noche, cargos/disponibilidad; crear grupo y distribuir con selector/arrastre; copiar dia/rango, retirar/reubicar/responsable. | Copia y cancelacion tienen modales; errores AJAX muestran toast; workspace conserva seleccion y retorna al contexto. El arrastre de retiro usa motivo automatico. | Snapshots y asignacion RR.HH. exacta; actividad unica en creacion; retiro logico; copia de destino transaccional. OP-03 y OP-06 afectan esta promesa operativa. |
| Asistencia | Periodo, destino, estado y busqueda; detalle de grupo, registrar individual/masivo, cerrar y reabrir. | Formularios conservan old input; cierre tiene confirmacion explicita por faltas; reapertura es un POST directo sin motivo. Retorno al detalle. | Padron desde distribuciones activas; estados/motivos, horario, cierre y falta automatica; bloqueo de cierre/reapertura, pero no barrera comun con marcado (OP-02). |
| Faltas | Mes actual por defecto, periodo maximo anual, destino, estado/busqueda y paginacion; detalle->corregir o anular. | Corregir muestra trabajador/destino/motivo de origen y efecto PRESENTE; anular pide motivo y confirmacion nativa; retorno detalle/listado. Errores de negocio de corregir retornan sin withInput (observacion menor, fuera de los seis). | Scope, permisos de corregir/anular, transaccion y bloqueo de falta/detalle en correccion; preserva primer evento de correccion. Falta actualizacion de ejecucion derivada (OP-05). |
| Evaluaciones | Pestanas por tipo, filtros de periodo/mina/cargo/origen/busqueda; evaluar desde asistencia cerrada y formulario de criterios; diaria directa; supervisor/residente. | Formularios conservan entradas tras validacion; reglas rechazan no presentes, autoevaluacion y duplicado; enlaces retornan al contexto de Evaluaciones. | Permisos por tipo, origen/propietario/scope, detalle unico, transaccion de diaria+promedio. Se revisaron lectura/promedios y la conexion con reapertura; OP-04 ya consta como regla pendiente. Importacion historica solo lectura documental, sin ejecutar. |

### Cobertura parcial y preguntas que no se elevan a hallazgo confirmado

- **Retiro/reemplazo RR.HH. despues de distribuir en Man Power:** `RQProsergeService::retireAssignment/replaceAssignment` cambia la asignacion sin propagar el retiro a distribuciones ya creadas; `GrupoTrabajoDetalle::isDistribucionActiva` consulta solo su estado local y el padron sigue usando ese resultado. Hace falta definir si debe bloquearse el cambio, retirarse solo la distribucion futura o abrir una tarea operativa; la documentacion antigua de RQ Proserge aun dice que no estaba vinculado con Man Power. No se propone cascada sobre historia ni se cuenta como defecto de regla sin esa decision.
- **Bloqueo de Man Power por mina y fecha:** `hasAsistenciaIniciada` consulta toda la mina/dia, por lo que una asistencia iniciada puede bloquear grupos distintos. Verificar si es una congelacion operativa deliberada o deberia ser por grupo/turno. No se asume la regla.
- No se ejecutaron pruebas de concurrencia MySQL, suite Feature, scheduler, cierres, reaperturas ni recalculos sobre informacion real. No hubo acceso FTP.
- No se realizo validacion visual de desktop/mobile ni interaccion en navegador para estos modulos; los resultados sobre botones/retencion se basan en Blade/JS/controladores actuales. El error de tipo de Mi Asistencia movil lo cubre el informe transversal del agente principal para evitar duplicidad.
- No se revisaron exhaustivamente el importador historico de Evaluaciones, volumen extremo, todas las rutas legacy ni todos los permisos de dashboards antiguos. No se afirma ausencia de defectos fuera del recorrido trazado.

### Evidencia y verificaciones

- Leidos `AGENTS.md`, `CODEX_CONTEXT.md`, `ARCHITECTURE_MAP.md`, `MAPA_ARCHIVOS.md`, reglas de los cuatro dominios e indices BPMN. Skill aplicada: `code-review`.
- Consultado `graphify-out/GRAPH_REPORT.md` y ejecutado `graphify query "RQProserge ManPower Asistencia cierre Faltas Evaluaciones" --budget 900`; el grafo es anterior y la consulta salio truncada, por lo que todas las conclusiones se verificaron contra fuente. No se regenero ni versiono el grafo.
- Script aislado: `storage/app/module-audit/2026-11-04/operacion-repro.php`. Comando ejecutado: `php storage/app/module-audit/2026-11-04/operacion-repro.php` (exit 0). Informa driver `sqlite`, base `:memory:`; no usa MySQL. Crea solo esquemas/datos ficticios en memoria. `php -l` del script paso; `git diff --check -- storage/app/module-audit/2026-11-04` no produjo errores (la carpeta es un artefacto local ignorado por Git).
- El script utiliza politica real y metodos reales de servicio; usa reflexion solo para aislar dependencias de `GrupoTrabajoService`, incluyendo su metodo interno de agregar. No sustituye la suite Feature ni valida restricciones del esquema MySQL historico.
- Archivos propios creados: este informe y el script de reproduccion en la carpeta de auditoria. Ningun archivo de producto editado. No se requiere despliegue para entregar este informe; las mejoras propuestas requieren trabajo separado y regresiones focalizadas.

## Detalle: Recursos, Logistica y transporte


Fecha: 2026-11-04. Alcance: checkout local actual, incluidos sus cambios intencionales. Revision solamente: no se modifico codigo de producto, no se desplego por FTP ni se uso la base normal/local de negocio.

Se aplicaron las instrucciones del repositorio y la skill `code-review`. Se leyeron `docs/CODEX_CONTEXT.md`, los mapas de arquitectura/archivos, el informe y una consulta del grafo existente, `docs/transporte-operativo.md`, `docs/processes/logistica-operativa/index.md`, `docs/EPP_AUTORIZACION.md`, `docs/EPP_INVENTARIO.md` y el flujo firmado EPP. El grafo se uso para orientacion y sus referencias se verificaron contra el codigo actual. No se regenero.

### Hallazgos confirmados


<a id="r1"></a>

#### R1. [P1] Aplicar autorizacion y alcance de mina a todas las escrituras de Transporte

- Evidencia principal: `app/Modules/Transporte/Controllers/TransporteController.php:68` y `:77`; `app/Modules/Transporte/Services/TransportePlanningService.php:174`, `:205`, `:270` y `:376`; `routes/api.php:81` y `:86`. `StoreTransporteAlcanceRequest::authorize()` devuelve `true` (`app/Modules/Transporte/Requests/StoreTransporteAlcanceRequest.php:9`). Las rutas API solo heredan `auth.token`.
- Disparador: un usuario con token activo, sin permisos de Transporte, envia `POST /api/v1/transporte/servicios/{id}/alcances` con `{"alcances":[{"sait_snapshot":"OTRO ALCANCE"}]}` para un servicio cuyo UUID conoce. El controlador obtiene el registro global y llama directamente a `syncAlcances`, que no verifica permiso ni mina. Adicionalmente, un operador con `transportes.actualizar`, pero sin scope de la mina del servicio, puede cambiar estado o manipular pasajeros por sus UUID; esos metodos comprueban la accion pero no llaman a `canAccessMina`.
- Efecto: alteracion de servicios ajenos o sin permiso de escritura; la serializacion de la respuesta tambien devuelve su unidad y pasajeros. El filtro del listado no impide estos accesos directos. Crear/editar servicio si valida mina, de modo que la proteccion es inconsistente por accion.
- Confirmacion: en SQLite en memoria, `syncAlcances` guardo un alcance con `can_update=false`; `changeEstado(..., 'FINALIZADO')` devolvio `ok=true` con cero filas de scope. El repro tambien confirma que `syncAlcances` borra los alcances anteriores antes de validar cada fila: una fila invalida deja cero alcances cuando se usa la ruta API directa, que no abre transaccion.
- Correccion propuesta: una frontera comun de autorizacion que reciba actor, accion y servicio, valide permiso directo, mina y contexto editable antes de leer/devolver o modificar datos; aplicar al origen y destino de una reubicacion. Validar todos los alcances y reemplazarlos junto con el evento en una sola transaccion.
- Regresion necesaria: matriz de acciones con token sin permisos, permiso sin scope, scope de otra mina, alcance invalido y plan archivado; verificar 403/422 y cero cambios tanto en filas como en eventos. El test actual de scope solo prueba crear (`tests/Feature/TransporteApiTest.php:382`).


<a id="r2"></a>

#### R2. [P1] Filtrar Servicios y alquileres por las minas autorizadas

- Evidencia: `app/Modules/Logistica/Services/LogisticaDashboardService.php:212`, `:224`, `:1716` y `:1724`; entrada en `app/Modules/Logistica/Controllers/LogisticaPageController.php:60`. `serviciosPageData` no recibe actor; `serviceRows` solo exige que exista la parada y aplica filtros operativos, sin scope de mina. Tampoco consume `$filters['minas']` cuando lo llama `pageData` (`:195`). `transportOptions` carga las paradas globales (`:318`).
- Disparador: usuario no privilegiado con acceso a Servicios y scope solo para mina A abre `/logistica?tab=servicios` o selecciona una parada de mina B con `servicio_parada_id`.
- Efecto: lectura de requerimientos, placas, fechas, incidencias, recepciones, nombres de documentos e historial de otras minas. La descarga y la escritura tienen `assertTransportAccess`, pero la informacion ya se entrega en el HTML del listado.
- Confirmacion: el lector real `serviceRows(['minas' => ['mine-allowed']])` devolvio `rq-other-mine` desde SQLite sintetico. La ausencia de actor en el flujo minimo y de predicado de mina en la consulta se verifico en codigo.
- Correccion propuesta: pasar actor al contexto de Servicios y aplicar la misma regla `MinaScopeAccess` a requerimientos, selector de paradas e historial, antes del limite de 300. No confiar en el UUID elegido por el navegador ni en filtros enviados por el cliente.
- Regresion necesaria: render de Servicios para usuario con solo mina A y comprobacion de que no aparezcan paradas, placas, recepciones ni eventos de B; repetir con `servicio_parada_id` ajeno y con usuario sin scope.


<a id="r3"></a>

#### R3. [P1] No aceptar cantidades ni autoria de recepcion desde Guardar requerimiento de herramientas

- Evidencia: `app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php:181`, `:218`, `:219` y `:229`; normalizacion de datos del cliente en `:599`, `:600` y `:610`. La ruta de guardado requiere `herramientas.actualizar` (`routes/web.php:299`), mientras que entregas/recepciones requieren `herramientas.registrar` (`:300`). Los valores protegidos viajan como inputs ocultos en `resources/views/parada-herramientas/partials/tool-row.blade.php:76` y siguientes.
- Disparador: usuario autorizado para editar el requerimiento, sin permiso de registrar entregas/recepciones, modifica esos campos del POST al guardar una lista antes de su plazo. Puede indicar cantidades entregadas/recibidas, recepcion `COMPLETO`, fecha y `recepcion_registrada_por_usuario_id` de otra persona. `saveLista` persiste los datos sin las comprobaciones de fase y permiso que si realiza `updatePedido`.
- Efecto: se puede fabricar una entrega y cierre de retorno antes de comenzar la parada y atribuirlos a otro usuario; tambien un formulario abierto previamente puede sobrescribir valores de atencion mas recientes al reenviar sus inputs ocultos. No es un problema que se resuelva ocultando controles.
- Confirmacion: `saveLista` real en SQLite, con `registrar=false` y una parada futura, devolvio `ok=true` y guardo entregada=99, recibida=99, estado=COMPLETO y actor de recepcion=`OTHER ACTOR` para un requerimiento de una unidad.
- Correccion propuesta: guardar solo los campos de solicitud permitidos; conservar atencion existente desde servidor por ID, sin aceptar cantidades ni autoria del formulario de requerimiento. Las entregas y recepciones deben pasar por su servicio, validar fase/cantidades, tomar actor y fecha desde servidor y guardar historial. Sincronizar por IDs estables en lugar de destruir y reconstruir grupos/items.
- Regresion necesaria: POST de un usuario con actualizar sin registrar que incluya cantidades y actor falsos; deben conservarse los valores previos. Cubrir formulario desactualizado frente a una recepcion reciente y borrado de un item con atencion.


<a id="r4"></a>

#### R4. [P2] Revertir el retiro cuando falle la reubicacion del pasajero

- Evidencia: `app/Modules/Transporte/Services/TransportePlanningService.php:319` y `:324`. El metodo abre una transaccion, retira al pasajero y retorna directamente el resultado de `addPasajeros`; Laravel confirma una transaccion cuando su callback devuelve un array `ok=false`.
- Disparador: reubicar un pasajero a un servicio PERSONAL de la misma fecha y turno que esta lleno o no incluye su grupo entre sus alcances. El retiro del origen pasa y el alta en destino devuelve un error de negocio.
- Efecto: el usuario recibe 422, pero el origen queda `REUBICADO` sin nueva asignacion. El pasajero pierde su transporte aunque la operacion informo fallo.
- Confirmacion: repro con destino sin alcance: `ok=false`, codigo `TRANSPORTE_PASSENGER_CONTEXT_INVALID`, pasajero origen=`REUBICADO`, pasajeros destino=0. El test llamado transaccional (`tests/Feature/TransporteApiTest.php:354`) cubre solo el caso exitoso; el BPMN ya advertia este riesgo y sigue presente en checkout.
- Correccion propuesta: validar y bloquear el destino antes de retirar, y lanzar/revertir la transaccion exterior si el alta no termina correctamente; conservar la estructura de error en la frontera HTTP. Bloquear servicios en orden estable para capacidad/contexto concurrentes.
- Regresion necesaria: destino lleno, sin alcance, permiso insuficiente para alta y fallo de persistencia; origen y sus eventos deben quedar intactos y destino sin altas parciales.


<a id="r5"></a>

#### R5. [P2] Conservar documentos privados cuando el guardado de Logistica es rechazado

- Evidencia: `app/Modules/Logistica/Services/LogisticaDashboardService.php:1926` y `:1927`; `normalizeTransportPayload` llama a `removeTransportDocuments` en `:2102`, y ese metodo borra fisicamente en `:2138`, antes de validar coherencia o confirmar MySQL.
- Disparador: en Hacer cambios, marcar un documento para quitar y guardar una combinacion incoherente, por ejemplo estado ASIGNADO sin origen/placa. Tambien ocurre si posteriormente falla la actualizacion o el registro del evento.
- Efecto: el guardado se rechaza y MySQL conserva la referencia, pero el archivo ya fue eliminado del disco privado. Un rollback de MySQL no recupera el archivo; una validacion fallida hace perder evidencia que antes se podia descargar.
- Confirmacion: llamada real `updateTransportRequirement` con documento sintetico y estado ASIGNADO sin unidad: lanza el mensaje de coherencia; quedan 1 documento referenciado y `file_exists=false`. El bloqueo padre agregado recientemente esta presente y no corrige este efecto de filesystem.
- Correccion propuesta: normalizar/validar sin efectos de archivos; retirar la referencia solo al confirmar y aplicar una eliminacion posterior recuperable o archivado con auditoria. Si se conserva borrado fisico, diferirlo hasta commit, comprobar que no exista otra referencia y definir reintento de limpieza. No borrar evidencia historica requerida por eventos.
- Regresion necesaria: documento marcado para eliminar + validacion fallida, fallo SQL y fallo de evento; deben conservarse referencia y contenido original. Verificar exito y reintentos sin borrar archivos aun referenciados.


<a id="r6"></a>

#### R6. [P2] Permitir registrar conductor y corregir el servicio antes de Confirmar

- Evidencia: `resources/views/transporte/planificacion.blade.php:182` (formulario nuevo), `:193`, `:194` y `:292` (acciones). La vista completa no tiene campo `conductor_personal_id` ni formulario hacia `transporte.servicios.update`; solo ofrece Crear, Confirmar y Agregar pasajero. La regla exige conductor en `app/Modules/Transporte/Services/TransportePlanningService.php:737`.
- Disparador: operador crea un servicio PERSONAL desde la pantalla, completando placa, capacidad y grupo, y luego pulsa Confirmar.
- Efecto: todo servicio PERSONAL creado por ese formulario nace sin conductor y Confirmar responde que falta conductor. La misma pantalla no ofrece como asignarlo ni como corregir placa/capacidad/alcances, aunque existen endpoint y servicio de edicion. El flujo web queda detenido salvo intervencion por API u otro mecanismo ajeno a la interfaz.
- Confirmacion: trazado completo del formulario -> `StoreTransporteServicioRequest` -> `createServicio` -> `changeEstado` -> validacion; busqueda de controles/actions en toda la vista. No se ejecutaron acciones contra registros reales.
- Correccion propuesta: selector de conductor del sistema dentro del alta y accion Editar servicio que reutilice la validacion existente, respete permisos y recupere entradas en errores; mostrar lo que falta y llevar a esa accion antes de confirmar.
- Regresion necesaria: navegador desktop/mobile con fixture sintetico: crear PERSONAL, registrar conductor, corregir capacidad, confirmar y conservar contexto/valores ante error. Cubrir roles de solo consulta, crear y editar por separado.

### Pruebas y evidencia

Ejecutado `php storage/app/module-audit/2026-11-04/recursos-repro.php`: SQLite `:memory:` con guardia explicita de driver/base, esquemas y personas sinteticos, sin acceder a MySQL. El script vive junto a este informe; su filesystem de prueba esta exclusivamente en `storage/app/module-audit/2026-11-04/recursos-fixtures`. No es una prueba del esquema productivo ni de concurrencia MySQL.

Resultados clave:

```text
relocation_failure: ok=false; origin_passenger_state=REUBICADO; destination_passengers=0
state_without_mine_scope: scope_rows=0; ok=true; stored_state=FINALIZADO
scopes_without_permission: can_update=false; alcance guardado
request_form_writes_receipt_without_register_permission: can_register=false; before_start=true; ok=true
service_rows_ignore_mine_filter: allowed=mine-allowed; returned=rq-other-mine
invalid_update_deletes_saved_document: stored_documents_after_rejection=1; file_exists=false
```

Ejecutado `node --test tests/JavaScript/epp-signature-pad.test.mjs tests/JavaScript/epp-mini-kardex.test.mjs`: 9 pruebas pasan. Cubren tinta, resize y limpieza de firma, respuestas tardias de Kardex y cambio de trabajador. No usan base de datos.

Las suites Feature de negocio de EPP/Transporte/Herramientas/Logistica no se ejecutaron; los repros de defectos anteriores usan SQLite. Posteriormente se autorizo una comprobacion visual acotada contra la instancia MySQL aislada, detallada a continuacion. No se ejecuto build, migracion, comando de cache de vistas ni despliegue, pues no hubo cambios de producto. No se ha afirmado validacion visual integral ni concurrencia MySQL.

### Comprobacion visual complementaria

Reutiliza la estructura de `tests/Browser/home-dashboards.cjs` y una copia del renderer `HomeDashboardsTest`, alojadas exclusivamente junto a este informe. Se inspeccionaron antes de ejecutar. El renderer de roles no usa `DatabaseTransactions`, por lo que no se eligio. El `phpunit.xml` original indica 3306; se genero una configuracion QA separada con 13376 forzado y una guardia adicional en el renderer que exige driver MySQL, host `127.0.0.1`, puerto `13376` y base `proserge_app_test` antes de comenzar la transaccion. Tambien se forzaron `APP_ENV=testing`, usuario root, password/`DB_URL`/`DB_SOCKET` vacios, sesion y cache en memoria. No se uso el ambiente normal.

- Renderer: `ResourcesVisualDashboardsTest.php`, copia del test de apertura de tarjetas, con rol/usuario sinteticos y `DatabaseTransactions`. Resultado: **1 test, 35 assertions**, aprobado. No conserva cambios de dominio.
- Navegador: `recursos-visual.cjs`, Chrome headless instalado, Playwright ya disponible fuera del repositorio; todas las peticiones del navegador fueron interceptadas hacia HTML de fixtures y assets locales. No se abrio un servidor de negocio.
- Cobertura visual: **13 comprobaciones aprobadas**. En **1440 x 1000** y **390 x 844**, seis comprobaciones por viewport: existencia de tarjeta Logistica, apertura del dashboard correcto, ausencia de desborde horizontal global, destino de formularios GET, ausencia de formularios POST en el contenido de solo consulta y retorno a Inicio. Comprobacion adicional: cero errores JavaScript en ambos recorridos.
- Se inspeccionaron ambas capturas completas: filtros y tarjetas conservan disposicion legible en desktop y mobile; las tablas grandes usan desplazamiento interno, sin ampliar el ancho de toda la pagina. No se elevo otro hallazgo visual a partir de estas pantallas vacias.
- Evidencias: `recursos-visual-result.json`, `recursos-visual-fixtures/logistica-1440.png` y `recursos-visual-fixtures/logistica-390.png`, todos dentro del directorio de auditoria.
- Limites: valida entrada, estructura, responsive, enlaces y estado vacio del dashboard de Logistica. No prueba envio real de filtros ni datos poblados, formularios operativos de EPP/Transporte/Herramientas, carga/descarga documental, firma, inventario, concurrencia o accesibilidad completa. No convierte en aprobados los seis hallazgos funcionales anteriores.

### Cobertura y observaciones no elevadas a hallazgo

- Logistica: entrada y permisos por pestana, filtros de Servicios, alta/atencion/recepcion, cambios de estado, historial, documentos privados y bloqueo coordinado con la parada. Se verifico que la correccion del bloqueo padre y la conservacion de atencion al editar RQ Mina ya existen; no se reportaron como pendientes.
- Transporte: contexto parada/plan/fecha/turno, formulario y acciones disponibles, payloads, alcances, candidatos, capacidad, retiro, reubicacion, confirmacion y eventos. Los problemas promovidos tienen trazado o repro; posibles carreras de capacidad y duplicidad de unidad/conductor requieren pruebas concurrentes aisladas de MySQL y no se presentan aqui como defectos reproducidos.
- Herramientas: solicitud por grupos/items, envio, plazo, importacion, entrega y recepcion, permisos y autoria. El modulo conserva limitaciones de flujo documentadas; no se reinterpretaron reglas de envio/aprobacion que requieren validacion operativa.
- EPP: lectura dirigida del flujo habitual `ENTREGAS_CAMBIOS`, preparacion y confirmacion firmada, devolucion por saldos, alcance de trabajador/almacen, tokens de inventario, locks, libro y compensaciones, endpoints de evidencia/Kardex. El codigo actual confirma firma antes del movimiento y conserva snapshots/saldos; no se confirmo otro hallazgo de igual prioridad dentro del tiempo de esta revision. Esto no equivale a declarar libre de fallos todo EPP: quedan pendientes la suite MySQL de firmas/devoluciones/inventario y sus pruebas concurrentes, exportacion Excel/PDF y revision visual completa.
- Documentacion de procesos contiene partes anteriores al flujo EPP habitual restituido en octubre; para conclusiones funcionales se dio prioridad al codigo actual y a las guias canonicas EPP. No se reporto como defecto de producto una afirmacion documental antigua sin respaldo en checkout.

Entrega: informe y reproduccion solamente. No se requiere despliegue para estos artefactos de auditoria; los cambios propuestos necesitan implementacion y regresiones en una tarea posterior.

## Detalle: Consulta, seguridad y soporte


Fecha: 2026-11-04. Se reviso el checkout local; no se cotejo la version desplegada.
No se modificaron archivos de producto. Se usaron datos sinteticos y una instancia
MariaDB aislada, nunca la base de negocio.

### Hallazgos


<a id="tr-01"></a>

#### TR-01 [P1] La API antigua de dashboards entrega evaluaciones nominales con solo Ver Inicio

- **Fuente:** `app/Modules/Dashboard/Policies/DashboardPolicy.php:12-14`,
  `app/Modules/Dashboard/Services/DashboardService.php:232-271` y `routes/api.php:118-126`.
  `DashboardFiltersRequest::authorize` devuelve true. Las rutas heredan autenticacion,
  pero no el permiso del dashboard/tipo de evaluacion consultado.
- **Pasos:** usuario con `inicio.ver`, sin permisos de Evaluaciones y con una mina
  autorizada, obtiene token y consulta `GET /api/v1/dashboard/evaluaciones`.
- **Resultado:** 200 con ID, nombre y nota del trabajador en `ranking_top`, aunque
  la interfaz nueva no le ofrece ese dashboard. El scope limita registros, pero no
  sustituye la autorizacion del dominio ni la del tipo/propietario de evaluacion.
- **Validacion:** endpoint real con fixture sintetica bajo transaccion: permisos
  de Evaluaciones false, respuesta 200 con nombre y promedio. Revision cruzada por
  otro agente confirmo ausencia de una comprobacion alternativa. Es una brecha
  existente, no se afirma una regresion nueva ni acceso indebido en produccion.
- **Mejora:** aplicar en cada endpoint la capacidad de dashboard y los predicados
  de lectura del modulo; el endpoint principal debe incluir solo secciones
  autorizadas. Reutilizar las politicas de los dashboards nuevos.
- **Regresion:** Inicio sin Evaluaciones no devuelve nombres/notas; repetir por
  cada dominio, por tipo de evaluacion, propietario y mina.


<a id="tr-02"></a>

#### TR-02 [P2] Mi Asistencia movil falla cuando encuentra un grupo

- **Fuente:** `app/Modules/MiAsistencia/Controllers/MiAsistenciaPageController.php:176`,
  frente a `app/Modules/Asistencia/Policies/AsistenciaPolicy.php:57`.
  `AsistenciaService::miAsistenciaRows` devuelve arrays (`:167-197`).
- **Pasos:** abrir `/mi-asistencia-mobile` con un grupo disponible para la fecha y
  turno. La pagina vacia evita el problema y puede ocultarlo en pruebas basicas.
- **Resultado:** el controlador convierte el array en stdClass y lo entrega a un
  metodo que exige `GrupoTrabajo`; lanza TypeError antes de renderizar. Ademas el
  controlador busca `grupo_id`, mientras el contrato actual de la lista usa `id`.
- **Validacion:** invocacion del controlador real con el contrato de lista actual
  y autenticacion sintetica; se reprodujo el TypeError. No se recorrio un dispositivo
  fisico ni se afirmo que el enlace movil sea la entrada principal de todos los roles.
- **Mejora:** recuperar el modelo autorizado por ID para la politica y construir
  un modelo de vista coherente; reutilizar `puede_registrar` cuando corresponda sin
  sustituir la autorizacion de los POST.
- **Regresion:** GET movil con grupo, sin grupo, sin trabajador vinculado y con
  varios grupos/turnos; verificar seleccion y permiso del grupo que se muestra.


<a id="tr-03"></a>

#### TR-03 [P2] Perfil presenta minas asignadas como habilitadas y evaluaciones existentes como cero

- **Fuente:** `app/Modules/Perfil/Controllers/PerfilPageController.php:36-58`,
  `app/Models/Personal.php:70-79` y `resources/views/perfil/index.blade.php:38`, `:145-153`.
- **Pasos:** consultar Perfil con una mina activa pero `NO_HABILITADO`, o una mina
  que solo pertenece al alcance del usuario. Consultar tambien tras existir una
  evaluacion real del trabajador vinculado.
- **Resultado:** Perfil une minas del trabajador con scopes del usuario bajo el
  titulo Minas Habilitadas, sin filtrar resultado. El resumen de evaluaciones se
  inicializa literalmente en total=0 y nunca consulta evaluaciones.
- **Validacion:** dos fixtures reales bajo transaccion: mina NO_HABILITADO incluida
  en `minasHabilitadas`; evaluacion persistida=1 y resumen de Perfil=0. No se
  confunde asignacion minera o alcance de usuario con autorizacion de ingreso.
- **Mejora:** separar Minas de acceso del usuario de Habilitacion del trabajador;
  usar el resultado canonico. Consultar evaluaciones con permiso y alcance o mostrar
  que el resumen no esta disponible, sin inventar un cero.
- **Regresion:** combinaciones HABILITADO/EN_PROCESO/NO_HABILITADO, scopes sin relacion
  laboral y resumen con/sin evaluaciones y sin permiso para ver puntajes.


<a id="tr-04"></a>

#### TR-04 [P2] Un fallo de notificacion informa error despues de guardar los permisos del rol

- **Fuente:** `app/Modules/Seguridad/Controllers/RolPageController.php:108-140` y
  `app/Modules/Notificaciones/Services/NotificationService.php:159-172`.
- **Pasos:** guardar un cambio de rol y simular un fallo del servicio de notificacion
  posterior a `updateWithNotificationPreferences`.
- **Resultado:** el cambio ya esta persistido; la excepcion llega a la frontera HTTP
  en lugar de la confirmacion de guardado. El operador puede interpretar que no se
  aplicaron permisos que ya afectan a las siguientes solicitudes.
- **Validacion:** controlador y persistencia reales con excepcion inyectada en
  `NotificationService::emit`; se capturo el error y se verificaron nombre/permisos
  nuevos en la base aislada. No se afirma que las notificaciones esten fallando hoy.
- **Mejora:** confirmar la operacion principal tras commit y despachar notificacion
  con reintento/outbox; conservar auditoria obligatoria dentro de la transaccion.
  No presentar una falla secundaria como perdida del guardado principal.
- **Regresion:** fallo al emitir no pierde confirmacion ni auditoria del cambio;
  reintentar no duplica la operacion ni los avisos.


<a id="tr-05"></a>

#### TR-05 [P2] Bienestar cambia o anula bloqueos sin una bitacora de la decision

- **Fuente:** `app/Modules/Bienestar/Controllers/BienestarPageController.php:304-365`,
  `app/Models/PersonalBloqueo.php:17-30` y
  `resources/views/bienestar/show.blade.php:116-119`.
- **Pasos:** editar motivo/fechas de un bloqueo existente, o pulsar Anular y aceptar
  el confirm nativo. El endpoint de anulacion ni siquiera recibe un motivo.
- **Resultado:** edicion sobrescribe el registro; anulacion solo cambia estado a
  ANULADO. Permanece el creador original, pero no se registra autor de la modificacion,
  razon de anulacion ni valores anteriores. `updated_at` no permite reconstruirlos.
  El aviso opcional de anulacion no es una bitacora obligatoria y no cubre la edicion.
- **Validacion:** traza de formulario, controlador, modelo y migraciones; no se
  ejecuto una anulacion HTTP. No se encontro observer/tabla de historial para este
  flujo. Se preserva la regla de que el bloqueo no modifica el contrato laboral.
- **Mejora:** motivo operativo separado del motivo original y evento inmutable con
  actor, fecha y antes/despues; guardar evento y bloqueo juntos. Mostrar historial
  en detalle, con el mismo permiso de consulta.
- **Regresion:** editar y anular conservan creador, actor de la decision, motivo,
  estado/fechas anteriores; rechazo de accion sin permiso y de anulacion sin motivo.


<a id="tr-06"></a>

#### TR-06 [P2] El login API no incorpora el limite de intentos existente en la web

- **Fuente:** `app/Modules/Auth/Controllers/AuthController.php:16-35`,
  `routes/api.php:30-32`; comparar `LoginPageController.php:32-41`, `:87`.
- **Pasos:** repetir solicitudes con contrasena incorrecta para el mismo correo
  desde la misma direccion al endpoint API.
- **Resultado:** la ruta API no tiene throttle y su controlador no usa RateLimiter;
  siete fallos consecutivos siguen devolviendo 401. El formulario web bloquea
  despues de cinco intentos, por lo que el control se puede evitar cambiando de canal.
- **Validacion:** siete POST reales en el kernel de pruebas con credenciales
  sinteticas. Se revisaron rutas y bootstrap para descartar un limite global de
  aplicacion. No se audito un posible WAF/proxy del servidor desplegado.
- **Mejora:** politica comun de intentos por cuenta/IP, mensajes operativos y
  desbloqueo temporal; mantener la respuesta sin revelar existencia del usuario.
- **Regresion:** alcanzar limite devuelve 429 y no emite token; intento valido limpia
  el contador segun la politica, ventana temporal y usuarios/IP independientes.

### Cobertura y limites

Se inspeccionaron Auth y refresco de sesion, Usuarios/Roles y su proteccion
administrativa, bandeja/poll/acciones de Notificaciones, dashboards nuevos y API
legacy, Bienestar, Perfil, Mi Asistencia, Catalogos y endpoints de System.
Catalogos y System no aportaron otro defecto de igual prioridad en los caminos
inspeccionados; esto no certifica todas sus variantes. Los catalogos publicos API
son una decision documentada existente, no se etiquetaron automaticamente como fuga.

La bandeja actual vuelve a aplicar permiso/contexto/alcance antes de contar y
paginar; no se volvio a reportar como pendiente el defecto corregido en octubre.
La comprobacion visual masiva y las pruebas de concurrencia quedan diferenciadas
del analisis de codigo. No se revisaron configuracion cPanel/WAF, correo real,
archivo historico a gran volumen ni todas las combinaciones de roles.

### Evidencia ejecutada

`TransversalAuditTest.php`, guardado en esta misma carpeta, contiene seis
diagnosticos y 42 aserciones. Reproducen el comportamiento defectuoso actual,
por lo que su salida OK no indica que el producto haya sido corregido.
Se ejecuto bajo MySQL en `proserge_app_test`, puerto 13376, con
`DatabaseTransactions` y guardias de nombre de base/puerto. Los registros
sinteticos se revirtieron al terminar cada caso.
