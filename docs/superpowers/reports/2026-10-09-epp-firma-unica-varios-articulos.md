# EPP: varios articulos con una sola firma

Fecha: 2026-10-09. Correccion solicitada y aprobada por el usuario sobre Entregas y cambios, sin nuevas pantallas de almacen.

## Uso

Seleccionar trabajador y EPP con sus caracteristicas. Agregar otro EPP incorpora el articulo a una lista pendiente, mantiene el trabajador y permite seleccionar el siguiente. Quitar elimina una linea pendiente sin tocar historial. Continuar a la firma incorpora tambien el articulo actual si esta seleccionado.

Antes del lienzo se muestran TODOS los articulos: nombre, cantidad, talla, color, atributos y observacion. Firmar y registrar confirma el grupo completo. Cada entrega referencia su detalle dentro de la misma operacion, aceptacion, firma y constancia del Kardex.

No se registra ningun EPP al agregarlo a la lista. Si un item deja de ser valido o cambia su configuracion antes de firmar, se rechaza todo el grupo. Los reenvios de la firma no duplican las entregas. Un cambio de EPP conserva un unico reemplazo y su flujo de cierre individual.

## Causa y cambios

La correccion anterior enviaba cada articulo directamente a revision y el boton agregar-otra reabria el formulario solo despues de firmar. Se reemplazo por acumulacion previa y confirmacion atomica de varios detalles. Tambien se retiro la dependencia de filas fijas del mini-Kardex: los campos de EPP tienen su propia columna, evitando desplazamientos cuando aparecen talla/color/atributos.

- EppPageController: valida items[] (1 a 50) sin eliminar el contrato individual anterior.
- EppDeliverySignatureService: prepara cada detalle, congela sus caracteristicas y revalida todos antes de crear una aceptacion y sus entregas en una unica transaccion.
- workspace.blade.php y epps.css: lista pendiente, quitar, mantener trabajador y distribucion separada de campos/historial.
- epp-delivery-batch.js y app.js: acumulacion, serializacion, recuperacion tras errores y prevencion de doble envio.
- delivery-signature.blade.php: resumen explicito de todos los articulos antes de la firma.
- Pruebas Feature y Browser: firma compartida, evidencia/PDF, idempotencia, cambio del segundo item, preparacion sin residuos parciales, agregar/quitar/reindexar, recuperacion del trabajador y visualizacion responsive.

Sin nuevas migraciones ni dependencias. Los assets se generaron con npm run build; no se editaron hashes manualmente. Se preservaron los cambios ajenos del workspace. Sin commit, push ni cambios contra produccion.

## Verificacion

- 17 regresiones del flujo: 138 aserciones, cero fallos/errores.
- Suite focalizada final, proceso nuevo: 297 pruebas, 2002 aserciones, cero fallos/errores (`.superpowers/epp-batch-focused-final.xml`). Incluye Epp*, LogisticaDashboardServiceTest y LogisticaEppIntegrationTest, excluyendo unicamente el caso conocido de Herramientas. La asercion del nombre del boton pasa en esta repeticion.
- JavaScript: 9 pruebas, cero fallos.
- Navegador sintetico con assets compilados: acumular dos, quitar/reindexar, enviar lista sin seleccionar otro item, conservar trabajador al recuperar errores. Sin solapamiento campos/historial en 320, 375, 756 y 1440 px.
- Harness de firma: todos los articulos visibles antes del lienzo, dibujo, limpiar y resize; 320, 375, 390, 430 y 1440 px. No se accedio a la sesion autenticada del usuario: todas las solicitudes del harness se interceptan con datos de prueba.
- Build Vite, compilacion Blade, Pint, sintaxis PHP y JS y git diff --check verificados.
- Suite global: 1311 pruebas, 8066 aserciones, 41 fallos y 1 error (`.superpowers/epp-batch-full.xml`). Contra la corrida anterior, los 41 casos conocidos siguen presentes; estan enumerados por nombre en [integracion local](2026-10-09-epp-integracion-local.md#casos-globales-pendientes). El caso adicional fue `Tests.Feature.LogisticaEppIntegrationTest::test_logistica_entregas_renderiza_la_vista_operativa_de_epp`: el proceso habia cargado la expectativa del texto anterior, Registrar y agregar otra, antes de actualizarla a Agregar otro EPP. Se repitio el conjunto focalizado en un proceso nuevo despues de esa actualizacion. No se declara la suite global verde ni se oculta el resultado de la corrida anterior.
- Revision independiente: sin Critical/Important; se corrigio el hallazgo de nombre de trabajador perdido al volver de validacion y se actualizo el texto operativo obsoleto.

Aplicadas systematic-debugging y test-driven-development: las regresiones del lote y de conservacion del nombre se observaron fallar antes de corregir. requesting-code-review aporto la revision independiente y documentation-sync actualizo el flujo operativo.

Pendiente manual: probar con el usuario operativo en su sesion y dispositivo tactil. El alcance es local; siguen vigentes los controles de respaldo/restauracion y liberacion del plan antes de produccion.
