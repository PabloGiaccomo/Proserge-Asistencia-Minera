# Correcciones de módulos y operaciones masivas de RR. HH.

Implementación local del 4 y 5 de noviembre de 2026. El trabajo comparte un checkout
con cambios anteriores. Este registro identifica el alcance nuevo y sus límites.
El despliegue FTP continúa pendiente.

El 5 de noviembre se recuperó la conexión de MySQL local y se aplicaron las
nueve migraciones de esta entrega después de ensayarlas sobre copia y comparar
los datos previos. Ver el [registro de recuperación local](2026-11-05-recuperacion-mysql-local.md).

## Recorridos disponibles

| Flujo | Comportamiento implementado |
|---|---|
| Personal | La edición general conserva las relaciones mineras. Oficinas y talleres usan su servicio. Los permisos sensibles se verifican en escritura, lectura y exportación de fichas, incluido el recurso de Personal. |
| Actualización por enlace | Desde Editar trabajador se solicita y copia un enlace individual de 72 horas para datos personales, nacimiento, contacto, domicilio, estudios, tallas y nuevos familiares. El fragmento se canjea por una sesión de 30 minutos. El borrador y las revisiones se cifran. Ingresos recibe la propuesta y RR. HH. compara y acepta u observa. |
| Ingresos masivos | Seleccionar hasta 50 fichas, indicar periodos de las altas, revisar dentro del lote y aceptar hasta 10 por solicitud. Se muestran familiares declarados y propuestos. Una actualización muestra el valor actual y el propuesto. Cada éxito y su resultado se guardan juntos. Mis revisiones recientes permite retomar los lotes propios. |
| Contratos firmados | Seleccionar varios PDF individuales, asociar cada uno a una preparación exacta y confirmar hasta 20 por solicitud. El resultado permite retomar después de una respuesta perdida. |
| Firmados futuros | El PDF queda en preparación hasta su fecha. La bandeja ofrece contratos listos para activar y el historial individual también permite activarlos. |
| Documentos | El reemplazo conserva bytes previos y versión privada con hash y metadatos cifrados. Se comprueban las copias y se limpian archivos nuevos sin referencia al revertir una transacción. |
| Transporte | Capacidades por acción, alcance por mina, conductor interno o externo, edición y confirmación desde la planificación. Los traslados revierten de forma conjunta y se evita doble viaje activo de la misma persona en el mismo tramo. |
| Logística | El contexto de Servicios filtra minas y paradas antes de limitar resultados. Atención, entrega, recepción y cambio guardan sus propios campos. Los documentos usan identificadores estables y los retirados conservan evidencia en eventos. |
| Herramientas | Guardar la solicitud conserva entregas, recepciones y evidencia. La atención requiere una acción específica. Las filas atendidas tienen identificadores estables y protecciones frente a eliminación o reducción de cantidad. |
| Asistencia y móvil | Selector de grupo, permisos y retorno coherentes. La marcación consulta el resultado después de fallos de red. Cierre, reapertura, faltas y correcciones comparten un orden de bloqueo. |
| Man Power | Historial de retiros, readmisiones y reubicaciones. Duplicar funciona con su permiso. Copiar integrantes o reemplazar grupos con personal requiere asignación. |
| Faltas y evaluación | Corregir una falta actualiza la ejecución del grupo. Cambiar a ausente una asistencia evaluada deja revisión pendiente y retira la nota de agregados. El evaluador original ratifica con asistencia corregida/cerrada o excluye con motivo. |
| Dashboard y Perfil | Los datos derivados de la API requieren capacidad de cada dominio. Perfil distingue alcance de cuenta y habilitación, y calcula únicamente evaluaciones autorizadas del trabajador vinculado. |
| Roles | El cambio de permisos y su evento se guardan juntos. Un aviso fallido queda pendiente y el guardado se confirma. El scheduler reintenta por evento con deduplicación. |
| Bienestar | Edición y anulación registran actor, motivo de decisión e historial cifrado. El fallo al registrar el evento revierte la decisión. |
| Acceso | Web y API comparten el límite de cinco fallos por cuenta/IP en cinco minutos. |
| Formulario minero | Un intento rechazado recupera trabajador, asignación, examen, acción y campos. La nota requerida y su mínimo usan el snapshot del examen. El archivo se vuelve a seleccionar. |

## Archivos principales

Los puntos de entrada nuevos están en los controladores
`PersonalIngresoUpdateController`, `PersonalIngresoBatchReviewController`,
`PersonalContratoSignedBatchController` y `EvaluacionAsistenciaRevisionController`.
Las reglas viven en sus servicios y reutilizan los servicios de ficha,
aceptación, contratos y promedios existentes.

`PersonalArchivoVersionService` centraliza conservación y limpieza de versiones.
`RoleChangeNotificationService` y `roles:retry-change-notifications` separan
la confirmación de permisos del envío del aviso.

Las vistas nuevas de RR. HH. son
`personal/contratos/carga-firmados.blade.php`,
`personal/fichas/ingresos-lote.blade.php`,
`personal/fichas/actualizacion-review.blade.php` y
`ficha-public/actualizacion.blade.php`.
Los cambios de rutas son localizados en `routes/web.php` y `routes/console.php`.

## Verificación

Se usó exclusivamente la instancia aislada
`127.0.0.1:13376/proserge_app_test` y datos sintéticos. Las pruebas de negocio
usan transacciones o limpieza explícita. Se verificaron rechazos por permiso,
propiedad del lote, expiración, PDF inválido, periodo cambiado, repetición,
ficha cambiada, actor ajeno, conservación de contratos e historial y rollback.

Las pruebas de dos conexiones cubren cierre/marcación de asistencia, ocupación
del último asiento y traslados opuestos de transporte. El ensayo de transporte
sincroniza el worker al llegar al bloqueo del servicio, antes de comprobar la
espera en InnoDB.

La revisión independiente añadió regresiones de dos enlaces abiertos en pestañas
distintas, reintento de alta con UUID de actualización, reenvío de una ficha
aceptada y carga de adjuntos antes de publicar el ingreso para revisión.
También reprodujo y corrigió carreras de creación/edición de evaluación contra
una asistencia que pasa a ausente. La asociación de PDF y su activación comparan
los términos contractuales y el snapshot bajo bloqueo.

Chrome con Playwright verificó 390 y 1440 px para los lotes de RR. HH.,
actualización por enlace, recuperación de intentos mineros, Transporte y
Mi Asistencia. Las pruebas de lotes incluyeron pérdida de respuesta y consulta
antes del reintento. Se inspeccionaron capturas sintéticas de contratos e Ingresos.
Los escenarios de navegador usan respuestas simuladas y las rutas reales se
comprueban por separado mediante Feature.

Las salidas de pruebas permanecen en
`storage/app/rq-mina-audit/2026-11-04/`.

- Suite integrada del 5 de noviembre con 775 pruebas y 7.848 aserciones
  aprobadas, incluidos RQ Mina y RQ Proserge, registrada en
  `correcciones-noviembre-final.xml`.
- Después del último ajuste de familiares declarados en altas, el focalizado
  de lotes, evaluaciones, descargas, documentos y regularización aprobó
  44 pruebas y 391 aserciones, registrado en `rrhh-cierre-final.xml`.
- La tanda de ampliación de ficha, lotes, concurrencia y esquema aprobó
  32 pruebas y 409 aserciones en `rrhh-noviembre-final.xml`.
- Transporte repitió tres veces sus dos escenarios de concurrencia con 24
  aserciones por ejecución y resultado aprobado.
- Compilación Blade, build Vite, sintaxis de 184 archivos PHP nuevos o
  modificados del checkout y `git diff --check` aprobados. Pint pasó sobre
  los 16 PHP seleccionados de esta continuación. Los cinco PHP formateados
  al cierre volvieron a pasar sintaxis.
- El scheduler registra limpieza de PDF cada 30 minutos y reintento de avisos
  de Roles cada cinco minutos.
- OP04 añadió 13 escenarios en `EvaluacionAsistenciaRevisionTest`, incluidos
  evaluaciones históricas y directas, asistencia eliminada, evaluador original
  y legado, reintentos y decisiones obsoletas. La nota se conserva y los
  promedios, las comparaciones y el dashboard excluyen las revisiones pendientes.
  Dos conexiones reales bajo REPEATABLE READ reprodujeron revocaciones de rol
  principal, rol adicional, cuenta y mina durante la espera del bloqueo. La
  resolución relee esas capacidades con bloqueo compartido y comprueba que la
  asistencia pertenezca al trabajador evaluado. La tanda con Asistencia API,
  Dashboard API y recálculo de promedios aprobó 46 pruebas y 344 aserciones.
- Ocho escenarios de RR. HH. con procesos y conexiones reales verificaron DNI
  coincidente entre altas, el mismo PDF en lotes independientes, dos reintentos
  de la misma fila, revocaciones de permisos y cambios de ficha o contrato
  durante la espera. Se conservan una persona/contrato o un archivo/resultado
  donde corresponde. Se reprodujo la lectura obsoleta del resultado de Ingresos
  y se corrigió leyendo la fila bajo bloqueo; los permisos se releen después
  de adquirir el bloqueo del lote. Los temporales usan discos propios de prueba.
- Las nueve migraciones se probaron en esquemas desechables con CHAR(36) y
  VARCHAR(36). La reanudación parcial de Ingresos y Evaluaciones conserva
  columnas y valores existentes. Las versiones de documentos admiten las rutas
  históricas de 500 caracteres. Se corrigieron y reprodujeron ambos defectos.
- Los enlaces generales y la comparación de familiares se verificaron de nuevo
  a 390/1440 px. Incluyen fecha familiar desconocida, duplicados y conflictos,
  conservación de familiares previos y compatibilidad de sobres anteriores.
  Una revisión independiente no encontró hallazgos accionables en esos cambios.

## Decisiones y alcance pendiente

- El enlace habilita datos generales y altas familiares. No reemplaza ni elimina
  familiares vigentes. Los duplicados documentales exactos no se agregan; las
  coincidencias con diferencias requieren revisión interna. Los adjuntos de
  actualización quedan pendientes. Los datos sensibles se tramitan mediante la
  revisión autenticada. Los enlaces anteriores conservan su revisión original.
- Una propuesta observada conserva su envío. RR. HH. puede emitir otra solicitud
  desde el trabajador. La reemisión dentro de la misma propuesta queda pendiente.
- Las altas coincidentes con Personal se resuelven individualmente. El buscador
  de preparaciones devuelve hasta veinte contratos por búsqueda y permite afinar
  por nombre o documento.
- Los datos de cada ficha se cargan dentro del lote cuando RR. HH. abre
  **Revisar datos aquí**. La casilla de revisión se habilita después de esa
  consulta. La vista inicial conserva un resumen mínimo.
- Se reutilizó la escritura canónica de contratos y sus campos de firma y
  activación. Los lotes conservan asociaciones, revisiones, propietario y
  resultado por archivo.
- La validación estructural de PDF usa FPDI instalado. Un formato con compresión
  no admitida indica el acceso al contrato individual. Los PDF protegidos
  requieren otro documento legible.
- Quedan por ensayar volumen realista en cPanel y uso táctil en un
  dispositivo físico. La verificación de permisos y los ensayos sintéticos
  preceden a la prueba operativa con las áreas usuarias.
- La revisión por cambio de asistencia aplica a nuevas correcciones. Auditar
  desajustes anteriores requiere una ejecución separada con simulación.

## Despliegue

Aplicar las nueve migraciones incrementales del 4 de noviembre junto al código,
después de respaldar y ensayar sobre copia. Conservar APP_KEY y el almacenamiento
privado. Publicar el build generado y comprobar scheduler, límites PHP y
recorridos con roles representativos. El procedimiento canónico está en
`docs/OPERACION_DESPLIEGUE.md`.
