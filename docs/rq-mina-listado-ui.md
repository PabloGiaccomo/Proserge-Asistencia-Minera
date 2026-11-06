# RQ Mina - listado principal

## Problema anterior

El listado principal de RQ Mina usaba una tabla amplia con muchas columnas. En
pantallas medianas los encabezados y valores se comprimian, las acciones se
apilaban y la lectura rapida de estado, plan, personal y transporte quedaba
dificil para uso operativo.

## Nueva estructura

La vista `resources/views/rq-mina/index.blade.php` mantiene los mismos datos,
rutas, permisos, filtros y paginacion, pero reemplaza la tabla principal por
tarjetas responsive.

Cada tarjeta muestra:

- estado, lugar y semana;
- nombre de la parada y area;
- rango de fechas;
- resumen de plan operativo;
- resumen de personal solicitado;
- resumen de transporte;
- creador y supervisor;
- Abrir parada y menu de acciones secundarias.

## Acciones

**Abrir parada** lleva al detalle, que reune el plan operativo, los datos
generales y los indicadores. El menu agrupa las acciones disponibles segun
permiso. Los filtros y la pagina se conservan al volver al listado. Desde
Transporte, el regreso mantiene la parada y el plan seleccionados.

El registro en RR. HH. se inicia al guardar con cargos validos y permiso de
envio. La eliminacion conserva su confirmacion operativa. El listado distingue
las solicitudes de transporte del plan de los registros del formato anterior.

## Filtros y estados

La busqueda y los filtros avanzados siguen usando query string y el backend
actual. Se agregan chips para retirar filtros activos de forma individual.

La API `GET /api/v1/rq-mina` mantiene la lista en `data` y entrega el contexto
de paginacion en `detail.pagination`. Exige permiso de consulta y alcance de
mina. Admite `page` y `per_page`, con un maximo de 100 registros por pagina.

Los estados se muestran con texto y color, sin depender solo del color:

- En preparacion;
- Pedido registrado en RR. HH.;
- Cerrado;
- Cancelado;
- En atencion;
- Completado.

## Responsive

El CSS de `resources/css/modules/rq-mina.css` agrega clases especificas para el
listado en tarjetas. En desktop la tarjeta usa una grilla interna; en pantallas
medianas pasa a una columna y en mobile los botones ocupan el ancho disponible.
El bloqueo movil antiguo queda neutralizado para esta pantalla.

## Accesibilidad

La nueva vista usa:

- titulos semanticos por tarjeta;
- botones reales para menus;
- `aria-expanded` y `aria-controls` en el menu de acciones;
- cierre de menu con clic fuera y tecla Escape;
- estados visibles con texto.

## Alcance

Las reglas de guardado, permisos y registro en RQ Proserge se describen en
[Planes operativos](./rq-mina-planes.md).

## Verificacion

Las correcciones del 2026-11-04 se verificaron con pruebas focalizadas de RQ
Mina e integraciones, compilacion Blade y navegador con datos sinteticos en
desktop y mobile. El detalle de las pruebas y sus limites esta en
[Planes operativos](./rq-mina-planes.md#validacion-local-de-las-correcciones-del-2026-11-04).
La comprobacion operativa del servidor queda pendiente del despliegue.
