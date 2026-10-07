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
apertura previa y nunca se permite saldo negativo. La vista previa muestra
saldo anterior/posterior; si el saldo cambia antes de confirmar, se exige revisar
otra vez. Los ajustes de existencias de articulos posteriormente desactivados
conservan su variante historica y no reescriben su catalogo.

Cada cambio de cantidad bloquea almacen y existencia dentro de una transaccion,
agrega un movimiento Eloquent al libro append-only con saldo anterior/posterior,
actor, origen, motivo y atributos snapshot, y actualiza la existencia. El bloqueo
del almacen tambien serializa las aperturas cuando aun no existe la fila de
existencia. Un error revierte el lote completo; nunca deja aperturas parciales.

Las vistas previas quedan en la sesion del servidor con token aleatorio, actor,
tipo de accion y vencimiento de 15 minutos. La confirmacion toma esos datos del
servidor, revalida permisos/alcance y consume la revision; el payload enviado por
el navegador no reemplaza el conteo revisado. No hay cambios de stock en preview.

## Validacion y despliegue

Aplicar primero las migraciones aditivas EPP del 2026-10-07 con respaldo de la base
y documentos privados. Revisar permisos del rol antes de habilitar el inventario.
La apertura requiere conteo fisico y confirmacion; no se ejecuta automaticamente
durante migraciones ni cambia entregas historicas o datos de Personal.

Pruebas: `EppCatalogTest`, `EppInventoryServiceTest`, `EppAuthorizationTest`.
`tests/Browser/epp-inventory.cjs` usa Playwright/Chrome con fixtures en memoria y
solo puede consultar `proserge_app_test`; revisa 1440/320/375/390/430 px.
