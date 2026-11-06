# Plan de implementación de Asistencia, grupos y móvil

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`. Cada casilla tiene un resultado comprobable.

**Objetivo.** Resolver OP-01, OP-02 y TR-02. Elegir el grupo correcto, marcar con autorización y recibir confirmación coherente con el cierre.

**Arquitectura.** `AsistenciaPolicy` separa lectura de escritura. La vista móvil usa el modelo de grupo ya autorizado y su resumen de vista. Los servicios de asistencia comparten bloqueos con la corrección de falta.

**Tecnología.** Laravel 12, MySQL, Blade, JavaScript y Chrome de prueba.

**Diseño.** [Transporte y asistencia móvil](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#transporte-y-asistencia-móvil)

### OP-01. Separar ver todos los grupos de registrar o cerrar

**Archivos.** `app/Modules/Asistencia/Policies/AsistenciaPolicy.php`, `app/Modules/MiAsistencia/Controllers/MiAsistenciaPageController.php`, `app/Modules/Asistencia/Services/AsistenciaService.php`, `tests/Feature/MiAsistenciaWebTest.php`, `tests/Feature/AsistenciaApiTest.php`.

- [ ] Probar lector con scope de la mina y `mi_asistencia.ver_todas_asistencias` frente a un grupo ajeno. GET funciona. POST individual, masivo y cierre devuelven 403 sin marca ni falta. Incluir responsable real, permiso sólo registrar, permiso sólo cerrar, ausencia de vinculación y mina fuera de scope.
- [ ] Ejecutar `php artisan test --filter 'MiAsistenciaWebTest|AsistenciaApiTest'` y comprobar la regresión.
- [ ] Ajustar `canRegisterGrupo` y `canCloseGrupo` para requerir su capacidad directa más acceso, preservando la excepción documentada del responsable real con `mi_asistencia.ver`. Usar `canViewAllAttendances` sólo para lectura. Alinear capacidades de Blade y acciones del servicio, con identidad válida para auditar y un mensaje accionable si falta asignación de responsable.
- [ ] Repetir tests. Revisar cualquier fixture anterior que combinaba ver todos con escritura implícita. Conservar los privilegios de alcance de `MinaScopeAccess` sin convertirlos en permiso de escritura.

### OP-02. Serializar marcar, cerrar y reabrir

**Archivos.** `app/Modules/Asistencia/Services/AsistenciaService.php`, `app/Modules/Asistencia/Services/AsistenciaCierreService.php`. Crear `tests/Feature/AsistenciaCierreConcurrenciaTest.php`. Coordinar con OP-05 en el plan de operación.

- [ ] Preparar una prueba de dos conexiones MySQL aisladas con barreras. Pausar marca antes de adquirir el bloqueo común, cerrar en otra conexión y reanudar. Esperar rechazo por `ASISTENCIA_ALREADY_CLOSED`, falta ACTIVA coherente y detalle AUSENTE. Invertir el orden y verificar que cierre observa PRESENTE. Repetir con masivo, reapertura y grupo cancelado.
- [ ] Ejecutar el test con esquema aislado y fixtures propios visibles por las dos conexiones. Una transacción de fixture sin commit no sirve para este ensayo. Limpiar sólo registros de prueba identificados.
- [ ] Mantener orden GrupoTrabajo → AsistenciaEncabezado → AsistenciaDetalle ordenados por ID → Falta. Releer y comprobar autorización, identidad de grupo, estado y detalle activo en esa transacción. Incluir creación de encabezado, ausentes por horario y escritura de métricas afectadas en el mismo protocolo. Reintento por interbloqueo acotado cuando todos los efectos son transaccionales.
- [ ] Repetir concurrencia y suite de asistencia/faltas. Confirmar que un rechazo conserva las marcas previas y que el cierre no genera duplicados.

### TR-02. Recuperar grupo y formularios móviles

**Archivos.** `app/Modules/MiAsistencia/Controllers/MiAsistenciaPageController.php`, `app/Modules/Asistencia/Resources/AsistenciaGrupoResource.php`, `resources/views/mi-asistencia/mobile.blade.php`, `resources/views/mi-asistencia/mobile-empty.blade.php`, `resources/views/mi-asistencia/show.blade.php`, `tests/Feature/MiAsistenciaWebTest.php`. Crear `tests/Browser/mi-asistencia-grupos-mobile.cjs`.

- [ ] Probar GET sin grupos, uno y varios, turno día/noche y grupo que dejó de estar accesible. La lista actual devuelve `id`, `mina`, `estado_asistencia`, `total`. El GET actual pasa un `stdClass` a `canRegisterGrupo(Usuario, GrupoTrabajo)` y la vista espera claves antiguas. Probar cerrado, cancelado, sin responsable, sin identidad vinculada y detalle activo propio ausente.
- [ ] Ejecutar `php artisan test --filter MiAsistenciaWebTest`. Confirmar que el caso con grupo falla antes de aplicar el arreglo.
- [ ] Validar fecha, turno y `grupo_id` opcional en `mobile`. Seleccionar el único grupo o mostrar el selector cuando haya varios. Cargar el modelo permitido vía `getGrupo`, resolver `AsistenciaGrupoResource` y exponer un modelo de vista con `id`, `mina`, `turno`, `horario`, `estado`, `total`, `puede_registrar` y `detalle_propio_id` nullable. El POST usa `grupo_trabajo_detalle_id` real del grupo. Si corresponde gestionar otros integrantes, abrir el detalle autorizado. Separar consulta de marcación sin identidad o estado apto.
- [ ] Conservar fecha, turno, grupo y entradas no sensibles tras 422. Ante 401 pedir volver a iniciar sesión y recuperar el contexto seguro. Ante 403 o 409 refrescar capacidad/estado. Ante 500 o conexión perdida consultar estado de esa marca antes de reintentar. Evitar doble toque y mostrar confirmación sólo tras respuesta válida. No usar localStorage para nóminas o datos personales.
- [ ] En Chrome a 390 y 1440 px probar selección, error visible, filtros de regreso y que teclado/barra inferior no tapen acción ni mensajes. Probar 401, 403, 422, error de red, grupo cerrado durante la edición y dos toques rápidos. Ejecutar pruebas focalizadas, `php artisan view:cache` y `git diff --check`.
