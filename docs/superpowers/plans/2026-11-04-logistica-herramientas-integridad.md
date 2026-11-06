# Plan de implementación de Logística y Herramientas

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`.

**Objetivo.** Resolver R2, R3 y R5. Mostrar sólo servicios autorizados y preservar la evidencia de entregas, recepciones y archivos.

**Arquitectura.** Pasar el actor al lector de Servicios. Separar solicitud de atención en Herramientas y preparar cambios de documentos sin borrar archivos durante validación.

**Tecnología.** Laravel 12, MySQL, Blade y disco privado. [Diseño general](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#correcciones-complementarias)

### R2. Aplicar mina autorizada a Servicios y alquileres

**Archivos.** `app/Modules/Logistica/Controllers/LogisticaPageController.php`, `app/Modules/Logistica/Services/LogisticaDashboardService.php`, `tests/Feature/LogisticaDashboardServiceTest.php`, `tests/Feature/LogisticaEppIntegrationTest.php`.

- [ ] Probar un rol Servicios sin EPP con scope A y datos A/B. GET con filtro B, sin scope y selector, contadores, incidencias e historial no deben devolver datos B. El rol con ambas capacidades conserva las mismas restricciones.
- [ ] Correr `php artisan test --filter 'LogisticaDashboardServiceTest|LogisticaEppIntegrationTest'` y verificar el fallo del caso B.
- [ ] Hacer obligatorio el actor para `serviciosPageData(array $query, Usuario $actor): array` y `serviceRows(array $filters, Usuario $actor): Collection`. Aplicar el predicado de `MinaScopeAccess` a la parada antes de seleccionar, limitar, contar y cargar documentos/eventos. Intersectar filtros de mina con scope. Pasar el actor tanto en contexto Servicios solo como combinado. Auditar selectores y confirmar coherencia con descarga/escritura.
- [ ] Repetir suite. Render con filtros y regreso móvil. Mantener los privilegios de scope de roles activos definidos en el módulo.

### R3. Mantener entrega y recepción fuera del guardado de solicitud

**Archivos.** `app/Modules/ParadaHerramientas/Services/ParadaHerramientaService.php`, `app/Modules/ParadaHerramientas/Controllers/ParadaHerramientaPageController.php`, `resources/views/parada-herramientas/partials/tool-row.blade.php`, `tests/Feature/ParadaHerramientaServiceTest.php`. Crear migración `2026_11_04_000700_create_parada_herramienta_item_eventos.php` y modelo `app/Models/ParadaHerramientaItemEvento.php` para los cambios de atención.

- [ ] Probar `actualizar` sin `registrar` con recibido=99 y actor falso. Fallo de validación sin escrituras. Probar solicitud abierta mientras otro registra recepción, omisión de ítem atendido, ID de otra parada e importación sobre lista con evidencia.
- [ ] Correr `php artisan test --filter ParadaHerramientaServiceTest` y reproducir la escritura indebida.
- [ ] `saveLista` acepta únicamente campos de solicitud y IDs propios. Bajo bloqueo parada/lista/ítems en orden estable, conserva atención desde la base. Reemplazar recreación total por altas y actualizaciones en sitio. Proteger retiro de filas con atención y registrar operaciones permitidas con actor/motivo. `updatePedido` mantiene permiso `herramientas.registrar`, fase y cantidades, asigna actor desde sesión autenticada y registra evento en la misma transacción. Revisar también `syncImportedRows` y quitar inputs ocultos de atención en el guardado general.
- [ ] Pasar suite con flujo solicitar → entregar → recibir y probar que un rechazo mantiene las filas e historial. En la pantalla distinguir «Guardar solicitud» de «Registrar entrega/recepción» según permiso y fase.

### R5. Conservar documentos cuando falla el guardado

**Archivos.** `app/Modules/Logistica/Services/LogisticaDashboardService.php`, `app/Modules/Logistica/Controllers/LogisticaPageController.php`, `tests/Feature/LogisticaDashboardServiceTest.php`.

- [ ] Con `Storage::fake`, marcar un documento para retiro y fallar coherencia, SQL o evento. Afirmar referencia y bytes anteriores intactos. Probar dos editores con distinto orden de documentos, recepción concurrente y reintento del mismo retiro.
- [ ] Correr `php artisan test --filter LogisticaDashboardServiceTest` y verificar el borrado prematuro del caso original.
- [ ] Hacer pura la preparación de documentos en `normalizeTransportPayload`. Resolver identificador de documento estable y revisión esperada en lugar de confiar en índice de una vista antigua. Revalidar bajo bloqueo RQMina → transporte. Persistir referencia nueva y evento juntos. Mantener bytes históricos referenciados. Retirar de la vista vigente con trazabilidad y política de archivo, sin eliminar evidencia. Limpiar sólo temporales nuevos sin referencia después de fallo.
- [ ] Pasar pruebas de descarga privada, rechazo y recepción con otro editor. Mostrar mensajes que distingan «Cambios guardados» de «Revisa este documento o recarga la atención». Revisar filtros compactos en móvil sin perder selección.
