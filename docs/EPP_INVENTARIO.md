# Catalogo e inventario EPP

El catalogo EPP usa `politica_devolucion`: `RETORNABLE` exige devolucion;
`CONSUMIBLE` no genera obligacion de devolucion. Los registros existentes y los
nuevos sin politica explicita conservan `RETORNABLE`. Al guardar desde la web
se exige revisar la politica y los controles de inventario, entrega repetida,
serie y cobertura. La categoria del articulo no determina su politica.

## Acceso operativo

Logistica ofrece **Inventario por almacen** en `/epps/inventario`. Listar requiere
`epps.gestionar_inventario` o `epps.ajustar_inventario`; crear almacen, guardar
catalogo y abrir stock requieren `gestionar_inventario`; ajustar requiere
`ajustar_inventario`. Las rutas usan `web.auth` y permisos directos, y los
servicios aplican Gate con `EppOperacion::class` y alcance de almacenes/minas.
Los almacenes generales quedan reservados a los roles privilegiados. Las reglas
de alcance y compatibilidad de roles viven en `EPP_AUTORIZACION.md`.

## Apertura

1. Crear el almacen mediante vista previa y confirmacion, o elegir uno existente.
2. Seleccionar un EPP activo con control de inventario y el numero de variantes.
3. Registrar talla/color/otros atributos configurados y serie si corresponde.
4. Introducir unidades fisicamente contadas (tambien se admite cero) y motivo.
5. Revisar el almacen, las variantes y cantidades, y confirmar explicitamente.

La UI permite hasta 20 variantes de un EPP por conteo; el servicio admite lotes
de hasta 100 variantes, incluso de distintos EPP. No se copia `epp_registro.stock`.
Ese campo se conserva solo por compatibilidad y nunca constituye disponibilidad.
`available()` retorna `null` si no hay existencia conciliada y cero para un
conteo confirmado sin unidades. Una variante conciliada no puede abrirse otra vez.

Los atributos se recortan, se convierten a mayusculas y se ordenan por nombre;
`variante_clave` es SHA-256 de su JSON canonico. No se aceptan atributos ajenos
al catalogo ni valores fuera de sus opciones. La existencia conserva los atributos.

## Ajustes e historial

Un ajuste registra una diferencia entera positiva o negativa y motivo. Se exige
apertura previa y nunca se permite saldo negativo.
El limite UINT32 de disponible incluye tambien las unidades reservadas que
podrian regresar a ese mismo saldo al liberar una reserva; un ajuste no puede
dejar `cantidad_disponible + cantidad_reservada` por encima de 4294967295.
La cuarentena tiene una columna independiente y no se suma en esta liberacion;
cualquier traslado posterior debe validar el limite de su saldo de destino.

La vista previa muestra
saldo anterior/posterior; si el saldo cambia antes de confirmar, se exige revisar
otra vez. Los ajustes de existencias de articulos posteriormente desactivados
conservan su variante historica y no reescriben su catalogo.

Cada cambio de cantidad bloquea almacen y existencia dentro de una transaccion,
agrega un movimiento Eloquent al libro append-only con saldo anterior/posterior,
actor, origen, motivo y atributos snapshot, y actualiza la existencia. El bloqueo
del almacen tambien serializa las aperturas cuando aun no existe la fila de
existencia. Un error revierte el lote completo; nunca deja aperturas parciales.

Las vistas previas conservan token aleatorio, actor, accion, payload y vencimiento
de 15 minutos en `epp_inventario_confirmaciones`. La sesion conserva solo datos
de presentacion. Preview crea metadatos de coordinacion, sin cambiar stock ni
agregar movimientos. Confirmacion bloquea la fila del token en MySQL y aplica
el cambio de inventario/almacen y su resultado dentro de la misma transaccion.
Los reintentos devuelven el resultado original y no generan otro movimiento,
incluso con una sesion restaurada o si el saldo vuelve al valor previo. Siempre
se revalidan permisos y alcance del actor, tambien al consultar el resultado
consumido. Los tokens desconocidos, vencidos sin confirmar, de otra accion o de
otro actor se rechazan. El payload del navegador o de sesion no sustituye el
payload persistido. Las claves consumidas se conservan al revertir la aplicacion.

## Preparacion de operaciones y reservas

`EppOperationService` prepara lotes de un trabajador y un almacen. Crear el
borrador exige permiso directo de preparar entrega/devolucion y alcance actual
del trabajador y almacen. `submission_uuid` identifica una sola operacion; su
reintento conserva el mismo resultado y rechaza otro actor, trabajador, almacen
o tipo. Es independiente de los tokens de apertura/ajuste del inventario.

Solo `BORRADOR` permite sincronizar lineas; el cliente envia
`cantidad_solicitada`, nunca cantidades procesadas definitivas. `epp_id` es el
articulo solicitado; `epp_real_id` opcional propone sustitucion con
`aceptar_sustitucion` y motivo. Se conservan los nombres, politica, precio y
atributos reales como snapshots. Las referencias a existencias, entregas y
pendientes previos deben corresponder al trabajador, almacen y variante.

Al preparar, el servidor bloquea operacion, almacen y existencias, revalida el
catalogo y calcula disponibilidad. Solo reserva una cantidad menor a la
solicitada cuando `aceptar_parcial` fue confirmado. Sin unidades procesables
queda `PENDIENTE_STOCK`; si todas las lineas se rechazaron queda `RECHAZADO`.
Los retornables ya asignados exigen motivo para una nueva entrega. Las variantes
duplicadas requieren `permite_entrega_repetida`; los consumibles pueden usar esa
politica. `control_inventario=false` permite preparar cantidades sin existencia,
reserva ni movimiento de stock.

Las unidades procesables quedan `LISTO_PARA_FIRMA` durante diez minutos. El
resumen congelado distingue procesadas, pendientes y rechazadas; nunca incluye
lineas rechazadas entre las cantidades por firmar. El hash del resumen se
conserva tras releerlo desde MySQL. El soporte
compartido `EppCanonicalPayload` ordena recursivamente claves asociativas y
conserva el orden de listas antes de serializar con Unicode y barras sin escape.
Preparar de nuevo una operacion vigente no duplica reservas. El rechazo en etapa de firma registra
`FIRMA_RECHAZADA`, libera las reservas y no crea entregas. La preparacion con
todas las lineas rechazadas registra `LINEAS_RECHAZADAS`.

La limpieza `epps:expire-reservations` se ejecuta cada minuto sin solapamientos;
tambien se ejecuta al consultar disponibilidad, preparar o revisar una
operacion. Bloquea nuevamente la operacion y libera cada reserva activa una
sola vez, agregando `LIBERACION` al libro y dejando la operacion `EXPIRADO`.
`expireReservations()` devuelve el numero de reservas liberadas, no de lotes.
Si un saldo historico impide liberar por desborde o inconsistencia de reserva,
el lote y su libro quedan intactos. Se registra `RESERVA_LIBERACION_BLOQUEADA`
con identificadores y cantidades para revision y la limpieza continua con los
otros lotes. La liberacion manual rechaza ese saldo con un error de dominio
antes de escribir un movimiento; requiere conciliacion mediante ajuste con motivo.
La confirmacion firmada y los saldos definitivos usan servicios separados.

## Confirmacion y evidencia privada

`EppOperationConfirmationService::confirm()` confirma entregas preparadas.
`EppAcceptanceService::capture()` usa la misma frontera transaccional cuando
se invoca directamente. La firma cubre el resumen congelado del servidor; el
navegador envia `submission_uuid`, `personal_id`, `review_hash`,
`review_version`, `firma_data_url` e `ink` (cantidad de puntos y limites del
trazo). No se aceptan cantidades definitivas del navegador en la confirmacion.
`SignEppOperationRequest` y `EppSignaturePayload` comparten las reglas de entrada.

El contrato canonico version 1 usa `details`, `requested_quantity`,
`processed_quantity`, `pending_quantity`, `pending`, `rejected`, `worker`,
`warehouse`, `revision` y `declaration_version`. Los atributos y snapshots de
catalogo conservan sus claves operativas originales. `EppCanonicalPayload`
es el unico normalizador/serializador: el resumen preparado y
`epp_aceptaciones.contenido_json` representan exactamente los mismos bytes y
hash. Los resumenes sin version con claves anteriores en espanol requieren
volver a preparar/revisar; eso incrementa la revision y reemplaza su hash sin
duplicar ni extender las reservas vigentes.

Se exige PNG real de hasta 1 MiB y de 300 a 2400 pixeles por lado. Se decodifica
y normaliza la imagen; los metadatos requieren al menos diez puntos y un trazo
de 20 por 10 pixeles dentro del lienzo. Tambien se comprueba tinta real en los
pixeles. La imagen se guarda exclusivamente en disco `local`, en
`epp/firmas/{operacion_id}/{uuid_aleatorio}.png`, con visibilidad privada y antes
de abrir la transaccion de confirmacion. No se persiste el Data URL.

La transaccion bloquea operacion, almacen, detalles, catalogo, reservas y
existencias; revalida permisos/alcance, identidad, revision/hash, vencimientos
y la correspondencia exacta de las reservas. Una salida consume unidades
reservadas sin descontar de nuevo el disponible. El libro se agrega mediante
Eloquent; nunca se reescribe. Se crean entregas solo por cantidades procesadas:
retornables con saldo positivo y consumibles en `CONSUMIDO` con saldo de
devolucion cero. Se guardan aceptacion, evento y estado `FIRMADO` o
`FIRMADO_PARCIAL` juntos. Las devoluciones usan esa misma frontera de
confirmacion y aceptacion privada; reducen el saldo de las entregas de origen
y registran su destino fisico sin crear nuevas entregas ni consumir reservas
de salida.

`submission_uuid` identifica la operacion y su resultado original, tambien
tras vencer la reserva si ya fue firmada. Los reintentos revalidan autorizacion
y devuelven la misma aceptacion; no escriben otra firma ni repiten salidas o
entregas. Dos confirmaciones concurrentes crean archivos distintos: al ganar
una, la otra elimina exclusivamente su propio archivo sin referencia. Ante
rollback se limpia solo el archivo creado por esa ejecucion y sin referencia
en aceptaciones. Si no puede comprobarse el resultado en MySQL se conserva la
evidencia para una auditoria de huerfanos; nunca se borra una evidencia confirmada.

La aceptacion conserva identidad snapshot del firmante, operador, fecha del
servidor en zona aplicativa y UTC, zona, version de declaracion, codigo aleatorio
de verificacion, resumen de navegador y hashes HMAC-SHA256 de sesion/IP. La
columna UTC se interpreta siempre como UTC al leerla. `verify()` compara el
archivo y los bytes canonicos, devuelve `EVIDENCIA_INVALIDA` con causas y marca
`FIRMA_INVALIDA` o `ARCHIVO_FALTANTE` cuando corresponde. Esta alerta no deshace
ni repite una entrega ya confirmada. Los cambios posteriores en Personal o
catalogo no reemplazan la identidad ni los productos del snapshot firmado.

`confirm()` y `confirmWithWitness()`, incluidos sus reintentos, devuelven una
operacion con `estado_integridad` (`FIRMA_VALIDA` o `EVIDENCIA_INVALIDA`) y
`verificacion_evidencia` (`valid`, `reasons`) de la comprobacion realizada.
Son datos transitorios serializables de la respuesta, no columnas editables.
El llamador debe presentar la alerta de integridad aunque el estado historico
sea `FIRMADO`/`FIRMADO_PARCIAL`. Una perdida o alteracion detectada despues del
commit mantiene la aceptacion y los efectos unicos de inventario/entrega.

## Entrega multiple desde celular

Logistica ofrece **Entrega multiple con firma** en `/epps/operaciones/nueva`.
Preparar requiere `preparar_entrega`; revisar y firmar requieren
`confirmar_entrega`; consultar el resultado requiere `ver_operaciones`.
Cada ruta usa sesion propia, capacidad directa y scope actual del trabajador
y almacen. La busqueda de trabajadores y las opciones de testigo respetan
el scope de mina; no se reutiliza la busqueda legada sin scope.

El operador selecciona trabajador/almacen, agrega tarjetas de articulos con
cantidades y variantes, e indica entrega parcial, sustitucion o motivo de
nueva entrega cuando corresponde. Guardar calcula y reserva con los servicios
del dominio. El resumen del trabajador muestra recibidas ahora, pendientes,
sustituciones y rechazadas; no permite editar cantidades ni catalogo.
La firma confirma solamente el resumen congelado. La confirmacion acepta
identidad, revision/hash, declaracion y firma; ignora cantidades del navegador.

Desacuerdo y negativa usan `reject()` con motivo: no crean entrega, liberan
reservas y no admiten convertir el lote en excepcion. Rechazar requiere el
permiso de preparacion del operador segun el contrato del servicio. Solo
`aprobar_excepcion_firma` permite abrir la revision por testigo; conserva
identidad de testigo, motivo, operador y aprobador distinto, e indica que el
trabajador no firmo. La firma se valida igual que en la entrega normal.

`signature-pad.js` comparte Pointer Events, captura de puntero, DPR, metadata
de tinta y preservacion vectorial al redimensionar. `clear()`, `hasInk()` y
`toPayload()` exponen el contrato; `signature:changed` actualiza el campo
oculto. Un cambio de lineas invalida firma/hash/version; cambiar testigo o
motivo borra su firma. Las fichas de Personal conservan `firma_base64`,
validacion de obligatoriedad y recuperacion de firma en borradores.

`tests/Browser/epp-signed-delivery.cjs` revisa formulario, resumen, testigo y
resultado a 320/375/390/430/1440 px con fixtures sinteticos sobre la base de
pruebas. No requiere servidor web; necesita Playwright y Chrome. Puede usar
`NODE_PATH` hacia el runtime disponible sin instalar dependencias del proyecto.
La pantalla de resultado conserva alertas de integridad ya detectadas y pide
revision, sin sugerir repetir una entrega firmada.

## Devolucion multiple y saldos

**Devolucion multiple con firma** abre `/epps/operaciones/devoluciones/nueva`.
Preparar exige `preparar_devolucion`; revisar/firmar, `confirmar_devolucion`.
Se elige trabajador y almacen receptor, y se seleccionan exclusivamente sus
saldos `RETORNABLE`, `ENTREGADO` y positivos. Consumibles y saldos cerrados no
aparecen, tampoco en Cesados por entregar. Esa consulta suma la cantidad
pendiente real por articulo; no modifica las otras metricas del dashboard.

Cada tarjeta permite cantidad, condicion y destino. `BUENO` vuelve a
`DISPONIBLE`; `DESGASTADO`, `DANADO` e `INCOMPLETO` requieren `CUARENTENA` o
`BAJA`. `OTRO` requiere descripcion y cuarentena/baja. Una baja registra la
recepcion y descarte sin sumar disponible ni cuarentena. La cantidad devuelta
parcial deja saldo y estado `ENTREGADO`; completar el saldo registra `DEVUELTO`,
fecha y actor de cierre. La firma `RETURN_V1` declara "Confirmo haber devuelto"
y cubre exclusivamente las cantidades procesadas, condiciones y destinos.
El resumen firmado muestra saldo anterior/restante por entrega de origen.

Antes de preparar y confirmar se acumulan todas las lineas de cada
`entrega_origen_id`. La confirmacion bloquea operacion, almacen, detalles,
catalogo ordenado, entregas de origen ordenadas y existencias. Revalida la
cantidad total contra el saldo bajo bloqueo: lineas duplicadas o lotes
concurrentes nunca consumen el mismo saldo dos veces. Si cambia el saldo
mostrado, exige una nueva revision incluso si aun alcanza la cantidad.
Un reintento conserva aceptacion, cantidades y movimientos originales.

El origen debe corresponder exactamente al trabajador, EPP, variante y, para
entregas nuevas, almacen. Se conservan politica, control de inventario y
catalogo snapshot de la entrega original, aunque el catalogo actual cambie.
Las entregas legacy sin almacen pueden devolverse al receptor seleccionado
con scope vigente y variante conciliada. Conservan `LEGACY_SIN_FIRMA`: se
firma la nueva devolucion, nunca se inventa una firma de entrega historica.
No se genera una apertura de stock automatica; un error de saldo/inventario
revierte aceptacion, devoluciones y libro del lote completo.

`EppBalanceService::markLoss()` exige `corregir`, cantidad dentro del saldo,
motivo y un `submission_uuid` valido suministrado por el caller. El caller debe
conservar esa clave ante respuesta perdida o retry: la misma clave, entrega,
cantidad, motivo y actor recuperan la operacion original, incluso con saldo
ya cerrado, sin otro descuento ni evento. Una clave distinta permite una nueva
perdida hasta el saldo vigente; reutilizarla con otro contenido se rechaza.
Cada replay vuelve a comprobar actor activo, permiso y scope de trabajador y
almacen. No existe ruta HTTP de perdida; cualquier futura ruta debe exigir
ese UUID estable, nunca generarlo en cada intento del servidor.
Registra una operacion `CORRECCION` con evento `PERDIDA_REGISTRADA`,
actor y saldos anterior/restante; una perdida completa cierra en
`PERDIDA_OLVIDO`. Nunca suma stock ni se presenta como devolucion fisica o
aceptacion firmada por el trabajador. Para una entrega legacy sin almacen,
la perdida requiere regularizar primero su contexto de almacen; no se elige
un almacen arbitrario. No aplica descuentos economicos.

## Validacion y despliegue

Aplicar primero las migraciones aditivas EPP del 2026-10-07 con respaldo de la base
y documentos privados. Revisar permisos del rol antes de habilitar el inventario.
La apertura requiere conteo fisico y confirmacion; no se ejecuta automaticamente
durante migraciones ni cambia entregas historicas o datos de Personal.

Pruebas: `EppCatalogTest`, `EppInventoryServiceTest`, `EppAuthorizationTest`,
`EppInventoryConfirmationConcurrencyTest`, `EppOperationServiceTest`. Las pruebas
concurrentes usan dos procesos MySQL,
fixtures comprometidos de coordinacion/almacen y limpieza dirigida; nunca muta
ni limpia el libro de inventario.
Las pruebas de operaciones conservan sus fixtures comprometidos en la base
aislada junto con el libro append-only; liberan las reservas al terminar.
`tests/Browser/epp-inventory.cjs` usa Playwright/Chrome con fixtures en memoria y
solo puede consultar `proserge_app_test`; revisa 1440/320/375/390/430 px.
