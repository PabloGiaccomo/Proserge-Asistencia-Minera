# EPP: operacion por lotes con firma

## Preparar y confirmar

En Logistica > Entregas, usar **Nueva entrega multiple**. Elegir trabajador y almacen dentro del alcance autorizado; agregar articulos, variantes y cantidades. El sistema reserva stock conciliado; nunca usa `epp_registro.stock` como apertura. Una existencia sin conciliacion muestra `STOCK_NO_CONCILIADO`.

Revisar articulos reales, cantidades procesadas y pendientes antes de firmar. Una entrega parcial exige aceptacion explicita; solo lo procesado afecta inventario y responsabilidad. Sin stock no se registra entrega. Un cambio de revision exige nueva firma. Un corte de red se recupera con el mismo `submission_uuid`; no crear otro lote hasta consultar el resultado.

El operador necesita `preparar_entrega` y quien confirma `confirmar_entrega`. La negativa o desacuerdo rechaza el lote y libera reservas sin entregar. La excepcion por testigo requiere firma real, motivo, testigo identificado y aprobador distinto del operador/testigo con `aprobar_excepcion_firma`. Una negativa no puede convertirse en excepcion por testigo.

## Devolver y compensar

Usar **Devolucion multiple con firma**, seleccionar saldos retornables positivos, cantidades y condicion. Bueno retorna a disponible; danado/desgastado/incompleto va a cuarentena. Los consumibles tienen historial sin saldo retornable ni pendiente al cese. En una entrega firmada con saldo, **Registrar perdida** abre GET `/epps/entregas/{delivery}/perdida`; POST en la misma ruta requiere permiso `corregir`, motivo y UUID idempotente. `EppBalanceService::markLoss` reduce responsabilidad sin simular una devolucion ni aumentar inventario. Para legado sin almacen origen, regularizar primero la trazabilidad; no inventar un almacen para registrar una perdida.

Firmados y legacy no admiten edicion ni borrado fisico desde web. Las rutas individuales antiguas devuelven 409. Las operaciones firmadas se corrigen o anulan desde su resultado con permiso directo `corregir`/`anular` y motivo; `EppCorrectionService` genera operaciones enlazadas y movimientos compensatorios, conservando la firma original. Una compensacion administrativa no recibe automaticamente la firma del trabajador: consultar su operacion origen.

El legado se marca sin inventar firmas, operaciones ni apertura. Sus saldos retornables pueden devolverse mediante lote. Desde Entregas, **Corregir / anular saldo legacy** registra motivo, cantidad y almacen responsable con permiso directo. Reduce el saldo con bloqueo de fila y enlaza la entrega original; no altera cantidades historicas ni inventa entradas de stock. El almacen seleccionado identifica al area responsable de la revision. Para recibir articulos fisicos debe usarse Devolucion multiple.

## Kardex y evidencia

`EppKardexService` agrupa operaciones y mantiene legado separado. Estados: `FIRMADO_VALIDO`, `EVIDENCIA_INVALIDA`, `LEGACY_SIN_FIRMA`. Cada consulta recalcula SHA-256 del archivo privado y JSON canonico con `EppAcceptanceService::verify`. Una compensacion sin aceptacion propia se presenta sin firma valida y enlaza al original.

- Verificacion autenticada: GET `/epps/evidencia/{code}`, permiso `ver_evidencia`.
- Constancia PDF: GET `/epps/operaciones/{operation}/constancia`, permiso `descargar_constancia`.
- Excel verificable: GET `/epps/kardex/verificable/descargar?personal_id=UUID`, permisos `ver_evidencia` y `descargar_constancia`.

El QR solo contiene la URL interna de verificacion. El codigo no sustituye sesion, permiso ni alcance por mina/almacen/trabajador. No contiene DNI ni firma. PDF contiene el resumen canonico, pendientes, declaracion, firmante, hashes abreviados y correcciones; una firma faltante o alterada genera alerta. El servidor es autoritativo; un Excel descargado no prueba integridad actual. Los accesos generan eventos con actor/canal sin registrar documentos o imagenes en logs.

## Dashboard y catalogo

La cobertura usa `es_requerido_cobertura` configurado en Identificacion, no seis nombres fijos. Revisar esta configuracion antes de habilitar el tablero. Las responsabilidades retornables usan saldo positivo; el tablero conserva cantidades pendientes de operaciones parciales y cuenta operaciones/legado por separado. Stock proviene de existencias conciliadas de almacenes activos, con reservas y cuarentena separadas.

## Despliegue y recuperacion

1. Respaldar MySQL y `storage/app/private`; validar restauracion en copia aislada.
2. Instalar dependencias desde `composer.lock` con PHP 8.2 y extensiones existentes. QR SVG usa endroid/qr-code 5.1 sin GD; la captura PNG conserva sus requisitos existentes.
3. Publicar codigo y `public/build` compilado; ejecutar `php artisan migrate --force` despues de probar el SQL sobre copia realista.
4. Ejecutar `php artisan epp:backfill-legacy-traceability --dry-run`; revisar scanned/updated/skipped/errors; ejecutar sin dry-run y repetir para comprobar idempotencia.
5. Configurar almacenes, realizar conteo/apertura con preview y confirmar. Configurar cobertura y permisos directos por rol/mina.
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
