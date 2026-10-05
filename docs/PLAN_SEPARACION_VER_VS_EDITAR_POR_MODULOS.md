# Plan y Auditoría de Separación: "Ver" (Lectura) vs "Editar" (Gestión) por Módulos

**Documento Canónico de Arquitectura y Seguridad UI/Backend**  
**Fecha de Auditoría:** Octubre 2026  
**Sistema:** Sistema Proserge (Operaciones Mineras, RR.HH., Logística, SSOMA)

---

## 1. Principio Fundamental del Dominio

En el Sistema Proserge, los perfiles de usuario abarcan desde directores y auditores que únicamente requieren auditar o consultar información, hasta supervisores de mina y administradores corporativos que operan cambios en tiempo real.

Para garantizar la integridad operativa, la confidencialidad de la información y prevenir alteraciones accidentales o no autorizadas de datos, se establece el estándar corporativo de **Separación Estricta entre "Ver" y "Editar"**:

1. **Modo "Ver" (Solo Lectura / Consulta / Detalle):**
   - Corresponde a las rutas y vistas `show` (o listados `index`).
   - Muestra resúmenes, tarjetas de información, badges de estado, métricas, tablas de historial y trazabilidad.
   - **Invariante:** No debe contener formularios embebidos de mutación (`<form method="POST/PUT/PATCH">`), campos de texto editables, selectores modificables ni botones de acción destructiva expuestos a usuarios que solo tienen permiso de lectura (`ver`).
   - Los accesos a edición desde la vista de detalle se limitan a botones o enlaces de navegación hacia la vista de edición (`edit`), protegidos mediante comprobaciones de permisos del lado del servidor.

2. **Modo "Editar" (Gestión / Escritura):**
   - Corresponde a rutas dedicadas `edit`, vistas de formulario o modales protegidos.
   - Concentra todos los campos de formulario, validaciones, confirmaciones de guardado y controles de cambio de estado.
   - Requiere permisos directos específicos (`editar`, `actualizar`, `asignar`, `configurar`, `eliminar`).

3. **Defensa en Profundidad (Frontend + Backend):**
   - **Frontend:** Uso estricto de directivas `@allowedDirect('modulo', 'accion')` o variables de control (`$canEdit`, `$canAssign`) para evitar renderizar botones o enlaces no autorizados en Blade.
   - **Backend:** Middleware `web.permission:modulo,accion`, directivas de controlador `$this->assertDirectPermission()` y FormRequests con políticas de autorización. Ocultar un botón en Blade **nunca** reemplaza la autorización en backend.
   - **Matriz RBAC:** Las acciones destructivas como `eliminar` no deben heredarse automáticamente de `actualizar` (`PermissionMatrix::fallbackActionsFor`).

---

## 2. Auditoría Integral y Diagnóstico por Módulos

A continuación se detalla el análisis módulo por módulo de las rutas, controladores, vistas Blade y estado de cumplimiento de la separación entre lectura y edición.

---

### Módulo 1: Seguridad y Usuarios (`app/Modules/Seguridad`)

#### Diagnóstico Previo
- **Ruta Web previa:** Existía únicamente `/usuarios/{id}` (`UsuarioPageController@show`), la cual cargaba simultáneamente los datos del trabajador y **cuatro formularios de edición activos** en la misma pantalla (cambio de email/rol, reseteo de contraseña, actualización de scope mina y configuración de notificaciones).
- **Riesgo:** Un usuario con solo permiso `usuarios:ver` accedía a `/usuarios/{id}` y observaba campos de formulario e inputs habilitados, y el enlace de la tabla de usuarios (`index.blade.php`) apuntaba tanto en "Ver" como en "Editar" a la misma URL con ancla `#edicion`.
- **Riesgo en eliminación:** No existía método web ni API para eliminar usuarios accidentales creados por error o confusión.
- **Riesgo RBAC:** En `PermissionMatrix`, la acción `eliminar` tenía fallback implícito a `actualizar`.

#### Solución Implementada
1. **Separación de Vistas y Rutas:**
   - **`usuarios.show` (`GET /usuarios/{id}`):** Rediseñado íntegramente como pantalla de solo lectura. Presenta los datos del colaborador, tarjetas de roles (base, área, cargo), estado de la cuenta, resumen de alcance minero (Mina Scope) y resumen de preferencias de alertas. Si el usuario logueado posee permiso de edición, muestra el botón de navegación `"Editar Usuario"`.
   - **`usuarios.edit` (`GET /usuarios/{id}/editar`):** Nueva ruta y vista dedicada (`seguridad.usuarios.edit`). Protegida por `web.permission:usuarios,editar|actualizar` y por `$this->assertAnyDirectPermission()`. Concentra los formularios de credenciales, roles adicionales (chips interactivos), reseteo de contraseña y preferencias de notificación.
   - **`usuarios.index` (`GET /usuarios`):** Acciones separadas en la tabla: botón `"Ver"` hacia `usuarios.show` y botón `"Editar"` hacia `usuarios.edit` (visible únicamente si tiene permiso `editar` o `asignar`).
2. **Eliminación Física Segura para SUPERADMIN:**
   - Restringida exclusivamente al rol `SUPERADMIN` tanto en Web (`DELETE /usuarios/{id}`) como en API (`DELETE /api/v1/seguridad/usuarios/{id}`).
   - **Protección contra auto-eliminación:** El usuario no puede eliminar su propia cuenta activa.
   - **Protección contra eliminación del último administrador:** Validado por `AdminProtectionService::assertUserDeleteAllowed()`.
   - **Auditoría de integridad transaccional:** `AdminProtectionService::getTransactionalDependencies()` audita de forma exhaustiva más de 25 columnas operativas sobre tablas clave (`personal_bloqueo/s`, `personal_contratos`, `personal_contrato_datos`, `personal_contrato_correcciones`, `personal_fichas`, `personal_ingresos`, `personal_documento_estados`, `personal` [ceses, lista negra, creadores], `personal_mina_*` [exámenes, convalidaciones, requisitos, intentos], `rq_mina`, `rq_mina_planes`, `rq_mina_detalle_cambios`, `rq_mina_field_options`, `asistencia_encabezado`, `faltas` [corregido_por_usuario_id, anulado_por_usuario_id], `evaluacion_supervisor` / `evaluaciones_supervisor`, `evaluacion_residente` / `evaluaciones_residente`, `evaluacion_desempeno` / `evaluaciones_desempeno`, `parada_herramientas` y catálogos). Si el usuario tiene actividad registrada, se bloquea el borrado informando los módulos afectados y sugiriendo la desactivación. Si es un usuario accidental sin registros operativos, se eliminan atómicamente registros accesorios (`usuario_roles`, `usuario_mina_scope`, `notification_preferences`, `notification_user_settings`, `notification_recipients` [limpiado mediante `usuario_id`], `auth_tokens`) y se purga el usuario emitiendo auditoría crítica.
3. **Claridad en Scope Mina:**
   - Se validó que el Scope Vacío es un comportamiento válido y funcional para usuarios locales asignados a su mina de adscripción.
   - Se incorporó aviso preventivo explicativo ("Scope Vacío - Acceso Local"), alerta cuando el usuario posee roles corporativos (`ADMIN`, `GERENTE`, `SUPERADMIN`), selector dinámico en tiempo real ("X de Y seleccionadas") y botones rápidos de selección masiva ("Marcar todas", "Desmarcar todas").
4. **Blindaje RBAC:**
   - `PermissionMatrix::fallbackActionsFor` se actualizó aislando `'eliminar' => [$action]` para impedir que permisos de actualización permitan ejecutar borrados.

---

### Módulo 2: Personal y Contratos (`app/Modules/Personal`)

#### Diagnóstico
- **Rutas Web:** `/personal/{id}` (`PersonalPageController@show`) y `/personal/{id}/editar` (`PersonalPageController@edit`).
- **Ver (`personal.show`):**
  - Muestra datos generales del colaborador, estado laboral (`ACTIVO`, `CESADO`, etc.), datos de ficha médica/ingreso, contratos históricos y documentos cargados.
  - La visualización de datos sensibles (DNI, sueldos, cuentas bancarias) está protegida por permisos específicos (`personal:ver_sensibles`).
- **Editar (`personal.edit`):**
  - Formulario completo de actualización de datos personales, sedes y puestos.
  - Protegido por `assertPersonalPermission(['editar', 'actualizar', 'editar_ficha'])`.
- **Contratos Laborales:**
  - Los contratos históricos son inamovibles. Las renovaciones generan contratos nuevos (`PersonalContratoRenewalService`), respetando la trazabilidad.
  - Rutas de contrato (`/personal/contratos/*`) están debidamente separadas de la vista de consulta.

#### Estado y Recomendaciones
- **Cumplimiento:** ALTO. La separación `show` vs `edit` ya está formalizada a nivel de rutas.
- **Chequeo:** Verificar que en `personal.show` ningún modal de carga de documentos o cambio de estado laboral sea visible sin permisos de escritura.

---

### Módulo 3: RQ Mina (Requerimientos de Paradas Mineras) (`app/Modules/RQMina`)

#### Diagnóstico
- **Rutas Web:** `/rq-mina/{id}` (`RQMinaPageController@show`) y `/rq-mina/{id}/editar` (`RQMinaPageController@edit`).
- **Incidencia detectada y corregida:**
  - En `resources/views/rq-mina/show.blade.php`, el botón de acceso al editor del "Plan operativo" (`route('rq-mina.plan-operativo.edit')`) se renderizaba condicionado únicamente al estado del RQ, omitiendo la validación de permisos de edición. Usuarios con solo `rq_mina:ver` podían ver el botón y al hacer clic recibían un error 403 Forbidden.
  - **Corrección aplicada:** Se introdujo la variable de autorización `$canEditRq` calculada en servidor mediante `PermissionMatrix::allowsDirectAny($permissions, 'rq_mina', ['editar', 'actualizar', 'gestionar'])`. El botón ahora está protegido por `@if($canEditRq)`.
- **Ver (`rq-mina.show`):**
  - Vista limpia de solo lectura que detalla la mina, tipo de parada, fechas operativas, dotación solicitada y turnos.
- **Editar (`rq-mina.edit` / `plan-operativo-editor`):**
  - Formularios dedicados para modificar la dotación por puestos, turnos y personal asignado.

#### Estado y Recomendaciones
- **Cumplimiento:** COMPLETO tras la corrección de `$canEditRq`.

---

### Módulo 4: RQ Proserge (Asignación y Respuesta de Requerimientos) (`app/Modules/RQProserge`)

#### Diagnóstico
- **Rutas Web:** `/rq-proserge` (`RQProsergePageController@index`).
- **Modo Ver:**
  - Grilla de seguimiento de requerimientos mineros con barras de progreso de cobertura (asignados vs requeridos).
  - Consulta del pool de trabajadores disponibles y habilitados para la mina.
- **Modo Editar:**
  - Modales de asignación y desasignación de personal a requerimientos.
  - El backend aplica `RQProsergePolicy` verificando permisos `rq_proserge:asignar|gestionar` y alcance por mina (`mina.scope`).

#### Estado y Recomendaciones
- **Cumplimiento:** ALTO. Los modales de asignación están condicionados por permisos y el controlador valida la política en cada mutación.

---

### Módulo 5: Man Power (Grupos de Trabajo y Destinos) (`app/Modules/ManPower`)

#### Diagnóstico
- **Rutas Web:** `/man-power` (`ManPowerPageController@index`).
- **Modo Ver:**
  - Tablero de grupos de trabajo, supervisores a cargo, vehículos asignados y lista de trabajadores por grupo.
- **Modo Editar:**
  - Creación de grupos, transferencia de trabajadores entre grupos y asignación de líderes de grupo.
  - Protegido por `web.permission:man_power,gestionar|crear|editar`.

#### Estado y Recomendaciones
- **Cumplimiento:** ALTO. Los formularios de reagrupación se encuentran aislados en vistas o componentes autorizados.

---

### Módulo 6: Asistencia y Faltas (`app/Modules/Asistencia`, `app/Modules/Faltas`)

#### Diagnóstico
- **Asistencia:**
  - **Ver (`asistencia.show`):** Detalle de la nómina de asistencia diaria cerrada o en ejecución (presentes, descansos, faltas, bajadas). Solo lectura.
  - **Editar (`asistencia.marcar`, `asistencia.masivo`):** Formularios de marcación rápida o carga masiva. Rutas separadas protegidas por `asistencias:registrar|editar`.
- **Faltas:**
  - **Ver (`faltas.show`):** Ficha individual de la falta registrada, motivos, fecha y colaborador.
  - **Editar (`faltas.corregir`):** Pantalla específica para justificar, corregir o anular faltas, restringida a supervisores con `faltas:editar|anular`.

#### Estado y Recomendaciones
- **Cumplimiento:** COMPLETO. Existe clara separación física entre consulta de asistencia y formularios de marcación/corrección.

---

### Módulo 7: Evaluaciones (Supervisor, Residente y Desempeño) (`app/Modules/Evaluaciones`)

#### Diagnóstico
- **Rutas Web:** `/evaluaciones` (`EvaluacionesPageController@index`).
- **Modo Ver:**
  - Dashboard analítico con notas promedio por cargo, mina y periodo, gráficos de distribución y tabla de historial de evaluaciones.
- **Modo Editar:**
  - Formulario de evaluación diaria (criterios de seguridad, rendimiento, actitud).
  - La interfaz de captura de notas está separada en componentes que solo se activan cuando el usuario autenticado tiene rol de evaluador o permiso `evaluaciones:evaluar`.

#### Estado y Recomendaciones
- **Cumplimiento:** ALTO. La visualización analítica no expone campos de calificación editables.

---

### Módulo 8: Logística y EPPs (`app/Modules/Logistica`, `app/Modules/Epps`)

#### Diagnóstico
- **Rutas Web:** `/logistica` (`LogisticaPageController@index`).
- **Modo Ver:**
  - Monitoreo de stock de equipos de protección personal, herramientas y materiales por almacén/mina.
- **Modo Editar:**
  - Formularios de entrega y recepción de EPPs por trabajador, ajustes de inventario y reposiciones.
  - Separado en modales dedicados protegidos por permisos `logistica:registrar|editar`.

#### Estado y Recomendaciones
- **Cumplimiento:** ADECUADO. Verificar que el botón "Registrar Entrega" no se muestre a usuarios con solo permiso de lectura de inventario.

---

### Módulo 9: Transporte y Rutas (`app/Modules/Transporte`)

#### Diagnóstico
- **Rutas Web:** `/transporte/planificacion` (`TransportePageController@planificacion`).
- **Modo Ver:**
  - Consulta de listas de pasajeros, unidades de transporte asignadas y horarios de subida/bajada de mina.
- **Modo Editar:**
  - Asignación de vehículos a rutas y registro de listas de pasajeros. Protegido por `transporte:planificar|editar`.

#### Estado y Recomendaciones
- **Cumplimiento:** ADECUADO.

---

### Módulo 10: Seguridad y Roles (`app/Modules/Seguridad/Controllers/RolPageController`)

#### Diagnóstico
- **Rutas Web:**
  - Listado: `/seguridad/roles` (`seguridad.roles.index`).
  - Ver / Detalle: `/seguridad/roles/{id}` (`seguridad.roles.show`).
  - Editar: `/seguridad/roles/{id}/editar` (`seguridad.roles.edit`).
- **Modo Ver:** Matriz de permisos en formato texto/insignia informativa sin selectores interactivos.
- **Modo Editar:** Checkboxes de permisos por módulo, asignación de tipo de rol y botón de guardar.

#### Estado y Recomendaciones
- **Cumplimiento:** COMPLETO. Sirve de modelo de referencia para los demás submódulos de seguridad.

---

## 3. Matriz de Mitigación y Reglas de Implementación

Para asegurar que cualquier desarrollo futuro mantenga esta separación, se establecen las siguientes reglas obligatorias:

| Regla | Descripción | Control Backend | Control Frontend (Blade) |
|---|---|---|---|
| **R1: Rutas Diferenciadas** | Las entidades principales deben tener ruta `show` (lectura) y `edit` (formulario). | `Route::get('/{id}')` vs `Route::get('/{id}/editar')` | Enlaces con clase distintiva (`btn-outline` para Ver, `btn-primary` para Editar). |
| **R2: Sin Formularios en Show** | Las vistas `*.show.blade.php` no deben incluir etiquetas `<form>` mutadoras. | Controladores de `show` solo aceptan peticiones GET. | No renderizar inputs, textareas ni botones de submit en la vista de detalle. |
| **R3: Doble Verificación en Botones** | Si un botón lleva a una acción de escritura, se debe comprobar el permiso antes de mostrarlo. | Middleware `web.permission` en la ruta destino. | `@allowedDirect('modulo', 'accion')` o `@if($canEdit)`. |
| **R4: Destrucción Restringida** | El permiso `eliminar` nunca debe ser concedido por herencia de `actualizar`. | `PermissionMatrix::fallbackActionsFor` aislado. | Formularios DELETE con confirmación `onsubmit` explícita y visible solo a perfiles autorizados. |
| **R5: Integridad Transaccional** | Bloquear el borrado físico de usuarios o entidades que posean relaciones históricas. | `AdminProtectionService::getTransactionalDependencies()`. | Alerta descriptiva indicando los registros vinculados y recomendando desactivar en vez de eliminar. |
| **R6: Claridad de Alcance** | Cuando un usuario tenga alcance restringido (ej. Scope Mina vacío), el sistema debe advertir qué significa. | Filtros de servicio `MinaScopeAccess` / `mina.scope`. | Badges y banners contextuales explicando el acceso local o corporativo. |

---

## 4. Checklist de Verificación de Pantallas

Antes de liberar cambios en cualquier módulo, el equipo debe verificar:

- [ ] **1. Prueba de Rol Observador:** Ingresar con un usuario con únicamente permiso `ver` en el módulo.
- [ ] **2. Integridad Visual:** Comprobar que en la vista `show` y en la tabla `index`:
  - No aparezcan botones "Editar", "Crear", "Eliminar" o "Modificar".
  - No existan inputs de texto, selects o checkboxes editables.
  - Los botones de exportación o descarga solo aparezcan si tiene permiso `exportar`.
- [ ] **3. Prueba de Acceso Directo por URL:** Intentar acceder por URL directa a `/{id}/editar` o enviar peticiones `POST/PUT/DELETE`.
  - Debe responder con **403 Forbidden** o redirección con error, nunca permitir la mutación.
- [ ] **4. Auditoría de Eliminaciones:** Confirmar que no se pueda eliminar usuarios administradores únicos ni cuentas con registros operativos vigentes.
- [ ] **5. Scope y Minas:** Validar que los usuarios con scope vacío solo operen en su mina y que las alertas expliquen claramente el alcance.
