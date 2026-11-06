# Conexión local de MySQL y esquema de las correcciones

5 de noviembre de 2026. Sólo ambiente local; sin cambios en FTP o producción.

## Causa y recuperación

La aplicación estaba configurada para `127.0.0.1:3306` y
`proserge_app_import_20260831_151029`. No existía un proceso MySQL escuchando
en ese puerto, por lo que el POST de login fallaba con SQLSTATE 2002.
Se conservó `.env` y se inició normalmente MariaDB de XAMPP con su `my.ini`.
El proceso queda limitado a `127.0.0.1` mediante argumento de arranque; no se
modificó el archivo `my.ini` ni se habilitó acceso de red a esta instancia.
MySQL respondió al ping y la consulta de la cuenta de login con sus relaciones
de roles se completó correctamente. Un POST con credenciales sintéticas
inexistentes devolvió la validación habitual y dejó de producir el error SQL.
Esto verifica conexión y consulta; no se utilizó ni cambió la contraseña de
ninguna cuenta real para iniciar sesión.

Antes del arranque se realizó una copia en frío de los 1.159 archivos del
directorio de datos y su configuración. La copia se guarda en
`storage/app/private/system-recovery/20261105-143604/mysql-data`, excluida
de Git y del acceso web. Se verificaron cantidad, tamaño total y hashes de
`ibdata1`, ambos redo logs y el control Aria. No se eliminaron archivos InnoDB
ni se ejecutaron reparaciones o restauraciones sobre el directorio original.

El registro contiene una inconsistencia anterior de tablespace en
`proserge_app/usuario_roles.ibd`, distinta del esquema importado utilizado por
la aplicación. No se atribuye a ella el rechazo TCP ni se declara reparada.
Su diagnóstico queda separado de esta recuperación de conexión.

## Esquema local de la entrega

Las nueve migraciones incrementales del 4 de noviembre seguían pendientes.
Se revisó su SQL y se aplicaron primero sobre una copia privada del directorio
de datos, con MariaDB de la misma versión en el puerto 13378. En 12 tablas
de negocio y seguridad se compararon todas las columnas existentes antes del
cambio: cantidad de filas y huella SHA-256 del contenido ordenado por ID.
Los valores previos se conservaron en todas ellas.

Después se aplicaron exclusivamente las mismas nueve migraciones en la base
local habitual, lote 39. La comparación independiente antes/después en esa
base confirmó de nuevo que las columnas y filas previas conservaron sus
valores. El esquema recibe las nuevas tablas, columnas e índices requeridos
por las correcciones. Las firmas se calcularon en privado sin imprimir datos
personales. No se ejecutó PHPUnit contra datos reales.

Los scripts, el SQL simulado y la evidencia detallada permanecen junto al
respaldo privado. La copia de validación se apagó al terminar; MySQL habitual
en 3306 permanece activo. El servidor de pruebas sintéticas de 13376 sigue
separado.

Quedan pendientes cuatro migraciones anteriores, ajenas a esta entrega:

- `2026_09_28_000100_enforce_unique_resident_evaluation_period`
- `2026_09_28_000200_grant_resident_evaluation_management_capability`
- `2026_10_05_000600_create_mining_exam_catalog_identity_tables`
- `2026_10_21_000100_align_notification_destination_permissions`

Requieren revisar su propósito y sus efectos antes de ejecutarlas. Este
registro no acredita despliegue ni migraciones en producción. Para un próximo
inicio de Windows, activar MySQL desde XAMPP antes de usar la aplicación.
