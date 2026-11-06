# Plan de correcciones por módulo

4 de noviembre de 2026. Ocho planes agrupan las tareas por flujo. Cada hallazgo de la auditoría tiene una tarea con archivos, prueba de regresión y condición de aceptación. [Diseño propuesto](../specs/2026-11-04-correcciones-modulos-y-rrhh-masivo-design.md) · [Auditoría](../reports/2026-11-04-auditoria-modulos-transversal.md)

| Plan | Hallazgos | Resultado esperado |
|---|---|---|
| [Personal y actualización por enlace](2026-11-04-personal-actualizacion-segura.md) | PER-01, PER-02, PER-04, PER-05, PER-06 | Edición segura. Actualización individual recibida en Ingresos, protegida y pendiente de aceptación. Formulario minero recuperable. |
| [Revisión masiva de Ingresos](2026-11-04-ingresos-revision-masiva.md) | Ampliación solicitada | Revisar y aceptar varias altas y actualizaciones con resultado por persona. |
| [Carga masiva de contratos firmados](2026-11-04-contratos-carga-masiva.md) | PER-03 y ampliación solicitada | Seleccionar varios PDF individuales, asociar cada uno al periodo correcto y confirmar con resultado por archivo. |
| [Transporte](2026-11-04-transporte-flujo-y-alcance.md) | R1, R4, R6 | Alcance en todas las acciones. Reubicación completa o reversible. Alta, conductor, edición y confirmación desde la misma pantalla. |
| [Asistencia y móvil](2026-11-04-asistencia-grupos-y-movil.md) | OP-01, OP-02, TR-02 | Elegir grupo, marcar con permiso, conservar contexto y cerrar sin contradicción de faltas. |
| [Logística y Herramientas](2026-11-04-logistica-herramientas-integridad.md) | R2, R3, R5 | Servicios por mina. Solicitud separada de entrega y recepción. Documentos conservados ante rechazo. |
| [Operación e indicadores](2026-11-04-operacion-historial-e-indicadores.md) | OP-03, OP-04, OP-05, OP-06 | Historial de Man Power, Duplicar independiente, ejecución recalculada y evaluación pendiente de revisión. |
| [Seguridad, consulta y soporte](2026-11-04-seguridad-consulta-y-soporte.md) | TR-01, TR-03, TR-04, TR-05, TR-06 | Dashboard API autorizado, Perfil coherente, Roles con confirmación real, auditoría de Bienestar y límite en login API. |

## Orden de implementación

1. **Proteger datos y permisos.** Ejecutar primero PER-01/02/04/05, PER-03, R1/2/3, OP-01/02 y TR-01. Personal y contratos comparten servicios y `routes/web.php`, por lo que sus cambios se integran en orden. Transporte y Asistencia se pueden probar en paralelo con archivos de rutas coordinados.
2. **Completar flujos que hoy se atascan.** R4/R6 en Transporte, TR-02 en móvil, PER-06 en formularios mineros y R5 en Logística. OP-05 y OP-04 se integran después del orden de bloqueo de OP-02. La protección de archivos puede avanzar en paralelo con los grupos.
3. **Entregar actualización y lotes a RR. HH.** Primero el recorrido individual de propuesta, comparación y aceptación. Después, aceptación masiva. La carga de firmados puede avanzar en paralelo una vez definida la escritura contractual canónica. Las altas que preparan contrato entregan su ID a la bandeja de firmados. La actualización de ficha conserva su propio recorrido.
4. **Cerrar ajustes de soporte.** Historial y duplicación de Man Power, Perfil, confirmación de roles, Bienestar y limitación de intentos. Las tareas de seguridad y verificación pueden ordenarse por riesgo sin esperar a las pantallas masivas.

## Comprobaciones de entrega

- Ejecutar los comandos de prueba desde una sesión dedicada con `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=13376` y `DB_DATABASE=proserge_app_test`. Confirmar instancia aislada activa, nombre de base y puerto efectivos antes de correr suites. Usar `DB_URL` y `DB_SOCKET` vacíos y credenciales de prueba. La configuración por defecto de PHPUnit apunta a 3306.
- Pruebas de permiso, alcance y datos sensibles con roles limitados y fixtures sintéticos. En cada módulo, ejecutar la regresión focalizada, las integraciones afectadas, `php -l` y `git diff --check`.
- Si cambia Blade, compilar vistas. Si cambian fuentes Vite, compilar assets. Revisar 390 y 1440 px y completar ensayo táctil para las pantallas de uso en campo.
- Los lotes se prueban con fallos por fila, conflicto de versión, permiso revocado, timeout, repetición, documentos inválidos y reintento selectivo. Confirmar conteos y resultado persistido.
- Las migraciones se verifican sobre base aislada con variantes histórica y actual. La preparación de un despliegue debe incluir los archivos de la entrega, copia de base y documentos privados, migraciones y prueba posterior con roles representativos.

## Verificación ejecutada para estos planes

- Transporte. Con SQLite en memoria se reprodujeron autorización insuficiente, pérdida de alcances al rechazar la solicitud y reubicación parcial. El código de la pantalla confirma que falta la entrada de conductor y la edición del servicio.
- Mi Asistencia. El controlador funciona con lista vacía y falla con una fila por discrepancia de tipo. La vista también busca claves antiguas. La prueba utilizó un servicio simulado con el contrato actual.
- Formulario minero. El generador real de nota y la vista confirman ausencia de obligatoriedad condicional y de recuperación al fallar. [Detalle y comandos de verificación](../../../storage/app/module-plan/2026-11-04/transporte-movil-verificacion.md)

Los planes proponen vigencias y tamaños de tanda ajustables. El enlace general es el alcance inicial de actualización. El acceso a campos sensibles mediante enlace queda sujeto a verificar identidad por un canal independiente. La activación de un firmado futuro sigue el flujo contractual al llegar la fecha de inicio. Los ensayos completos en navegador, dispositivo y concurrencia se realizan con cada implementación.

## Ejecución local

El estado de implementación, los archivos principales, la evidencia ejecutada y
las comprobaciones pendientes se mantienen en el
[registro de correcciones](../reports/2026-11-04-correcciones-modulos-implementadas.md).
Las casillas de los planes describen el alcance completo propuesto y requieren
contrastar cada criterio con ese registro.
