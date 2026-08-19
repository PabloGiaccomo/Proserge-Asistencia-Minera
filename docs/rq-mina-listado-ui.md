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
- acciones principales y menu de acciones secundarias.

## Acciones

Las acciones conservan las rutas existentes:

- `rq-mina.plan`;
- `rq-mina.show`;
- `rq-mina.edit`;
- `rq-mina.create?copy_from=...`;
- `rq-mina.enviar`;
- `rq-mina.destroy`.

Las acciones siguen dependiendo de los permisos calculados en la vista mediante
`PermissionMatrix::allowsDirect`. El envio se muestra solo para registros en
`BORRADOR` y conserva su confirmacion. La eliminacion conserva la confirmacion
operativa existente. La accion `Abrir plan` se muestra solo cuando el usuario
tiene permiso `rq_mina,editar`, que es el mismo permiso exigido por la ruta web.

## Filtros y estados

La busqueda y los filtros avanzados siguen usando query string y el backend
actual. Se agregan chips para retirar filtros activos de forma individual.

Los estados se muestran con texto y color, sin depender solo del color:

- Borrador;
- Enviado;
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

Esta iteracion fue visual. No se cambiaron reglas de negocio, calculos,
permisos, RQ Proserge, Man Power, Transporte, Asistencia ni base de datos.

## Verificacion

Validaciones tecnicas previstas:

- `php artisan route:list --path=rq-mina -v`;
- `php artisan view:clear`;
- `php artisan view:cache`;
- `npm run build`;
- `git diff --check`;
- pruebas filtradas de RQ Mina cuando la base `proserge_app_test` este
  disponible.

La verificacion visual debe hacerse con sesion local autenticada para revisar
registros con plan, sin plan, con transporte, sin transporte y estados reales.
