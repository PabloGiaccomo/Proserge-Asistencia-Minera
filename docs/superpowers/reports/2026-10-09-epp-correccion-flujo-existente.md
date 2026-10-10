# Correccion local: firma en Entregas y cambios de EPP

Fecha: 2026-10-09. Alcance aprobado: conservar el flujo operativo existente y agregar firma; no imponer inventario por almacenes.

Actualizacion posterior: el usuario preciso que varios articulos deben acumularse
y compartir una sola firma. Esa correccion reemplaza el comportamiento de
agregar-otra-después-de-firmar descrito en esta primera entrega; consultar
[firma unica para varios articulos](2026-10-09-epp-firma-unica-varios-articulos.md).

## Resultado funcional

- Logistica conserva su navegacion. Se retiraron los botones globales y los accesos duplicados de entrega/devolucion por lotes.
- En Entregas y cambios, Registrar entrega abre el formulario existente y reutiliza Identificacion de items (tallas, colores, atributos y vida util).
- Continuar a la firma prepara un resumen. La entrega solo se registra al confirmar la firma; salir del resumen no entrega EPP.
- Cerrar conserva el formulario de cambio/devolucion. El cambio o cierre ocurre despues de firmar, no antes.
- No se exige crear almacenes, existencias o movimientos de stock para este flujo. Las capacidades de inventario por almacenes permanecen separadas.
- Entregas nuevas firmadas y Kardex ofrecen Ver firma y Constancia. La evidencia privada se verifica contra su hash antes de mostrarla.
- Los registros anteriores mantienen su historial sin firmas ficticias. Cerrar esta disponible para registros anteriores y para el flujo firmado del catalogo; editar/eliminar historial protegido no se reintrodujo.

## Implementacion y datos

Servicio y controlador nuevos: `EppDeliverySignatureService` y `EppDeliverySignatureController`. Reutilizan EppService, aceptaciones, evidencia, PDF, permisos y alcance existentes. Vistas modificadas: workspace EPP, Logistica, verificacion y constancia; nueva vista delivery-signature. Dos rutas autenticadas con permiso de confirmar entrega/devolucion.

La migracion `2026_10_09_000100_allow_catalog_epp_operations_without_warehouse.php` permite almacen nulo. Se aplico localmente y en la base de pruebas. Solo se admite esta excepcion para operaciones marcadas explicitamente ENTREGAS_CAMBIOS y dentro del alcance del trabajador; no concede derechos de inventario, correccion, cancelacion o testigos.

La firma se guarda en almacenamiento privado. Registro, aceptacion y cierre son transaccionales; reenvios son idempotentes. Se bloquean cambios concurrentes de catalogo y entrega de origen. La devolucion conserva snapshots historicos de la entrega firmada. El PNG exclusivo se prepara una sola vez antes de posibles reintentos de transaccion y se limpia cuando no queda referenciado.

No se sembraron entregas ni firmas de prueba en la base operativa. No se eliminaron operaciones reales ni se inventaron almacenes. No se modificaron dependencias. Los cambios ajenos del workspace permanecen; no hay commit ni push de esta correccion.

## Verificacion

- Regresiones nuevas: 14 pruebas, 108 aserciones, sin fallos. Cubren formulario, firma, devolucion/cambio, idempotencia, hash, permisos revocados, alcance, catalogo cambiado, snapshots historicos, evidencia y Kardex.
- Revision independiente: los tres hallazgos importantes se corrigieron y revalidaron; sin pendientes Critical/Important. No se provoco un deadlock real: la prueba verifica preparacion de archivo antes de la transaccion y la reutilizacion se reviso en codigo.
- Sintaxis PHP, Pint de archivos afectados y compilacion Blade verificados.
- Harness de navegador sobre vistas renderizadas: firma, limpiar, redimensionar y anchos 320/375/390/430/1440. No sustituye una prueba autenticada en el navegador del usuario; ese acceso no estuvo disponible.
- JS: 9 pruebas aprobadas. No se cambiaron fuentes CSS/JS compilables en esta correccion, por lo que no requiere recompilar assets por este cambio.
- Suite global final sobre el codigo corregido: 1308 pruebas, 8039 aserciones, 40 fallos y 1 error. Comparacion de nombres contra `.superpowers/local-epp-full.xml`: mismos 41 casos, sin diferencias. Resultado: `.superpowers/epp-correction-full-final.xml` (318.9 segundos).
- Suite focalizada final aislada: 294 pruebas, 1972 aserciones, cero fallos/errores; resultado `.superpowers/epp-correction-isolated.xml`. Incluye Epp*, LogisticaDashboardServiceTest y LogisticaEppIntegrationTest; excluye unicamente el caso ya conocido de Herramientas, enumerado en el informe anterior.
- Se descarto una corrida focalizada simultanea con la global: el almacenamiento y la base de pruebas compartidos causaron un deadlock y una evidencia temporal ausente. La suite global final no reprodujo esos dos fallos; se repitio ademas la focalizada de forma aislada. No se presenta la corrida simultanea como validacion aprobada.

La suite global anterior a esta correccion tenia 40 fallos y 1 error. Los 41 casos estan enumerados por nombre en [el informe de integracion local](2026-10-09-epp-integracion-local.md#casos-globales-pendientes). La primera repeticion de esta correccion (1305 pruebas, 8021 aserciones) presento exactamente los mismos 41 casos, sin casos fallidos adicionales. No se declara la suite global verde ni se atribuye su origen sin investigacion.

Metodo: systematic-debugging y receiving-code-review guiaron el rastreo de la regresion; test-driven-development exigio regresiones fallando antes de cada correccion; requesting-code-review aporto revision independiente; verification-before-completion y documentation-sync guiaron las comprobaciones y la actualizacion documental.

## Uso y limites de entrega

Recargar Logistica con Ctrl+F5. Entregas y cambios -> Registrar entrega -> Continuar a la firma -> Firmar y registrar. Revisar Ver firma/Constancia en la entrega y Kardex.

Documentacion canonica sincronizada: EPP_FIRMA_OPERATIVA, EPP_AUTORIZACION, EPP_INVENTARIO, CODEX_CONTEXT, ARCHITECTURE_MAP y MAPA_ARCHIVOS. El plan original tiene una nota de correccion que reemplaza su supuesto de almacenes obligatorios para este flujo.

Pendiente manual: prueba autenticada con el usuario operativo y su trabajador, incluida firma tactil. Esta entrega es local, no una aprobacion de produccion. Antes de produccion siguen siendo necesarios respaldo vigente, ensayo de restauracion, revision SQL/migracion y los controles de liberacion del plan. La migracion es incremental; su down no fuerza almacenes ficticios sobre evidencia existente.
