# EPP: entregas y cambios con firma

## Flujo habitual corregido (2026-10-09)

El usuario confirmo conservar la operacion existente. En **Logistica > Entregas
y cambios de EPP > Registrar entrega**, usar el formulario habitual: trabajador,
item, talla/color/atributos de **Identificacion de items**, cantidad y fecha.
**Continuar a la firma** muestra el resumen; **Firmar y registrar** guarda la
entrega y su evidencia. No exige almacen, conciliacion ni apertura de inventario.
**Agregar otro EPP** incorpora el articulo a la lista pendiente y mantiene el
mismo trabajador. Permite acumular y quitar articulos antes de continuar. No
registra entregas ni solicita firmas al agregar. **Continuar a la firma**
incluye todos los articulos agregados y el articulo actual, si esta seleccionado.
En la revision se muestran nombre, cantidad, talla, color, atributos y
observacion de cada articulo, antes del area de firma. Una sola aceptacion y
constancia cubre todo el grupo; cada entrega conserva su detalle vinculado a
esa operacion. Si un articulo cambia o falla la confirmacion, no se registra
ninguna entrega del grupo. Se admiten hasta 50 articulos por firma.

**Registrar devolucion**, junto a Registrar entrega, abre los saldos del
trabajador en Entregas y cambios. Buscar por nombre o DNI, pulsar **Ver saldos
por devolver** y marcar los articulos que devuelve. Solo aparecen entregas
retornables activas con saldo positivo del trabajador y alcance autorizado;
no se incluyen consumibles, articulos ya devueltos ni operaciones de almacen.
Se puede devolver todo el saldo o una cantidad parcial. Los articulos no marcados
mantienen su saldo. Al cambiar la busqueda se deshabilita la lista anterior
hasta volver a consultar los saldos del trabajador seleccionado.

**Revisar devolucion y firmar** muestra nombre, talla, color, atributos,
cantidad devuelta, saldo anterior y saldo restante de cada articulo antes de
una sola firma. Hasta confirmar no se descuentan saldos. La confirmacion
revalida todos los origenes bajo bloqueo: si uno cambio, no guarda la firma
ni descuenta ninguna linea. Una devolucion parcial mantiene la entrega abierta;
al llegar a cero queda Devuelto. La cantidad y evidencia de la entrega original
no se sobreescriben. Se genera una operacion de devolucion y constancia propia
en Kardex, con fecha operativa y fecha de firma, sin exigir almacen ni afectar
inventario. Reintentar la misma confirmacion no descuenta nuevamente.

Rutas de seleccion: GET/POST `/epps/devoluciones`, con permiso `registrar`.
`EppCatalogReturnService` valida y aplica saldos; reutiliza la confirmacion de
`EppDeliverySignatureService`, que exige `confirmar_devolucion` y alcance del
trabajador. Quien confirma no necesita el permiso de preparacion. Se admiten
de 1 a 50 entregas diferentes por devolucion y una cantidad positiva hasta el
saldo disponible. La fecha no puede ser anterior a la entrega de origen.

**Cerrar** en una fila activa conserva las opciones de movimiento existentes.
Para un cambio, abre el formulario de reemplazo; la entrega anterior permanece
abierta hasta confirmar la firma de la nueva. Una devolucion/cierre tambien
requiere revisar y firmar antes de cerrar. La firma de una perdida o uso
incorrecto confirma ese movimiento, no declara una devolucion fisica.
Si hubo devoluciones parciales, Cerrar firma solamente el saldo restante.

Se conservan los registros previos sin atribuirles firmas nuevas. Las entregas
firmadas y el historial anterior no se editan ni borran fisicamente. El cierre
operativo no cambia el contenido de una aceptacion original. Cambios en el item,
la identidad o la entrega origen invalidan una revision pendiente. Reintentos
con el mismo UUID no duplican efectos; un envio con otro contenido se rechaza.

`EppDeliverySignatureService` reutiliza las reglas de `EppService` y la aceptacion
privada existente. Estas operaciones tienen `almacen_id = null` y origen canonico
`ENTREGAS_CAMBIOS`; no crean movimientos de inventario ni reservas. Alcance por
trabajador y permisos vigentes se revalidan en servidor. Preparar exige
`registrar`; confirmar exige `confirmar_entrega` o `confirmar_devolucion` segun el
movimiento. No se habilita testigo ni compensacion de inventario en este flujo.

El formulario envia `items[]` con las caracteristicas de cada EPP y mantiene
trabajador y fecha comunes. El servidor congela todos los detalles y la
configuracion del catalogo en el resumen firmado. La confirmacion revalida cada item
antes de persistir entregas y aceptacion en una unica transaccion. Se conserva
compatibilidad con revisiones individuales ya preparadas y con cambios/cierres
individuales. El nombre visible del trabajador es informativo; la identidad
firmada se obtiene del registro del servidor, no del texto del buscador.

Rutas: GET/POST `/epps/entregas-firma/{operation}`. El Kardex incluye estas
aceptaciones y enlaces **Ver firma** / **Constancia PDF**, tambien disponibles
en la fila de la entrega. Las rutas de editar/eliminar siguen protegidas con
409; las rutas individuales de registrar/cerrar ahora preparan la firma.

Migracion aditiva: `2026_10_09_000100_allow_catalog_epp_operations_without_warehouse.php`.
Solo admite almacen nulo; no elimina tablas, entregas ni aceptaciones. Su rollback
no vuelve obligatorio el almacen, para no inventarlo ni perder evidencia.

## Inventario por lotes: capacidad separada

Las rutas anteriores de operaciones e inventario se conservan para registros que
ya las utilicen, pero sus botones no se insertan en la cabecera de Logistica ni
sustituyen Registrar entrega. Este flujo separado elige trabajador y almacen
dentro del alcance autorizado; agrega articulos, variantes y cantidades. Reserva
stock conciliado y nunca usa `epp_registro.stock` como apertura. Una existencia
sin conciliacion muestra `STOCK_NO_CONCILIADO`.

Revisar articulos reales, cantidades procesadas y pendientes antes de firmar. Una entrega parcial exige aceptacion explicita; solo lo procesado afecta inventario y responsabilidad. Sin stock no se registra entrega. Un cambio de revision exige nueva firma. Un corte de red se recupera con el mismo `submission_uuid`; no crear otro lote hasta consultar el resultado.

El operador necesita `preparar_entrega` y quien confirma `confirmar_entrega`. La negativa o desacuerdo rechaza el lote y libera reservas sin entregar. La excepcion por testigo requiere firma real, motivo, testigo identificado y aprobador distinto del operador/testigo con `aprobar_excepcion_firma`. Una negativa no puede convertirse en excepcion por testigo.

## Devolver y compensar

Usar **Devolucion multiple con firma**, seleccionar saldos retornables positivos, cantidades y condicion. Bueno retorna a disponible; danado/desgastado/incompleto va a cuarentena. Los consumibles tienen historial sin saldo retornable ni pendiente al cese. En una entrega firmada con saldo, **Registrar perdida** abre GET `/epps/entregas/{delivery}/perdida`; POST en la misma ruta requiere permiso `corregir`, motivo y UUID idempotente. `EppBalanceService::markLoss` reduce responsabilidad sin simular una devolucion ni aumentar inventario. Para legado sin almacen origen, regularizar primero la trazabilidad; no inventar un almacen para registrar una perdida.

En operaciones con almacen, firmados y legacy no admiten edicion ni borrado
fisico desde web. Las operaciones de inventario se corrigen o anulan desde su
resultado con permiso directo `corregir`/`anular` y motivo; `EppCorrectionService`
genera operaciones enlazadas y movimientos compensatorios, conservando la firma
original. Estas compensaciones no se aplican a entregas del formulario habitual.
Una compensacion administrativa no recibe automaticamente la firma del trabajador.

El legado se marca sin inventar firmas, operaciones ni apertura. El formulario
habitual permite cerrarlo o cambiarlo desde su fila con revision y firma del
movimiento nuevo. Las rutas separadas de compensacion legacy permanecen para
administracion autorizada; no aparecen como sustituto de Cerrar en la tabla.

## Kardex y evidencia

`EppKardexService` agrupa operaciones y mantiene legado separado. Estados: `FIRMADO_VALIDO`, `EVIDENCIA_INVALIDA`, `LEGACY_SIN_FIRMA`. Cada consulta recalcula SHA-256 del archivo privado y JSON canonico con `EppAcceptanceService::verify`. Una compensacion sin aceptacion propia se presenta sin firma valida y enlaza al original.

- Verificacion autenticada: GET `/epps/evidencia/{code}`, permiso `ver_evidencia`.
- Constancia PDF: GET `/epps/operaciones/{operation}/constancia`, permiso `descargar_constancia`.
- Excel SGC-FOR-59: GET `/epps/kardex/verificable/descargar?personal_id=UUID&fecha_desde=AAAA-MM-DD&fecha_hasta=AAAA-MM-DD`, permisos `ver_evidencia` y `descargar_constancia`. La ruta anterior `/epps/kardex/descargar` usa el mismo servicio y controles.

En Kardex, elegir trabajador y fechas Desde/Hasta. El valor inicial es el mes
calendario anterior completo; ambas fechas se incluyen. `EppKardexExcelService`
utiliza la plantilla original `resources/epp-templates/SGC-FOR-59.xlsx`, conserva
Anterior/Posterior y ordena los movimientos visibles del rango por fecha real.
Pantalla y Excel agrupan por fecha, tipo de movimiento y lote firmado. Cada
columna admite una unidad por fila: tres cascos y dos chalecos del mismo lote
generan tres filas, las primeras dos con ambos EPP y la tercera solo con casco.
Modelos que corresponden a la misma columna nunca se sobrescriben. Fechas,
movimientos y lotes diferentes permanecen separados; registros antiguos sin
lote identificable no se mezclan. La agrupacion se realiza antes de cualquier
paginacion. La tabla carga 20 filas por defecto y permite 20, 50 o 100 como
maximo por pagina. El pie muestra el rango y total, con Anterior/Siguiente;
cambiar trabajador restablece pagina 1 y limite 20. No muestra el bloque
tecnico de operaciones/UUID/hashes. La evidencia historica no se elimina:
sigue disponible en Entregas y cambios, Ver firma y Constancia.
GET `/epps/kardex` acepta `page` entero positivo y `per_page` 20/50/100;
devuelve `data.kardex.pagination` y solo las filas solicitadas. Paginas fuera
de rango se ajustan a la ultima; historial vacio muestra 0–0 de 0.
El servidor recorre metadatos para agrupar y contar, con lectura de origen
por bloques de 100 y rangos de cantidades sin expandir todas las unidades.
No consulta ni audita las firmas privadas para cargar cada pagina. El Excel
conserva todos los movimientos del periodo, sin depender de pagina/limite.
Solo usa N (nuevo), C (cambio), I (uso incorrecto), P (perdida) y D
(devolucion), sin cantidades junto al codigo ni asteriscos. Solo conserva nombres
de EPP relacionados con los movimientos del trabajador en el periodo. Agrega
filas o paginas cuando sea necesario. La fecha de apertura es historica.
La leyenda D (Devolución) mantiene una separacion respecto a P y un ancho
similar al resto de tipos, sin ocupar la columna reservada para las firmas.
No agrega bloques tecnicos debajo del formulario. Los ajustes administrativos,
cantidades originales, caracteristicas, observaciones y evidencia por lote
siguen disponibles en el historial y sus constancias, sin inventar un codigo A.

La columna Firma queda vacia en las filas de movimientos. Solo el recuadro
inferior contiene una firma: la ultima firma valida del trabajador relacionada
con los EPP de esa hoja dentro del rango. No se usa una firma de testigo ni
fuera del periodo; tampoco certifica movimientos de otros lotes. Las firmas
individuales permanecen en sus constancias originales.
La copia exportada lleva fondo blanco; el archivo privado y su hash no cambian.
Una correccion administrativa no hereda la firma original. Fechas
operativas explicitas no se desplazan; timestamps sin fecha operativa se
interpretan en `app.display_timezone` (America/Lima).

Antes de firmar una entrega se muestran los articulos y la declaracion completa
SGC-FOR-59 (compromisos a-e). Las nuevas entregas congelan el texto y su version
`DELIVERY_SGC_FOR59_V1` dentro del resumen firmado. No se reescribe evidencia
anterior. Las devoluciones conservan su declaracion de devolucion.

En Entregas y cambios, el filtro Mina selecciona entregas cuyo intervalo de
uso coincide con una asignacion RQ en esa mina; no usa la habilitacion activa
como criterio operativo. Los periodos desplegados y dias de uso corresponden
solo a la mina elegida. Sin filtro se conserva el calculo global. Los controles
de permiso y alcance del usuario permanecen vigentes.

Los buscadores de Logistica aceptan palabras en cualquier orden, coincidencias
parciales, mayusculas/minusculas, acentos y espacios adicionales; DNI sigue
disponible. Todas las palabras deben coincidir sin ampliar permisos o filtros.

El QR solo contiene la URL interna de verificacion. El codigo no sustituye sesion, permiso ni alcance por mina/almacen/trabajador. No contiene DNI ni firma. PDF contiene el resumen canonico, pendientes, declaracion, firmante, hashes abreviados y correcciones; una firma faltante o alterada genera alerta. El servidor es autoritativo; un Excel descargado no prueba integridad actual. Los accesos generan eventos con actor/canal sin registrar documentos o imagenes en logs.

## Dashboard y catalogo

La cobertura usa `es_requerido_cobertura` configurado en Identificacion, no seis nombres fijos. Revisar esta configuracion antes de habilitar el tablero. Las responsabilidades retornables usan saldo positivo; el tablero conserva cantidades pendientes de operaciones parciales y cuenta operaciones/legado por separado. Stock proviene de existencias conciliadas de almacenes activos, con reservas y cuarentena separadas.

## Despliegue y recuperacion

1. Respaldar MySQL y `storage/app/private`; validar restauracion en copia aislada.
2. Instalar dependencias desde `composer.lock` con PHP 8.2 y extensiones existentes. QR SVG usa endroid/qr-code 5.1 sin GD; la captura PNG conserva sus requisitos existentes.
3. Publicar codigo y `public/build` compilado; ejecutar `php artisan migrate --force` despues de probar el SQL sobre copia realista.
4. Ejecutar `php artisan epp:backfill-legacy-traceability --dry-run`; revisar scanned/updated/skipped/errors; ejecutar sin dry-run y repetir para comprobar idempotencia.
5. Configurar cobertura y permisos directos por rol/mina. Solo si se va a usar el flujo separado de inventario, configurar almacenes y conteo/apertura; no es requisito para Registrar entrega.
6. Ensayar entrega parcial, negativa, devolucion parcial, reintento, PDF/Excel y QR con usuario autorizado y fuera de alcance.
7. Ante incidencia, detener nuevas escrituras y conservar base/archivos/eventos. Restaurar codigo anterior solo con evaluacion de compatibilidad; no ejecutar down ni borrar evidencia. Una restauracion de datos exige backup y reconciliar movimientos posteriores.

El ensayo de despliegue debe realizarse exclusivamente en una copia aislada; las pruebas automatizadas usan `proserge_app_test`.

## Controles de cierre de revision

- Busqueda, ultimas entregas, listado, dashboard, kardex y descargas aplican el alcance vigente del usuario obtenido de MySQL. El kardex omite operaciones de almacenes ajenos sin bloquear las operaciones permitidas del mismo trabajador.
- Correccion, anulacion y compensacion legacy requieren un `submission_uuid` estable del formulario. Repetir la misma solicitud devuelve la operacion existente; reutilizarlo con otro contenido se rechaza. Seleccionar solo las lineas a compensar: cero significa no seleccionada y los limites reflejan compensaciones anteriores.
- Las devoluciones conservan la existencia de recepcion. Para evidencia anterior sin ese identificador, la compensacion resuelve el movimiento original sin reescribir el documento firmado.
- PDF, verificacion y Excel usan nombres y atributos del resumen firmado, incluidos pendientes, rechazados y condicion/destino de devolucion. Las celdas exportadas son texto literal, incluso si el catalogo empieza con un signo de formula.
- Repetir el backfill no restablece saldos legacy ya reducidos. Revisar sus contadores antes y despues de ejecutarlo.
- La cobertura incluye consumibles entregados aunque su saldo retornable sea cero. **Pendientes registrados en lotes** suma el pendiente original firmado: no es demanda neta actual ni se descuenta automaticamente por una entrega posterior. El cumplimiento posterior requiere enlace y conciliacion operativa explicita.
