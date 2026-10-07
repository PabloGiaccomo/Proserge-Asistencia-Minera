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
