# Plan de implementación de historial e indicadores operativos

> Para ejecutar las tareas usar `superpowers:subagent-driven-development` o `superpowers:executing-plans`.

**Objetivo.** Resolver OP-03, OP-04, OP-05 y OP-06 en Man Power, Evaluaciones y Faltas.

**Arquitectura.** Preservar movimientos en bitácora, reutilizar creación autorizada según operación y recalcular proyecciones con la asistencia que las origina.

**Tecnología.** Laravel 12, MySQL, Blade y JavaScript del repositorio. [Diseño general](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md#correcciones-complementarias)

### OP-03. Preservar retiros y reingresos de Man Power

**Archivos.** `app/Modules/ManPower/Services/GrupoTrabajoService.php`, `app/Models/GrupoTrabajoDetalle.php`, `resources/views/man-power/workspace.blade.php`, `tests/Feature/ManPowerApiTest.php`. Crear `app/Models/GrupoTrabajoDetalleMovimiento.php` y migración `2026_11_04_000800_create_grupo_trabajo_detalle_movimientos.php`.

- [ ] Probar asignar, retirar con motivo y readmitir con otro origen RR. HH. Debe quedar un integrante activo y conservar el retiro anterior con actor, fecha, motivo y origen. Probar reubicación fallida, retiro anterior a la migración y referencia de asistencia histórica.
- [ ] Correr `php artisan test --filter ManPowerApiTest` y reproducir pérdida del retiro.
- [ ] Guardar eventos append-only con detalle, grupo, estado, asignación anterior/nueva, actor, motivo y fecha. `markDetalleRetired` y `addAssignmentToGrupo` guardan eventos en su transacción. Para una fila legada retirada, preservar sus valores conocidos antes de reutilizarla. Respetar la unicidad vigente y las referencias de asistencia. Filtrar conteos por distribución activa.
- [ ] Repetir suite y probar resumen de movimientos en el contexto del grupo. La bitácora queda disponible sólo con acceso al grupo.

### OP-06. Hacer que Duplicar funcione con su propio permiso

**Archivos.** `app/Modules/ManPower/Services/GrupoTrabajoService.php`, `app/Modules/ManPower/Policies/ManPowerPolicy.php`, `resources/views/man-power/workspace.blade.php`, `tests/Feature/ManPowerApiTest.php`.

- [ ] Probar `duplicar=true/crear=false` para grupo, día y rango, con scope correcto. Probar `crear=true/duplicar=false`, destino de otra mina y copia con integrantes sin permiso de asignar.
- [ ] Correr `php artisan test --filter ManPowerApiTest` y verificar que Duplicar solo falla hoy.
- [ ] Separar `createGrupo` y `copyGrupo` como fronteras de autorización. Extraer persistencia privada compartida después del control de cada acción. Exigir Asignar cuando se copian integrantes y mantener destino, plan, fechas, cupo y estados. Alinear botones de Copiar/Pegar con las capacidades. Un lote de copia fallido conserva todo el destino.
- [ ] Repetir suite y comprobar el mensaje y el retorno al día/plan seleccionado.

### OP-05. Recalcular ejecución al corregir la falta

**Archivos.** `app/Modules/Faltas/Services/CorregirFaltaService.php`, `app/Modules/Faltas/Services/FaltasService.php`, `app/Modules/Asistencia/Services/ParadaExecutionMetricsService.php`, `tests/Feature/FaltasApiTest.php`, `tests/Feature/AsistenciaFaltasWebTest.php`.

- [ ] Cerrar con ausente y corregir a presente. Afirmar falta CORREGIDA, marca PRESENTE y ejecución real/ausentes/brechas recalculados para la actividad. Forzar fallo de proyección y comprobar rollback completo. Reintentar con falta ya corregida y revisar que historial no se sobrescribe.
- [ ] Ejecutar `php artisan test --filter 'FaltasApiTest|AsistenciaFaltasWebTest'` y reproducir proyección obsoleta.
- [ ] Aplicar el orden común del [plan Asistencia](2026-11-04-asistencia-grupos-y-movil.md), con relectura de relaciones y capacidad vigente. Inyectar `ParadaExecutionMetricsService` y llamar `recalculateGrupo($grupo, persist: true)` dentro de la transacción para detalle y falta con grupo vinculado. Mantener el camino legado de falta sin grupo si el origen es válido, sin atribuir métricas a otro grupo. Conservar motivo y hora ante validación web.
- [ ] Repetir suite y leer ejecución por API y dashboard sin recalcular manualmente.

### OP-04. Revisar evaluaciones afectadas por una asistencia corregida

**Archivos.** `app/Models/EvaluacionDesempeno.php`, `app/Modules/Evaluaciones/Services/EvaluacionDesempenoService.php`, `app/Modules/Evaluaciones/Services/PromedioDesempenoService.php`, `app/Modules/Evaluaciones/Services/EvaluacionesDashboardService.php`, `app/Modules/Asistencia/Services/AsistenciaService.php`, `routes/web.php`. Crear `app/Modules/Evaluaciones/Services/EvaluacionAsistenciaRevisionService.php`, migración `2026_11_04_000900_add_revision_to_evaluaciones_de_asistencia.php` y `tests/Feature/EvaluacionAsistenciaRevisionTest.php`.

- [ ] Probar PRESENTE → evaluación → reapertura → AUSENTE. La fila y nota original permanecen, el estado es pendiente y ya no cuenta en agregados. Otro usuario no resuelve. Retornar a estado elegible conserva la revisión pendiente hasta que resuelva el evaluador original. Incluir importadas/directas y evaluación con relación eliminada históricamente.
- [ ] Correr `php artisan test --filter 'EvaluacionAsistenciaRevisionTest|PromedioDesempeno|EvaluacionesWebTest'` y reproducir el promedio incorrecto.
- [ ] Agregar estado de revisión, origen, fecha y resolución auditada. `markPendingForDetail(AsistenciaDetalle $detalle, Usuario $actor): void` se ejecuta cuando el detalle cambia a AUSENTE, dentro del orden de locks de asistencia. `resolve(string $id, Usuario $evaluador, string $decision, string $motivo): EvaluacionDesempeno` exige evaluador original y permite ratificar sólo cuando la asistencia respalda la evaluación o excluirla con historial. Agregados, comparaciones y API legacy usan un único predicado de inclusión. Recalcular trabajador afectado. Auditar desajustes legados con simulación antes de cualquier actualización histórica.
- [ ] Pasar tests de ciclo completo, alcance y ranking. Mostrar «Pendiente de revisión por su evaluador» en historial, y comprobar evaluación válida y excluida en el mismo periodo.
