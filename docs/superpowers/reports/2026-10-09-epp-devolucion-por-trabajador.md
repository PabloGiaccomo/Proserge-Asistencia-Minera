# EPP: devolucion por trabajador con una firma

Implementado localmente el 2026-10-09, conforme al flujo aprobado por el usuario.

## Uso y comportamiento

Entregas y cambios -> Registrar devolucion -> buscar trabajador -> Ver saldos
por devolver -> marcar articulos y cantidades -> Revisar devolucion y firmar.

Antes del lienzo se ven todos los articulos seleccionados, sus caracteristicas,
cantidades, saldos anteriores y restantes. Una sola firma registra el grupo.
La devolucion parcial mantiene la entrega abierta. El saldo cero la cierra.
Los articulos no seleccionados y la evidencia original no se modifican. El
Kardex conserva una operacion de devolucion con su propia firma y PDF.

Sin almacenes obligatorios, movimientos de stock, migraciones nuevas ni
dependencias. No se modificaron datos operativos para probar el flujo.

## Archivos

- EppCatalogReturnService y EppCatalogReturnController: saldos autorizados,
  preparacion idempotente, snapshots, bloqueo y validacion de cada origen.
- EppDeliverySignatureService: reutiliza la aceptacion privada y confirma
  descuentos en su misma transaccion; Cerrar firma solo el saldo restante.
- routes/web.php: GET/POST /epps/devoluciones bajo web.auth y registrar.
  Confirmar usa confirmar_devolucion y alcance del trabajador, sin exigir registrar.
- workspace, catalog-return, delivery-signature, evidence-lines y PDF:
  boton en el panel, seleccion, revision explicita y saldos en la constancia.
- epp-operation.js: reutiliza seleccion y saldos; admite el buscador habitual,
  invalida la lista al cambiar trabajador y no exige condicion/destino de almacen.
- EppExistingWorkflowTest y harnesses Browser: regresiones y UX responsive.
- Guia EPP_FIRMA_OPERATIVA y mapas: sincronizados con el comportamiento verificado.

## Verificacion

- Ciclo rojo/verde observado antes de implementar la devolucion; tambien se
  reprodujo y corrigio el 403 de un confirmador con permiso separado de preparar.
- EppExistingWorkflowTest: 23 pruebas, 212 aserciones, cero fallos/errores.
- Suite Epp*, LogisticaDashboardServiceTest y LogisticaEppIntegrationTest:
  303 pruebas, 2076 aserciones, cero fallos/errores. Excluye unicamente
  test_logistica_herramientas_muestra_listado_y_oculta_sidebar_independiente,
  caso ajeno conocido. MySQL proserge_app_test, ejecucion serial.
- JavaScript: 9 pruebas, cero fallos.
- Browser epp-catalog-return: seleccionar, parcial, desmarcar, payload exacto,
  cambio de trabajador, sin almacen; 320, 375, 756 y 1440 px.
- Browser epp-signed-delivery: incluye revision de devolucion por catalogo con
  dos articulos, saldos y una firma; dibujo, limpiar, resize y sin errores de
  consola, 320, 375, 390, 430 y 1440 px.
- Browser epp-catalog-batch: regresion de multiples entregas con firma unica.
- Inspeccion visual de captura movil; sin solapamientos ni desbordamientos.
- Vite build, Blade view:cache, Pint de PHP afectado, php -l y git diff --check.
- Revision independiente: corregidos permiso de confirmacion y etiqueta del
  PDF. Segunda revision sin hallazgos pendientes.

No se repitio la suite global del repositorio: conserva fallos ajenos conocidos
documentados en el informe anterior de firma unica; no se declara global verde.
Los navegadores fueron sinteticos con todas las solicitudes interceptadas;
no se accedio ni se evadio el acceso denegado a la sesion autenticada del usuario.

Pendiente manual: probar en la sesion operativa y dispositivo tactil. Recargar
la aplicacion con Ctrl+F5 para obtener el JS compilado. El cambio es local,
sin commit, push ni despliegue a produccion. Para desplegar se requieren los
fuentes y public/build generado, ademas de los controles habituales del proyecto.
