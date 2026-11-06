# Plan de implementación de seguridad, consulta y soporte

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`.

**Objetivo.** Resolver TR-01, TR-03, TR-04, TR-05 y TR-06. Cada pantalla y API muestra los datos permitidos y comunica el resultado real.

**Arquitectura.** Reutilizar autorización de dominio. Guardar auditoría junto con las decisiones y procesar avisos secundarios después del commit. Mantener separadas identidad, alcance y habilitación.

**Tecnología.** Laravel 12, MySQL y Blade. [Diseño general](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#correcciones-complementarias)

### TR-01. Autorizar el Dashboard API por dominio

**Archivos.** `app/Modules/Dashboard/Policies/DashboardPolicy.php`, `app/Modules/Dashboard/Controllers/DashboardController.php`, `app/Modules/Dashboard/Services/DashboardService.php`, `app/Modules/Evaluaciones/Policies/EvaluacionesPolicy.php`, `app/Modules/Evaluaciones/Services/EvaluacionesDashboardService.php`, `tests/Feature/DashboardApiTest.php`.

- [ ] Probar con `inicio.ver` solamente. `/dashboard/evaluaciones` debe devolver 403 y `/dashboard/principal` conservar su estructura con secciones restringidas nulas, sin nombres, IDs, notas ni conteos de esos dominios. Probar acceso por tipo/propietario, filtros de otra mina y privilegios de roles activos. Revisar clientes API conocidos y documentar el cambio esperado.
- [ ] Ejecutar `php artisan test --filter DashboardApiTest` y confirmar el 200 indebido en el nuevo caso.
- [ ] Incorporar `DashboardPolicy::canViewSection(Usuario $usuario, string $section): bool`. Mapear secciones a la capacidad canónica del dashboard web correspondiente. Aplicar antes de consultar, incluso en `resumen` y `alertas` agregados. Evaluaciones limita tipos, propietario, puntajes y mina con los mismos predicados que la consulta web. Conectar la exclusión por revisión de OP-04 cuando exista.
- [ ] Repetir tests de contrato y aislamiento. Los clientes autorizados mantienen formato y los no autorizados no reciben datos derivados.

### TR-03. Distinguir alcance de usuario, habilitación y evaluaciones en Perfil

**Archivos.** `app/Modules/Perfil/Controllers/PerfilPageController.php`, `resources/views/perfil/index.blade.php`. Crear `tests/Feature/PerfilResumenTest.php`.

- [ ] Probar mina por scope sin relación, EN_PROCESO, NO_HABILITADO, habilitación vigente e inactiva. Probar evaluación autorizada, ninguna y permiso insuficiente. Verificar que el HTML no contiene puntajes ajenos.
- [ ] Correr `php artisan test --filter PerfilResumenTest` y verificar los dos indicadores engañosos.
- [ ] Preparar `minasDeAcceso`, `habilitaciones` con estado canónico y `evaluacionesResumen` nullable con lectura autorizada por trabajador vinculado. Mostrar «Sin evaluaciones» sólo ante consulta autorizada con cero resultados y «No disponible» cuando no se pueda consultar. Reutilizar política de puntajes y no usar un resumen global del usuario.
- [ ] Pasar test y revisar estados y etiquetas en móvil y escritorio.

### TR-04. Confirmar roles aunque falle un aviso

**Archivos.** `app/Modules/Seguridad/Controllers/RolPageController.php`, `app/Modules/Seguridad/Services/RoleManagementService.php`, `app/Modules/Notificaciones/Services/NotificationService.php`, `routes/console.php`, `tests/Feature/RolePermissionCheckboxTest.php`. Crear `app/Models/RolCambioEvento.php`, migración `2026_11_04_001000_create_rol_cambio_eventos.php`, `app/Console/Commands/RetryRoleChangeNotificationsCommand.php` y `tests/Feature/RoleChangeConfirmationTest.php`.

- [ ] Probar error al emitir después de actualizar rol. El guardado confirma y existe un evento de cambio pendiente. Probar error de auditoría antes del commit, reintento de aviso, doble entrega y preferencia de notificaciones desactivada.
- [ ] Correr `php artisan test --filter 'RoleChangeConfirmationTest|RolePermissionCheckboxTest'` y verificar fallo del primer caso.
- [ ] Persistir evento con actor, rol, fecha y permisos cambiados en la transacción de `updateWithNotificationPreferences`. Usar ese evento como trabajo pendiente de aviso. Tras commit, intentar emitir con dedupe por evento. El comando reintenta fallos con límite, distingue ausencia de receptores de error y evita duplicados. La respuesta confirma el guardado y comunica el estado del aviso si afecta la operación. Mantener auditoría accesible sólo para quien corresponda.
- [ ] Repetir suite, revisar auditoría y checkboxes, y forzar que un fallo del aviso no devuelva error del guardado.

### TR-05. Auditar cambios de bloqueos en Bienestar

**Archivos.** `app/Modules/Bienestar/Controllers/BienestarPageController.php`, `resources/views/bienestar/edit-bloqueo.blade.php`, `resources/views/bienestar/show.blade.php`, `tests/Feature/BienestarBloqueosTest.php`. Crear `app/Modules/Bienestar/Services/BienestarBloqueoService.php`, `app/Models/PersonalBloqueoEvento.php` y migración `2026_11_04_001100_create_personal_bloqueo_eventos.php`.

- [ ] Probar editar y anular. El actor y motivo de decisión quedan separados del creador y del motivo original. Probar anulación sin motivo, reintento, falta de permiso y fallo al registrar evento.
- [ ] Correr `php artisan test --filter BienestarBloqueosTest` y verificar la falta de historial actual.
- [ ] Implementar `update(PersonalBloqueo $bloqueo, Usuario $actor, array $changes, string $reason): PersonalBloqueo` y `annul(PersonalBloqueo $bloqueo, Usuario $actor, string $reason): PersonalBloqueo`. Releer y bloquear antes de cambiar. Guardar campos anteriores y nuevos en evento privado, cifrado cuando contenga datos de salud. Pedir motivo de decisión y mostrar historial autorizado. Mantener el cambio circunscrito al bloqueo.
- [ ] Pasar suite. Revisar mensajes de error, retorno al trabajador/mes y edición móvil.

### TR-06. Limitar intentos de acceso por API

**Archivos.** `app/Modules/Auth/Controllers/AuthController.php`, `app/Modules/Auth/Controllers/LoginPageController.php`, `routes/api.php`. Crear `app/Modules/Auth/Services/LoginAttemptLimiter.php` y `tests/Feature/AuthLoginRateLimitTest.php`.

- [ ] Probar cinco fallos en una combinación cuenta/IP y bloqueo del sexto. Probar canales alternados web/API, cuenta distinta, IP distinta, expiración, respuesta API 429 con `Retry-After` y que no se emita token durante bloqueo.
- [ ] Correr `php artisan test --filter AuthLoginRateLimitTest` y verificar que la API continúa aceptando intentos de validación.
- [ ] Compartir una clave normalizada por cuenta e IP con hash, cinco fallos en 300 segundos como la política web actual. Un éxito permitido limpia el contador. Mantener mensajes indistinguibles para credenciales inválidas y comprobar proxy/IP confiable antes de habilitar. Conservar contrato de login exitoso y el mensaje de espera de web.
- [ ] Repetir tests con tiempo controlado y cache aislada. Verificar que claves y logs no muestran correo ni contraseña.
