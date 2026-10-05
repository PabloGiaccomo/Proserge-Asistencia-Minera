# REVISION EXHAUSTIVA DE PROCESOS BPMN - SISTEMA PROSERGE

**Fecha de evaluación:** Octubre 2026  
**Versión del catálogo:** 59 procesos BPMN distribuidos en 11 módulos funcionales  
**Alcance:** Revisión unitaria de cada proceso, análisis de riesgos técnicos y operacionales, propuestas de mejora de modelado BPMN 2.0 y arquitectura Laravel, y detección de procesos faltantes en la cadena de valor corporativa.

---

## 1. ESTADO DE INSTALACION DE SUPERPOWERS

Se ha instalado y configurado exitosamente la suite de **Superpowers** en el espacio de trabajo local:
- **Ruta de instalación:** `.agents/skills/`
- **Habilidades disponibles:**
  1. `using-superpowers` (enrutador metodológico y chequeo mandatorio de skills)
  2. `brainstorming` (exploración rigurosa de alternativas antes de implementar)
  3. `writing-plans` (diseño estructurado de planes de trabajo)
  4. `executing-plans` (ejecución paso a paso con trazabilidad)
  5. `systematic-debugging` (análisis de causa raíz en defectos de software)
  6. `test-driven-development` (desarrollo guiado por pruebas unitarias/feature)
  7. `verification-before-completion` (control de calidad previo a entrega)
  8. `subagent-driven-development` (orquestación distribuida de tareas)
  9. `requesting-code-review` y `receiving-code-review` (auditoría cruzada)
  10. `finishing-a-development-branch` (limpieza y cierre ordenado de ramas)
  11. `dispatching-parallel-agents` (paralelismo de agentes independientes)
  12. `using-git-worktrees` (aislamiento seguro en ramas de trabajo)
  13. `diagnosing-superpowers` y `writing-skills` (soporte y extensibilidad)

---

## 2. CRITERIOS METODOLOGICOS DE REVISION BPMN

Cada uno de los 59 diagramas ha sido evaluado bajo cuatro dimensiones críticas:

1. **Rigor BPMN 2.0 y Semántica:**
   - Correcta diferenciación entre tareas humanas (`userTask`), automáticas del backend (`serviceTask`) y reglas declarativas de validación (`businessRuleTask`).
   - Coherencia en compuertas exclusivas (`exclusiveGateway`) y paralelas (`parallelGateway`).
   - Modelado explícito de eventos de error (`errorEvent`), temporizadores (`timerEvent`) y compensaciones transaccionales.
   - Definición limpia de carriles (`lanes`) que reflejen la responsabilidad real de cada actor (Usuario, Supervisor, Residente, RR.HH., Logística, Sistema, Scheduler).

2. **Fidelidad y Riesgos Técnicos (Laravel & MySQL):**
   - Atomicidad transaccional (`DB::transaction`): evitar estados huérfanos o confirmaciones parciales.
   - Concurrencia y contención de datos (`lockForUpdate`): prevenir condiciones de carrera en reservas de cupos, asignaciones y cambios de estado.
   - Tratamiento de excepciones: evitar que arrays de error devueltos dentro de transacciones confirmen modificaciones no deseadas sin disparar rollback.

3. **Invariantes del Dominio Proserge:**
   - *Ciclo Laboral:* Nadie puede estar `ACTIVO` sin contrato firmado vigente; las renovaciones jamás sobreescriben contratos anteriores.
   - *Habilitación Minera:* El flujo de intentos es acumulativo; no convalidar automáticamente sin confirmación de usuario; los estados de Excel (`PROGRAMAR EMO`) son estados de proceso, no terminales.
   - *Asistencia y Evaluaciones:* Preservar el desacoplamiento; cuando una asistencia corregida a `AUSENTE` afecta una evaluación previa, congelarla para auditoría y excluirla de métricas sin borrarla.
   - *Seguridad:* Autenticación con limitación de tasa (rate limiting) y respeto riguroso de RBAC y scopes por mina.

4. **Resiliencia Operativa y UX:**
   - Mensajería orientada a usuarios operativos no técnicos.
   - Gestión de contingencias ante fallos de correo (SMTP/Outlook) o importaciones masivas de Excel con datos corruptos.

---

## 3. REVISION UNO POR UNO: LOS 59 PROCESOS BPMN

---

### MODULO 1: ACCESO Y SEGURIDAD (`seguridad-acceso`)

#### 1. Administrar usuarios, roles y scope (`administrar-usuarios-roles-scope`)
- **Alcance actual:** Permite asignar roles y minas visibles a los usuarios, protegiendo al último administrador del sistema.
- **Hallazgos / Qué debe mejorar:**
  - El BPMN actual no modela la validación de auto-modificación (ej. un usuario revocándose accidentalmente sus propios permisos o su propio scope de mina).
  - No contempla qué ocurre cuando se revoca el scope de una mina a un usuario que tiene actualmente cuadrillas o asignaciones activas en campo.
- **Propuesta de mejora:**
  - Agregar una `businessRuleTask` que valide: "Verificar si el usuario editado es el actor en sesión" y bloquear la auto-remoción de roles de administración.
  - Añadir una compuerta que evalúe si la reducción de scopes afecta operaciones abiertas, emitiendo una advertencia de auditoría antes del guardado.

#### 2. Iniciar sesión web (`iniciar-sesion-web`)
- **Alcance actual:** Valida credenciales, estado `ACTIVO`, límite de intentos (5 por IP/email) y crea la sesión web.
- **Hallazgos / Qué debe mejorar:**
  - El final de rechazo agrupa tanto credenciales inválidas como cuenta inactiva y bloqueo por fuerza bruta. En BPMN 2.0 y para seguridad, es vital distinguir el rechazo por bloqueo temporal (HTTP 429 Too Many Requests).
  - No modela la auditoría de accesos fallidos en base de datos.
- **Propuesta de mejora:**
  - Desacoplar la compuerta `Allowed` en dos niveles: 1) ¿Superó límite de intentos? -> Fin de error "Bloqueo por Fuerza Bruta (Rate Limit)"; 2) ¿Credenciales y estado activo válidos? -> Si no, Fin "Credenciales Incorrectas o Cuenta Inactiva".
  - Incluir `serviceTask` para registrar el evento de auditoría de login en `auditoria_accesos`.

#### 3. Iniciar sesión API (`iniciar-sesion-api`)
- **Alcance actual:** Autentica correo y contraseña vía endpoint API y entrega un Bearer token con TTL de 12 horas.
- **Hallazgos / Qué debe mejorar:**
  - *Riesgo técnico en código:* `AuthController::login` no verifica el estado `ACTIVO` del usuario ni aplica `RateLimiter`, a diferencia del login web.
  - En el BPMN no existe rama de revocación ni caducidad anticipada; los tokens no se pueden revocar manualmente desde la API.
- **Propuesta de mejora:**
  - En el BPMN, incorporar la regla de negocio: "Validar estado ACTIVO del usuario" y compuerta de rechazo "Cuenta Inhabilitada".
  - Proponer la creación de un endpoint `POST /api/v1/logout` con revocación explícita de token y modelarlo en el diagrama.

#### 4. Cerrar sesión web (`cerrar-sesion-web`)
- **Alcance actual:** Invalida la sesión web del usuario y regenera el token CSRF.
- **Hallazgos / Qué debe mejorar:**
  - El BPMN asume que cerrar sesión web es el fin del ciclo de acceso, pero no explicita que los Bearer tokens activos de la API emitidos a ese mismo usuario siguen siendo válidos en dispositivos móviles o integraciones.
- **Propuesta de mejora:**
  - Añadir un cuadro de anotación o compuerta opcional: "¿Revocar sesiones activas en otros dispositivos / tokens API asociados?" para ofrecer cierre de sesión global.

---

### MODULO 2: PERSONAL (`personal`)

#### 5. Ingreso público de colaborador (`ingreso-publico`)
- **Alcance actual:** Captura de ficha de postulante/trabajador mediante formulario abierto protegido por clave diaria.
- **Hallazgos / Qué debe mejorar:**
  - No se modela la validación de expiración de la clave diaria (la clave caduca a medianoche). Si un postulante envía el formulario con la clave del día anterior, el backend lo rechaza de forma no documentada en el flujo.
  - No muestra claramente qué sucede cuando el DNI ingresado ya existe en la base de datos de Personal (conflicto de identidad).
- **Propuesta de mejora:**
  - Incorporar al inicio una `businessRuleTask`: "Validar vigencia de clave del día" con salida a Fin de error "Clave diaria expirada".
  - Añadir compuerta exclusiva: "¿DNI ya registrado en Personal?" que derive a "Vincular a legajo existente para regularización" en vez de forzar una creación duplicada.

#### 6. Completar y revisar ficha temporal (`ficha-temporal-revision`)
- **Alcance actual:** El trabajador accede mediante enlace tokenizado, llena datos y RR.HH. aprueba u observa la ficha.
- **Hallazgos / Qué debe mejorar:**
  - El token maneja 5 estados reales (`invalid`, `disabled`, `expired`, `readonly`, `edit`), pero el BPMN simplifica esto a solo dos ramas.
  - Cuando RR.HH. "Observa" la ficha, no se ilustra el ciclo de subsanación (bucle donde el trabajador recibe notificación, reingresa, corrige los campos observados y vuelve a enviar a revisión).
- **Propuesta de mejora:**
  - Modelar el bucle iterativo de subsanación: `Observar ficha` -> `Notificar trabajador con observaciones` -> `Trabajador reingresa y subsana` -> `Reenviar a revisión`.
  - Explicitar el gateway multidireccional del token: Token vencido -> Solicitar reactivación; Token readonly -> Consulta sin edición.

#### 7. Gestión de documentos personales (`documentos-personales`)
- **Alcance actual:** Carga, validación y archivo de legajo digital (DNI, CV, antecedentes, certificados médicos, pólizas).
- **Hallazgos / Qué debe mejorar:**
  - No distingue entre documentos *obligatorios para contratación* (DNI, antecedentes, ficha médica) y documentos *secundarios* o facultativos.
  - La rama `NO_APLICA` no especifica si requiere aprobación de RR.HH. o si el trabajador puede marcarla por su cuenta (riesgo de omisión indebida de documentos de ley).
- **Propuesta de mejora:**
  - Separar la compuerta de validación: si el documento es de tipo "Crítico para Contratación", no permitir `NO_APLICA` sin visto bueno explícito de Asesoría Legal o Jefatura de RR.HH.
  - Añadir subproceso para carga masiva o descarga en ZIP estructurado por carpetas de trabajador.

#### 8. Contrato, renovación, reingreso y cierre (`ciclo-contractual`)
- **Alcance actual:** Gestión integral de contratos (Borrador, Firmado, Cesado, No Renovado).
- **Hallazgos / Qué debe mejorar:**
  - Es el proceso más delicado del sistema. En el BPMN falta visibilizar el disparo de alertas de vencimiento (30 y 15 días antes del fin de contrato) que alimentan la decisión de renovar o cesar.
  - No modela la prohibición absoluta de sobreescritura: una renovación debe crear obligatoriamente una nueva entidad `PersonalContrato` vinculada como adenda o contrato sucesivo, manteniendo intacto el contrato cerrado.
- **Propuesta de mejora:**
  - Agregar un Intermediate Timer Event: "Alerta 30/15 días previos a fecha fin".
  - Detallar explícitamente en la rama de "Renovación" la creación de un nuevo registro contractual en estado `PENDIENTE_FIRMA`, manteniendo el contrato actual `VIGENTE` hasta el último segundo de su vigencia.

#### 9. Importación y regularización: mapa macro (`importacion-y-regularizacion`)
- **Alcance actual:** Diagrama de enrutamiento que clasifica si la entrada es por archivo Excel, alta de personal antiguo o regularización individual.
- **Hallazgos / Qué debe mejorar:**
  - Es un mapa muy estático que no aporta control de flujo. Funciona como menú de navegación más que como orquestador de negocio.
- **Propuesta de mejora:**
  - Convertirlo formalmente en un diagrama de Colaboración o Call Activity que conecte las entradas de reclutamiento con los tres subprocesos de ingesta.

#### 10. Importar datos de Personal desde Excel (`importar-personal-excel`)
- **Alcance actual:** Carga de Excel (Master General o Contactos) procesando en bloques/chunks.
- **Hallazgos / Qué debe mejorar:**
  - *Debilidad técnica:* No cuenta con paso de Previsualización (Preview) ni transacción global en el método principal; si falla en el chunk 4, los chunks 1 a 3 ya quedaron guardados en MySQL.
  - Contradice la regla confirmada en habilitaciones de "bloqueo total ante errores de validación".
- **Propuesta de mejora:**
  - Modelar dos fases obligatorias en el BPMN: 1) `Parsear y Validar en Memoria/Staging` -> si hay errores bloqueantes, abortar y mostrar reporte; 2) `Confirmar e Insertar en DB::transaction`.

#### 11. Registrar trabajador antiguo (`registrar-personal-antiguo`)
- **Alcance actual:** Incorporación manual de personal que laboró en temporadas previas sin duplicar registros.
- **Hallazgos / Qué debe mejorar:**
  - No modela la verificación de si el trabajador antiguo tenía antecedentes disciplinarios, vetos o bloqueos registrados en Bienestar.
- **Propuesta de mejora:**
  - Añadir una `businessRuleTask`: "Consultar historial de bloqueos o vetos históricos en Bienestar" antes de habilitar el alta.

#### 12. Regularizar trabajador existente (`regularizar-personal-existente`)
- **Alcance actual:** Actualiza datos maestros o subsana inconsistencias en un trabajador ya existente usando `lockForUpdate`.
- **Hallazgos / Qué debe mejorar:**
  - Falta reflejar qué datos sensibles requieren aprobación de RR.HH. (ej. cambio de número de cuenta bancaria o sueldo pactado) frente a datos menores (cambio de teléfono o dirección).
- **Propuesta de mejora:**
  - Bifurcar mediante compuerta: "¿Modifica datos críticos/financieros?" -> Si sí, enviar a cola de autorización con estado `PENDIENTE_APROBACION_RRHH`.

#### 13. Activar enlace temporal de ficha (`activar-enlace-ficha`)
- **Alcance actual:** Genera o extiende el token temporal de acceso a la ficha web.
- **Hallazgos / Qué debe mejorar:**
  - No modela la revocación automática de enlaces anteriores no utilizados cuando se emite un nuevo enlace para el mismo trabajador.
- **Propuesta de mejora:**
  - Añadir una `serviceTask`: "Invalidar enlaces activos anteriores del trabajador" antes de generar el nuevo token criptográfico.

#### 14. Enviar enlace de ficha por correo (`enviar-enlace-ficha`)
- **Alcance actual:** Despacha el correo electrónico con el hipervínculo seguro.
- **Hallazgos / Qué debe mejorar:**
  - Si el servidor de correos (Outlook/SMTP) falla o rechaza el envío, el proceso termina en rechazo genérico sin alternativa para el usuario.
- **Propuesta de mejora:**
  - Añadir un Boundary Event de error de transporte de correo con rama de contingencia: "Copiar enlace manual al portapapeles para envío por WhatsApp/SMS".

---

### MODULO 3: HABILITACION MINERA (`habilitacion-minera`)

#### 15. Asignar trabajador y calcular habilitación (`asignacion-y-estado`)
- **Alcance actual:** Vincula un trabajador a una mina, evalúa requisitos y calcula el estado general (`HABILITADO`, `EN_PROCESO`, `NO_HABILITADO`).
- **Hallazgos / Qué debe mejorar:**
  - No contempla qué ocurre cuando la habilitación pasa a estar condicionada por vencimiento próximo (ej. faltan 5 días para que venza un curso de inducción general).
  - No modela el estado especial de "Habilitación Temporal / En Trámite" utilizado comúnmente en paradas de planta.
- **Propuesta de mejora:**
  - Incorporar una tarea de cálculo de semáforo: Habilitado (>30 días), Por Vencer (<=30 días) y Vencido.
  - Modelar la regla confirmada: el estado `NO_HABILITADO` por agotar intentos no bloquea autorizaciones de reintento extraordinario aprobadas por la supervisión de mina.

#### 16. Programar y resolver examen (`ciclo-examen-intento`)
- **Alcance actual:** Ciclo de vida de un examen minero o EMO (Programar -> Rendir -> Aprobado/Desaprobado/No Asistió/No Aplica).
- **Hallazgos / Qué debe mejorar:**
  - No modela el guardado del *Snapshot de Costos* (precio pactado de la clínica o capacitador en el momento exacto del intento).
  - La rama `NO_APLICA` no especifica si exige justificación obligatoria o si queda a criterio del analista.
- **Propuesta de mejora:**
  - Añadir en el guardado de la resolución la `serviceTask`: "Guardar snapshot de precio de examen e imputación de centro de costos".
  - Modelar explícitamente las 4 salidas del examen con sus transiciones correspondientes.

#### 17. Carga masiva de cursos y EMO (`carga-masiva-cursos-emo`)
- **Alcance actual:** Carga masiva de notas y certificados vía Excel con validación estricta.
- **Hallazgos / Qué debe mejorar:**
  - El BPMN refleja bien la regla de bloqueo total (si una fila es inválida, se rechaza todo el archivo). Sin embargo, no distingue entre un error sintáctico (DNI inexistente) y una advertencia funcional (curso ya aprobado en otra mina que califica para convalidación).
- **Propuesta de mejora:**
  - Separar en la fase de validación: "Errores Bloqueantes" (abortan la importación) vs "Sugerencias de Convalidación" (se presentan en un resumen interactivo para confirmación manual).

#### 18. Importación de master minero (`importacion-master-minero`)
- **Alcance actual:** Ingesta de la matriz maestro de habilitaciones provista por el cliente minero.
- **Hallazgos / Qué debe mejorar:**
  - El master de la mina muchas veces trae estados contradictorios con la base interna. El BPMN actual no modela la interfaz de resolución de discrepancias (Diff Viewer).
- **Propuesta de mejora:**
  - Incorporar una `userTask`: "Revisar discrepancias detectadas entre el Master Minero y los registros internos" antes de persistir cambios.

#### 19. Sincronizar vigencias mineras (`sincronizar-vigencias`)
- **Alcance actual:** Tarea programada (`00:10`) que revisa fechas de caducidad de exámenes médicos y cursos y actualiza estados.
- **Hallazgos / Qué debe mejorar:**
  - El BPMN es puramente lineal; no modela la emisión de notificaciones de alerta cuando un trabajador pierde su condición de `HABILITADO` y tiene una asignación futura en un RQ activo.
- **Propuesta de mejora:**
  - Añadir tras el recalculo una compuerta: "¿Trabajadores deshabilitados tienen asignaciones vigentes en RQ o Man Power?" -> Si sí, disparar evento de alerta de alta prioridad a Operaciones.

---

### MODULO 4: RQ MINA (`rq-mina`)

#### 20. Ciclo de RQ Mina (`crear-editar-enviar-requerimiento`)
- **Alcance actual:** Mapa macro de estados del requerimiento (Borrador -> Enviado -> En Atención).
- **Hallazgos / Qué debe mejorar:**
  - No modela las ramas de "Cancelación por el Cliente" ni "Cierre por Fin de Parada". Un RQ en el mundo real puede ser cancelado antes de ejecutarse.
- **Propuesta de mejora:**
  - Añadir estados terminales: `CANCELADO_POR_MINA` y `FINALIZADO/LIQUIDADO`.

#### 21. Gestionar plan operativo (`gestionar-plan-operativo`)
- **Alcance actual:** Manejo de versiones de planes operativos por especialidad/fase de la parada.
- **Hallazgos / Qué debe mejorar:**
  - No define qué ocurre con los grupos de Man Power generados con la versión 1 de un plan cuando se publica la versión 2. ¿Quedan huérfanos o se sincronizan?
- **Propuesta de mejora:**
  - Modelar la compuerta de impacto: al archivar o actualizar un plan, ejecutar una tarea de verificación de grupos de Man Power asociados para alertar discrepancias de dotación.

#### 22. Crear requerimiento de mina (`crear-rq-mina`)
- **Alcance actual:** Registro inicial de puestos requeridos, fechas, turno y mina.
- **Hallazgos / Qué debe mejorar:**
  - No modela la validación de solapamiento de fechas con otra parada en la misma mina, ni el control de techos presupuestales pactados en el contrato comercial con la minera.
- **Propuesta de mejora:**
  - Incluir `businessRuleTask`: "Validar coherencia de fechas contra el calendario maestro de paradas de la unidad minera".

#### 23. Editar requerimiento de mina (`editar-rq-mina`)
- **Alcance actual:** Modificación de puestos o perfiles en un requerimiento existente.
- **Hallazgos / Qué debe mejorar:**
  - Si el requerimiento ya estaba en estado `ENVIADO` o `EN_ATENCION` y se reducen los puestos solicitados, el BPMN no muestra el tratamiento de las personas que ya habían sido asignadas por RR.HH.
- **Propuesta de mejora:**
  - Agregar una rama de detección de sobre-asignación: si puestos requeridos < personas asignadas, emitir evento a RQ Proserge para reubicación o retiro de personal sobrante.

#### 24. Enviar requerimiento a RR. HH. (`enviar-rq-mina`)
- **Alcance actual:** Pasa el estado a `ENVIADO` y ejecuta la sincronización hacia RQ Proserge.
- **Hallazgos / Qué debe mejorar:**
  - *Grave riesgo técnico en código:* `RQMinaService::send` ejecuta `$rqMina->save()` cambiando el estado a `ENVIADO` *fuera* de la transacción que ejecuta `syncFromRqMina`. Si la sincronización lanza una excepción, el RQ queda marcado como enviado en Mina pero no existe en Proserge.
- **Propuesta de mejora:**
  - En el BPMN, modelar una transacción atómica envolvente con compensación: si `syncFromRqMina` falla, revertir el estado del RQ a `BORRADOR` y emitir error al usuario.

---

### MODULO 5: RQ PROSERGE (`rq-proserge`)

#### 25. Asignar personal a RQ Proserge (`atender-requerimiento-personal`)
- **Alcance actual:** Selecciona un trabajador disponible y lo asigna a un puesto solicitado en el RQ.
- **Hallazgos / Qué debe mejorar:**
  - Las validaciones de elegibilidad están resumidas en una sola caja genérica. Deben ser transparentes las cuatro reglas de oro:
    1. Contrato vigente firmado.
    2. Habilitación vigente en la mina específica.
    3. Ausencia de bloqueos activos en Bienestar.
    4. No asignado a otro requerimiento en el mismo rango de fechas.
- **Propuesta de mejora:**
  - Desglosar la `businessRuleTask` en una matriz explícita de elegibilidad con salidas de rechazo específicas para cada causal de no aptitud.

#### 26. Actualizar asignación de RQ Proserge (`actualizar-asignacion`)
- **Alcance actual:** Modifica fechas de inicio/fin, cargo o tipo de asignación.
- **Hallazgos / Qué debe mejorar:**
  - Si se extiende la fecha fin de la asignación, no se revalida si la habilitación minera o el contrato laboral del trabajador vencen durante el período extendido.
- **Propuesta de mejora:**
  - Añadir una `businessRuleTask`: "Revalidar vigencia de contrato y habilitación minera para el nuevo rango de fechas".

#### 27. Retirar asignación de RQ Proserge (`retirar-asignacion`)
- **Alcance actual:** Da de baja la asignación con motivo obligatorio y recalcula la cobertura.
- **Hallazgos / Qué debe mejorar:**
  - No modela la desvinculación automática en cascada: si el trabajador ya estaba agregado a un grupo de Man Power o tenía un asiento reservado en el Transporte de la parada, el retiro de RQ debe limpiarlo o alertar al planificador.
- **Propuesta de mejora:**
  - Agregar `serviceTask`: "Disparar retiro de listas de Man Power y Transporte asociadas a la asignación".

#### 28. Reemplazar asignación de RQ Proserge (`reemplazar-asignacion`)
- **Alcance actual:** Transacciona el pase del titular a `REEMPLAZADO` y crea el nuevo asignado referenciando `reemplaza_a_id`.
- **Hallazgos / Qué debe mejorar:**
  - Muy buen flujo transaccional. Falta modelar el traspaso opcional de cupo en transporte y grupo de trabajo hacia el nuevo trabajador entrante.
- **Propuesta de mejora:**
  - Añadir compuerta: "¿Transferir automáticamente las posiciones de Man Power y Transporte al reemplazo?" para evitar doble trabajo operativo.

---

### MODULO 6: MAN POWER (`man-power`)

#### 29. Crear grupo de trabajo (`planificar-grupos-trabajo`)
- **Alcance actual:** Crea una cuadrilla operativa para una fecha, parada, supervisor y turno.
- **Hallazgos / Qué debe mejorar:**
  - No valida si el Supervisor o Responsable de grupo asignado ya está a cargo de otra cuadrilla en el mismo turno y fecha (conflicto de ubicuidad del supervisor).
- **Propuesta de mejora:**
  - Incluir `businessRuleTask`: "Comprobar disponibilidad del responsable de grupo en fecha y turno".

#### 30. Reubicar integrante de Man Power (`reubicar-integrante`)
- **Alcance actual:** Pasa a un trabajador de una cuadrilla a otra dentro de la misma parada y fecha.
- **Hallazgos / Qué debe mejorar:**
  - Si el grupo origen y el grupo destino tienen turnos distintos o transportes asignados diferentes, el trabajador puede quedar en un grupo sin bus asignado.
- **Propuesta de mejora:**
  - Modelar en el BPMN la verificación de transporte y la reasignación de paradero/asiento coordinada con Logística.

#### 31. Cancelar grupos del día (`cancelar-grupos-dia`)
- **Alcance actual:** Cancela cuadrillas completas para un SAIT y fecha, siempre que no tengan asistencia iniciada.
- **Hallazgos / Qué debe mejorar:**
  - El BPMN bloquea si hay asistencia, lo cual es correcto. Sin embargo, no muestra qué sucede con los pasajeros asociados en Logística (se deben liberar los asientos en los buses).
- **Propuesta de mejora:**
  - Añadir `serviceTask`: "Liberar asientos de transporte asignados a los integrantes de los grupos cancelados".

#### 32. Agregar integrante a grupo de Man Power (`agregar-integrante`)
- **Alcance actual:** Suma un trabajador a una cuadrilla activa sin asistencia iniciada.
- **Hallazgos / Qué debe mejorar:**
  - Falta explicitar la validación de cupo máximo de la cuadrilla (si el plan operativo define cuadrillas de máx. 6 personas).
- **Propuesta de mejora:**
  - Agregar comprobación de capacidad máxima del grupo según el estándar del plan operativo.

#### 33. Copiar grupo de Man Power (`copiar-grupo`)
- **Alcance actual:** Duplica una cuadrilla hacia otra fecha o turno omitiendo personal con conflicto.
- **Hallazgos / Qué debe mejorar:**
  - Al omitir integrantes que no están disponibles en la fecha destino, el grupo resultante puede quedar descompensado (ej. sin soldador o sin capataz). No existe advertencia de roles críticos faltantes.
- **Propuesta de mejora:**
  - Añadir una verificación post-copia: "¿El grupo copiado cuenta con los roles mínimos requeridos por el plan?" y emitir advertencia si faltan perfiles clave.

#### 34. Copiar grupos de un día (`copiar-grupos-dia`)
- **Alcance actual:** Copia todas las cuadrillas de una fecha a otra, reemplazando grupos destino por defecto (`sobrescribir_destino=true`).
- **Hallazgos / Qué debe mejorar:**
  - *Riesgo operacional elevado:* Sobrescribir grupos destino puede eliminar cuadrillas donde un planificador ya había hecho ajustes manuales.
- **Propuesta de mejora:**
  - Incorporar en el BPMN un paso de confirmación reforzada con visualización previa de cuadrillas destino que serán afectadas.

#### 35. Copiar grupos a semana o parada (`copiar-grupos-rango`)
- **Alcance actual:** Itera la copia diaria a lo largo de un rango de fechas completas.
- **Hallazgos / Qué debe mejorar:**
  - Si ocurre un fallo a mitad del rango (ej. día 4 de 7), el código aplica rollback total. El BPMN debe dejar meridianamente claro que no se persisten días parciales si uno solo falla.
- **Propuesta de mejora:**
  - Representar la transacción como un Subproceso Transaccional con cancelación/compensación general.

---

### MODULO 7: ASISTENCIA Y FALTAS (`asistencia-faltas`)

#### 36. Corregir, justificar o anular falta (`gestionar-falta`)
- **Alcance actual:** Permite al usuario autorizado corregir una falta o anularla.
- **Hallazgos / Qué debe mejorar:**
  - Falta modelar la opción operativa por excelencia: **"Justificar Falta"** con soporte documental (descanso médico, permiso con goce o causa de fuerza mayor).
  - No modela la tipificación legal de la falta laboral (injustificada, justificada, tardanza acumulada).
- **Propuesta de mejora:**
  - Reestructurar el Exclusive Gateway en tres ramas: 1) Corregir error de marcación; 2) Justificar con sustento documental aprobado; 3) Anular por duplicidad técnica.

#### 37. Marcar ausencias por horario (`ausencias-por-horario`)
- **Alcance actual:** Proceso automático cron (`* * * * *`) que marca `AUSENTE` a quienes no tuvieron marcación pasada la tolerancia horaria.
- **Hallazgos / Qué debe mejorar:**
  - No modela la regla de turnos nocturnos o cruzados (jornadas que inician a las 20:00 y terminan al día siguiente a las 08:00), donde el horario de corte de día genera falsos ausentes si no se calcula la jornada relativa.
- **Propuesta de mejora:**
  - Incluir en la tarea de verificación horaria la regla explícita: "Normalizar turno nocturno contra la fecha de inicio de jornada".

#### 38. Marcar asistencia de grupo (`marcar-asistencia`)
- **Alcance actual:** Marcación individual o masiva por cuadrilla (Presente, Tardanza, Ausente, Descanso).
- **Hallazgos / Qué debe mejorar:**
  - No modela la captura de hora exacta de marcación ni geolocalización/evidencia de campo.
  - No ilustra qué sucede si un trabajador figura en Bienestar como "Bloqueado" o "Con Descanso Médico" el mismo día que se intenta marcar como `PRESENTE`.
- **Propuesta de mejora:**
  - Agregar `businessRuleTask`: "Verificar si el colaborador tiene bloqueo activo en Bienestar para la fecha". Si tiene bloqueo médico activo, impedir la marca `PRESENTE` sin autorización especial.

#### 39. Cerrar asistencia de grupo (`cerrar-asistencia`)
- **Alcance actual:** Cierra el encabezado del grupo, completa detalles en blanco como `AUSENTE` y genera registros de falta automática.
- **Hallazgos / Qué debe mejorar:**
  - No muestra el bloqueo definitivo de edición: una vez cerrada la asistencia, la UI no permite modificar ningún registro hasta que se active el flujo de reapertura.
- **Propuesta de mejora:**
  - Modelar el evento de bloqueo inmutable de la cuadrilla y el cómputo final de horas laboradas para tareo.

#### 40. Reabrir asistencia de grupo (`reabrir-asistencia`)
- **Alcance actual:** Vuelve el estado de `CERRADO` a `REGISTRADO` y anula las faltas automáticas generadas.
- **Hallazgos / Qué debe mejorar:**
  - *Debilidad de auditoría:* El método en código no solicita motivo obligatorio ni registra quién ordenó la reapertura.
  - Tampoco congela las evaluaciones de desempeño que ya se hubieran llenado para ese día.
- **Propuesta de mejora:**
  - Exigir en el inicio de la tarea un campo obligatorio: `Motivo operativo de reapertura`.
  - Incorporar la regla acordada: si se reabre y un registro se corrige a `AUSENTE`, marcar la evaluación vinculada como `PENDIENTE_REVISION` y excluirla del cálculo de promedios.

---

### MODULO 8: EVALUACIONES (`evaluaciones`)

#### 41. Evaluación diaria desde asistencia (`evaluacion-diaria`)
- **Alcance actual:** Evaluación de 5 criterios de desempeño diario por trabajador tras cerrar asistencia.
- **Hallazgos / Qué debe mejorar:**
  - No modela la regla de negocio acordada ante correcciones de asistencia: una evaluación cuyo trabajador pase a ausente debe quedar en estado `PENDIENTE_REVISION`.
  - Falta modelar la resolución de dicha revisión (solo el evaluador original puede reevaluar).
- **Propuesta de mejora:**
  - Añadir compuerta y estado: "Evaluación en Revisión / Congelada" para excluirla de los dashboards operativos mientras permanezca observada.

#### 42. Evaluación de supervisor (`evaluacion-supervisor`)
- **Alcance actual:** Evaluación integral de supervisores realizada por el Residente de obra en bloques A, B y C.
- **Hallazgos / Qué debe mejorar:**
  - El control de duplicados (`hasDuplicate`) solo se aplica si existe grupo de trabajo vinculado. Si se evalúa por mina y fecha sin grupo, permite duplicados en código.
- **Propuesta de mejora:**
  - Modelar la restricción estricta de unicidad: Una sola evaluación por supervisor, mina y período/semana, independientemente de si se vincula o no a un grupo específico.

#### 43. Evaluación mensual de residente (`evaluacion-residente`)
- **Alcance actual:** Evaluación mensual de Residentes realizada por Gerencia de Operaciones.
- **Hallazgos / Qué debe mejorar:**
  - El rol "Gerencia" no está claramente tipificado en la matriz RBAC (se valida por IDs o roles con permisos transversales).
  - No modela la notificación formal de retroalimentación al Residente una vez evaluado.
- **Propuesta de mejora:**
  - Definir formalmente el rol `Gerencia / Director de Operaciones` en el carril correspondiente y añadir una `serviceTask` final: "Emitir notificación con informe de desempeño al Residente evaluado".

---

### MODULO 9: LOGISTICA OPERATIVA (`logistica-operativa`)

#### 44. Registrar entrega de EPP (`entrega-devolucion-epp`)
- **Alcance actual:** Entrega de equipos de protección personal (EPP) por trabajador.
- **Hallazgos / Qué debe mejorar:**
  - No modela la validación de stock disponible en almacén antes de la entrega.
  - No contempla la firma de conformidad o entrega de acta de cargo del colaborador.
- **Propuesta de mejora:**
  - Incluir `businessRuleTask`: "Verificar stock disponible en almacén de mina" y `userTask`: "Firma o conformidad de recepción de EPP".

#### 45. Lista de herramientas por parada (`herramientas-por-parada`)
- **Alcance actual:** Armado y despacho de la lista de herramientas requeridas para la parada de planta.
- **Hallazgos / Qué debe mejorar:**
  - El proceso termina en "Lista enviada". No modela el ciclo de devolución o check-out al finalizar la parada.
- **Propuesta de mejora:**
  - Complementar con un subproceso de control de retorno e inventario de herramientas al término de los trabajos.

#### 46. Crear servicio de transporte (`planificar-transporte`)
- **Alcance actual:** Configuración de rutas, unidades, placas, conductores y horarios para personal o carga.
- **Hallazgos / Qué debe mejorar:**
  - No modela la verificación de requisitos del vehículo (SOAT, inspección técnica minera) ni del conductor (licencia MTC vigente y pase de mina).
- **Propuesta de mejora:**
  - Añadir una `businessRuleTask` de homologación de transporte antes de permitir pasar el servicio a estado `PROGRAMADO`.

#### 47. Recordatorio de vencimiento de herramientas (`recordatorio-herramientas`)
- **Alcance actual:** Cron programado (`07:30`) que avisa si faltan 2 días para el límite de envío de lista en RQs próximos.
- **Hallazgos / Qué debe mejorar:**
  - Requiere deduplicación estricta para evitar enviar la misma notificación en días consecutivos si el jefe de planeamiento ya leyó la alerta.
- **Propuesta de mejora:**
  - Modelar en la `serviceTask` la verificación de clave hash de deduplicación de eventos.

#### 48. Cerrar o reemplazar entrega de EPP (`cerrar-reemplazar-epp`)
- **Alcance actual:** Tratamiento de EPP dañado, perdido o en renovación periódica.
- **Hallazgos / Qué debe mejorar:**
  - Si el motivo es "Desgaste", el estándar de seguridad industrial exige la devolución física del EPP deteriorado para su destrucción o canje. El BPMN actual no modela este requisito físico.
- **Propuesta de mejora:**
  - Condicionar la entrega del nuevo EPP a la confirmación: "EPP deteriorado internado en almacén".

#### 49. Asignar pasajeros a transporte (`asignar-pasajeros`)
- **Alcance actual:** Reserva de asientos en buses/camionetas para integrantes de Man Power.
- **Hallazgos / Qué debe mejorar:**
  - No modela la asignación de paradero de subida y bajada por pasajero (vital para el control de ruta del conductor).
- **Propuesta de mejora:**
  - Incorporar la selección de paradero autorizado en la tarea de asignación.

#### 50. Cambiar estado de transporte (`cambiar-estado-transporte`)
- **Alcance actual:** Modifica el estado del servicio (`BORRADOR`, `CONFIRMADO`, `EN_RUTA`, `COMPLETADO`, `CANCELADO`).
- **Hallazgos / Qué debe mejorar:**
  - El código no impone una máquina de estados finita (FSM) estricta; permite saltos inconsistentes (ej. de Borrador directo a Completado).
- **Propuesta de mejora:**
  - Modelar formalmente las transiciones válidas mediante compuertas de control de secuencia.

#### 51. Retirar pasajero de transporte (`retirar-pasajero`)
- **Alcance actual:** Da de baja un asiento asignado con motivo y evento auditable.
- **Hallazgos / Qué debe mejorar:**
  - Muy buen flujo. Debe asegurarse la notificación al supervisor de grupo cuando uno de sus integrantes es retirado del bus.
- **Propuesta de mejora:**
  - Añadir `serviceTask`: "Notificar al responsable de cuadrilla sobre la liberación del asiento".

#### 52. Reubicar pasajero entre servicios (`reubicar-pasajero`)
- **Alcance actual:** Pasa a un pasajero de un servicio de transporte a otro en la misma fecha y turno.
- **Hallazgos / Qué debe mejorar:**
  - *Riesgo técnico crítico en código:* Si el alta en el servicio destino falla (ej. sin capacidad), el código devuelve un array `['ok' => false]` dentro de la transacción externa. Esto **no dispara rollback**, dejando al pasajero marcado como `REUBICADO` en el origen pero sin cupo en el destino.
- **Propuesta de mejora:**
  - En el BPMN, modelar una transacción atómica estricta con compensación obligatoria si el destino rechaza la asignación.

#### 53. Copiar servicio de transporte (`copiar-servicio-transporte`)
- **Alcance actual:** Replica la programación de una ruta a otra fecha o turno.
- **Hallazgos / Qué debe mejorar:**
  - Si se elige copiar placa y conductor, no se verifica si esa unidad o chofer ya están programados en otro servicio en la fecha/turno destino.
- **Propuesta de mejora:**
  - Incluir comprobación de disponibilidad vehicular y del conductor antes de crear la copia.

---

### MODULO 10: BIENESTAR (`bienestar`)

#### 54. Registrar bloqueo de bienestar (`registrar-bloqueo`)
- **Alcance actual:** Registra una restricción médica, disciplinaria o administrativa sobre un trabajador.
- **Hallazgos / Qué debe mejorar:**
  - *Falla de trazabilidad en código:* Si no se detecta usuario en sesión, `BienestarPageController` asigna como autor del bloqueo al primer usuario encontrado en la tabla (`Usuario::first()`).
  - No modela el impacto directo sobre asignaciones activas (debe inhabilitar al trabajador en RQ Proserge y Man Power si su bloqueo cae en días programados).
- **Propuesta de mejora:**
  - Bloquear el registro si no existe actor autenticado explícito.
  - Agregar `serviceTask`: "Detectar y alertar asignaciones activas colisionantes en paradas de mina".

#### 55. Editar bloqueo de bienestar (`editar-bloqueo`)
- **Alcance actual:** Modifica fechas, motivo o diagnóstico del bloqueo.
- **Hallazgos / Qué debe mejorar:**
  - A diferencia del registro o anulación, la edición no dispara ninguna notificación operativa a los supervisores o planificadores de mina.
- **Propuesta de mejora:**
  - Incorporar en el BPMN la tarea `Notify`: "Emitir notificación de cambio de fechas de restricción".

#### 56. Anular bloqueo de bienestar (`anular-bloqueo`)
- **Alcance actual:** Da de baja un bloqueo pasando su estado a `ANULADO`.
- **Hallazgos / Qué debe mejorar:**
  - No exige registrar el sustento o motivo formal del levantamiento del bloqueo (ej. alta médica firmada por médico ocupacional).
- **Propuesta de mejora:**
  - Agregar campo obligatorio `Motivo de levantamiento / Documento de alta médica`.

---

### MODULO 11: NOTIFICACIONES (`notificaciones`)

#### 57. Vencer y purgar notificaciones (`limpiar-notificaciones-vencidas`)
- **Alcance actual:** Cron nocturno (`02:10`) que pasa notificaciones vencidas a `EXPIRED` y purga las que superan los 15 días posteriores al vencimiento.
- **Hallazgos / Qué debe mejorar:**
  - En bases de datos con cientos de miles de registros, borrar directamente sin procesamiento por lotes (chunking) puede bloquear tablas durante minutos.
- **Propuesta de mejora:**
  - Modelar en el servicio y BPMN la eliminación segmentada por bloques (`delete chunked`) con límite de tiempo de ejecución.

#### 58. Emitir notificación operativa (`emitir-notificacion`)
- **Alcance actual:** Dispara eventos, resuelve roles/destinatarios y crea copias en las bandejas.
- **Hallazgos / Qué debe mejorar:**
  - No distingue el canal de entrega: notificación in-app (campana web) vs correo electrónico o notificación push móvil.
- **Propuesta de mejora:**
  - Bifurcar mediante compuerta paralela los canales activos según las preferencias configuradas por el usuario.

#### 59. Gestionar bandeja personal (`gestionar-bandeja`)
- **Alcance actual:** Permite al usuario consultar sus notificaciones, abrirlas o archivarlas.
- **Hallazgos / Qué debe mejorar:**
  - No contempla la acción masiva fundamental en bandejas de usuario: **"Marcar todas como leídas"** o **"Archivar todas"**.
- **Propuesta de mejora:**
  - Modelar la opción de procesamiento masivo en un solo clic dentro de la tarea de usuario.

---

## 4. DETECCION DE PROCESOS FALTANTES (GAP ANALYSIS)

Tras una inspección cruzada entre el código fuente de Proserge, los modelos Eloquent, los requerimientos corporativos de contratistas mineras y la legislación laboral peruana, se identifican **10 Macroprocesos y Procesos Críticos Faltantes** que actualmente no cuentan con diagrama BPMN en el repositorio:

---

### 1. Desvinculación, Renuncia y Cese de Personal (Offboarding & Liquidación)
- **Módulo:** Personal / Legal / RR.HH.
- **Actores:** Trabajador, RR.HH., Asesoría Legal, Administración.
- **Justificación:** El sistema cuenta con alta, ficha, contrato y renovación, pero carece del flujo formal de cese. En una contrata minera, el cese debe coordinar: 1) Carta de renuncia o carta de preaviso/despido; 2) Entrega de cargo y devolución de herramientas/EPP; 3) Bloqueo inmediato de acceso en el sistema y revocación de pases de mina; 4) Cálculo de liquidación de beneficios sociales (CTS, gratificaciones truncas, vacaciones truncas); 5) Emisión del certificado de trabajo y constancia de cese.
- **Estructura BPMN propuesta:**
  - `Start`: Recepción de renuncia / Decisión de término de contrato.
  - `Task`: Validar deudas, herramientas pendientes y EPPs sin devolver.
  - `Task`: Marcar contrato como `CESADO` y trabajador como `INACTIVO`.
  - `Task`: Deshabilitar pases mineros y revocar credenciales web/API.
  - `Task`: Generar liquidación y constancia de trabajo.
  - `End`: Trabajador cesado con expediente auditado.

---

### 2. Campañas de Vencimiento y Renovación Masiva de Contratos
- **Módulo:** Personal
- **Actores:** Analista de RR.HH., Residente de Mina, Gerencia.
- **Justificación:** Actualmente solo existe el proceso unitario de ciclo contractual. Cuando terminan los meses de campaña, cientos de contratos vencen simultáneamente. Se requiere un proceso de revisión masiva de nómina con filtros de desempeño.
- **Estructura BPMN propuesta:**
  - `Timer Start`: Scheduler quincenal (alerta contratos a vencer en <= 30 días).
  - `Task`: Generar reporte de contratos por vencer consolidado con evaluaciones de desempeño.
  - `Task`: Residente de mina emite recomendación (Renovar / No Renovar).
  - `Gateway`: ¿Aprobado para renovación?
  - `Task`: Generar adendas masivas en borrador.
  - `Task`: Firma digital o física de renovación y activación en lote.

---

### 3. Solicitud, Aprobación y Goce de Vacaciones
- **Módulo:** Personal / Asistencia
- **Actores:** Trabajador, Supervisor de Mina, RR.HH.
- **Justificación:** No existe ningún flujo de vacaciones. Si un trabajador sale de vacaciones, hoy en día se corre el riesgo de marcarlo como `AUSENTE` en la cuadrilla y generarle faltas injustificadas automáticas.
- **Estructura BPMN propuesta:**
  - `Start`: Trabajador o RR.HH. solicita rol vacacional.
  - `Task`: Validar saldo de días ganados y récord vacacional.
  - `Gateway`: ¿Aprobado por el Supervisor y RR.HH.?
  - `Task`: Registrar período de vacaciones en el calendario del colaborador.
  - `ServiceTask`: Sincronizar con Asistencia para que los días vacacionales se auto-marquen como `VACACIONES` (sin generar falta ni requerir marcación diaria).
  - `End`: Rol vacacional programado y cerrado.

---

### 4. Gestión de Licencias Médicas, CITT y Subsidios EsSalud
- **Módulo:** Bienestar / RR.HH.
- **Actores:** Trabajador, Asistenta Social / Médico Ocupacional, Contabilidad.
- **Justificación:** Bienestar solo posee "bloqueos genéricos". Las licencias por enfermedad o accidentes de trabajo requieren validación de descanso médico emitido por EsSalud (CITT) o clínicas privadas, control del acumulado anual de 20 días a cargo del empleador, y a partir del día 21 la tramitación del subsidio ante EsSalud.
- **Estructura BPMN propuesta:**
  - `Start`: Recepción de certificado de descanso médico.
  - `Task`: Validación médica y legal del certificado.
  - `Gateway`: ¿Descanso supera 20 días acumulados en el año fiscal?
  - `Branch Sí`: Iniciar expediente de cobro de subsidio en EsSalud.
  - `ServiceTask`: Registrar bloqueo médico en Bienestar y auto-marcar `DESCANSO_MEDICO` en Asistencia.
  - `End`: Descanso médico registrado y subsidio en trámite.

---

### 5. Macroproceso Integral de Parada de Planta (Convocatoria a Liquidación)
- **Módulo:** Operaciones / RQMina / ManPower / Logística / Asistencia
- **Actores:** Cliente Minero, Residente Proserge, Planeamiento, RR.HH., Logística.
- **Justificación:** Es el corazón del negocio de Proserge. Los módulos actuales modelan fragmentos aislados (RQ Mina, RQ Proserge, Man Power, Asistencia, Evaluaciones), pero no existe un BPMN que muestre el flujo completo de vida de una Parada de Planta de inicio a fin.
- **Estructura BPMN propuesta:**
  - `Fase 1: Convocatoria y Planificación`: Recepción de orden minera -> Creación de RQ Mina -> Versionado del Plan Operativo.
  - `Fase 2: Asignación y Logística`: Atención en RQ Proserge -> Validación de Habilitaciones Mineras -> Programación de Transporte y Herramientas.
  - `Fase 3: Ejecución en Campo`: Planificación diaria de Man Power -> Marcación de Asistencia -> Ejecución de Evaluaciones de Desempeño.
  - `Fase 4: Cierre y Liquidación`: Cierre de asistencias -> Devolución e inspección de herramientas -> Liquidación de horas hombre con el cliente -> Cierre de Parada.

---

### 6. Consolidación de Tareo y Cierre de Nómina / Planilla (Payroll Export)
- **Módulo:** Asistencia / Faltas / Contabilidad
- **Actores:** Encargado de Planilla, Jefe de RR.HH., Contabilidad.
- **Justificación:** El registro diario de asistencia y faltas tiene como fin pagar a los trabajadores. Falta el proceso formal de consolidación periódica (quincenal/mensual) que valida que todas las asistencias estén cerradas, computa horas ordinarias, sobretiempos, días de altura y faltas, y genera el archivo plano para el sistema de nóminas (ej. PLAME/T-Registro).
- **Estructura BPMN propuesta:**
  - `Start`: Corte de período de tareo (ej. fin de mes).
  - `Task`: Validar que no existan grupos de asistencia en estado `REGISTRADO` (pendientes de cierre).
  - `Task`: Consolidar horas trabajadas, turnos noche, dominicales y faltas justificadas/injustificadas.
  - `Gateway`: ¿Discrepancias detectadas? -> Subproceso de conciliación con supervisores.
  - `ServiceTask`: Congelar período de tareo para evitar ediciones posteriores.
  - `Task`: Exportar archivo de nómina y enviar a Contabilidad.

---

### 7. Reclutamiento, Selección y Filtro Previo de Candidatos
- **Módulo:** Personal / Reclutamiento
- **Actores:** Postulante, Analista de Selección, Médico Evaluador.
- **Justificación:** Antes de enviar un enlace de ficha temporal (`activar-enlace-ficha`), existe un embudo de selección donde se reciben CVs, se verifican antecedentes penales y policiales, y se realiza el pre-chequeo de aptitud médica para altura minera.
- **Estructura BPMN propuesta:**
  - `Start`: Publicación de vacantes según requerimiento de personal.
  - `Task`: Recepción y filtro curricular de postulantes.
  - `Task`: Verificación de antecedentes y referencias laborales.
  - `Gateway`: ¿Candidato APTO?
  - `ServiceTask`: Disparar proceso `activar-enlace-ficha` y enviar invitación formal.
  - `End`: Candidato habilitado para llenar ficha de ingreso.

---

### 8. Devolución, Inspección y Baja de Herramientas de Parada
- **Módulo:** ParadaHerramientas / Logística
- **Actores:** Supervisor de Parada, Almacenero, Mantenimiento.
- **Justificación:** El módulo actual solo contempla el armado de la lista inicial (`herramientas-por-parada`) y recordatorios. Al terminar la parada, las herramientas deben retornar al taller central, verificarse contra el checklist de salida, separar las herramientas dañadas para reparación o calibración, y reportar las pérdidas.
- **Estructura BPMN propuesta:**
  - `Start`: Conclusión de actividades de parada de planta.
  - `Task`: Recepción física de herramientas en almacén/taller.
  - `Task`: Cotejar herramientas devueltas contra la lista de despacho original.
  - `Gateway`: ¿Herramientas faltantes o averiadas?
  - `Branch Daños`: Emitir informe de avería y derivar a calibración/mantenimiento.
  - `Branch Pérdidas`: Abrir reporte de responsabilidad y reposición.
  - `Task`: Cerrar acta de devolución y actualizar inventario.

---

### 9. Gestión de Almacén y Kardex de Stock de EPP
- **Módulo:** Epps / Logística
- **Actores:** Logística / Almacén, Proveedores, Administración.
- **Justificación:** Actualmente solo se modela la entrega y reposición a trabajadores. No existe el proceso de compras, ingreso por orden de compra, control de stock mínimo y alerta de desabastecimiento de tallas críticas (respiradores, arneses de seguridad, zapatos con puntera de acero).
- **Estructura BPMN propuesta:**
  - `Start`: Alerta de stock por debajo del punto de pedido o nueva orden de compra.
  - `Task`: Recepción de lote de EPPs e inspección de certificados de calidad (ANSI/OSHA).
  - `ServiceTask`: Registrar ingreso en Kardex por modelo, marca y talla.
  - `Task`: Actualizar disponibilidad para entregas en el módulo de Logística Operativa.

---

### 10. Auditoría de Seguridad, Revocación de Tokens y Recuperación de Cuenta
- **Módulo:** Seguridad / Acceso
- **Actores:** Usuario, Administrador de Seguridad, Sistema.
- **Justificación:** Es imprescindible contar con flujos de contingencia de seguridad: reseteo de clave por correo electrónico ("Olvidé mi contraseña"), bloqueo forzado de cuentas por sospecha de compromiso, y revocación inmediata de todos los tokens Bearer emitidos.
- **Estructura BPMN propuesta:**
  - `Start`: Solicitud de reseteo / Detección de incidente de seguridad.
  - `ServiceTask`: Revocar de inmediato todos los tokens Bearer y sesiones web activas.
  - `Task`: Generar token seguro de recuperación con TTL de 15 minutos.
  - `Task`: Usuario establece nueva contraseña cumpliendo políticas de complejidad.
  - `End`: Acceso restablecido y registrado en log de auditoría de seguridad.

---

## 5. PLAN DE ACCION Y RECOMENDACIONES PRIORIZADAS

1. **Prioridad 1 (Urgencia Operativa y Consistencia de Código):**
   - Corregir el método `RQMinaService::send` para envolver el guardado de `ENVIADO` y la sincronización `syncFromRqMina` en una única transacción atómica con rollback.
   - Corregir `TransportePlanningService::reubicarPasajero` para que los errores devueltos por `addPasajeros` aborten la transacción y eviten desasociaciones parciales de pasajeros.
   - Enriquecer `AsistenciaCierreService::reabrir` para que exija motivo obligatorio y registre usuario auditor.

2. **Prioridad 2 (Alineamiento de Diagramas BPMN con Decisiones Confirmadas):**
   - Actualizar los BPMN de Asistencia y Evaluaciones para reflejar formalmente la regla del 2026-09-27: evaluaciones afectadas por corrección a ausente pasan a `PENDIENTE_REVISION` y quedan excluidas de indicadores hasta que el evaluador original resuelva.
   - Incorporar en el BPMN de Habilitación Minera la regla de que el reintento extraordinario tras alcanzar el límite desaprobado está permitido con aprobación.

3. **Prioridad 3 (Modelado de Procesos Faltantes):**
   - Diseñar y documentar en formato BPMN los tres procesos faltantes de mayor impacto legal y operativo:
     1. `personal/cese-desvinculacion.bpmn`
     2. `personal/vacaciones-licencias.bpmn`
     3. `operaciones/ciclo-parada-planta.bpmn`
