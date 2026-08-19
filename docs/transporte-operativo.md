# Transporte operativo

## Diferencia entre requerimiento y servicio

`rq_mina_actividad_transportes` conserva el requerimiento solicitado desde el plan operativo de RQ Mina. Describe que se necesita transportar, para que grupo, SAIT o actividad, en que rango o turno, y con que observaciones.

`transporte_servicios` representa una unidad o servicio concreto usado en una fecha y turno. Aqui viven placa, conductor, capacidad, origen, destino, estado y datos operativos.

## Tipos

- `PERSONAL`: admite pasajeros y calcula capacidad, ocupacion, asientos disponibles y sobreocupacion.
- `CARGA`: no admite pasajeros. Sirve para unidad de carga, materiales o equipos.

## Alcances

Un servicio puede atender varios alcances:

- grupo operativo;
- actividad o SAIT;
- grupo diario de Man Power;
- SAIT snapshot cuando el dato viene como texto legacy.

La relacion esta en `transporte_servicio_alcances`. Debe existir al menos un alcance para confirmar un servicio.

## Pasajeros

Los pasajeros viven en `transporte_servicio_pasajeros` y apuntan a `grupo_trabajo_detalle`.

Solo pueden asignarse integrantes activos de Man Power:

- de la misma fecha;
- del mismo turno;
- pertenecientes a grupos relacionados al servicio;
- no retirados ni reubicados;
- no asignados activamente a otro servicio del mismo tramo.

Los estados iniciales son:

- `ASIGNADO`;
- `RETIRADO`;
- `REUBICADO`;
- `NO_ABORDO` reservado para etapa futura.

## Tramos

El tramo por defecto es `IDA`. Tambien existen `RETORNO` y `TRASLADO_INTERNO`.

Un pasajero no puede tener dos servicios activos del mismo tramo. Para ida y retorno se deben usar tramos distintos.

## Capacidad y ocupacion

Para `PERSONAL`:

- ocupacion = pasajeros activos;
- asientos disponibles = capacidad - ocupacion;
- sobreocupacion = ocupacion - capacidad cuando excede;
- capacidad null significa no registrada.

Se permite guardar `BORRADOR` sin capacidad. Para confirmar se requiere:

- placa;
- conductor;
- capacidad;
- al menos un alcance;
- sin sobreocupacion.

## Placa y conductor

La placa y el conductor no pueden duplicarse en otro servicio activo de la misma fecha, turno y tramo.

El conductor puede ser un trabajador del sistema. Se guarda snapshot del nombre para conservar historial si el dato cambia.

## Copia

La copia de un servicio crea otro en `BORRADOR` para otra fecha y turno.

Copia:

- transportista;
- tipo de vehiculo;
- capacidad;
- horarios;
- origen/destino;
- alcances;
- observaciones.

No copia pasajeros. La placa y el conductor solo se copian si el usuario lo solicita y pasan validacion.

## Historial

`transporte_servicio_eventos` registra:

- creacion;
- modificacion;
- cambio de estado;
- sincronizacion de alcances;
- asignacion o retiro de pasajeros;
- copia y reubicacion segun corresponda.

No reemplaza una auditoria transversal general.

## Compatibilidad legacy

Los campos actuales como `placas_asignadas`, `unidades_transporte`, `alcance` y `origen` pueden contener texto ambiguo o multiples valores.

La UI marca estos casos como:

`TRANSPORTE LEGACY SIN ESTRUCTURA COMPLETA`

El backfill solo vincula casos inequivocos:

```bash
php artisan transporte:backfill-servicios --dry-run
php artisan transporte:backfill-servicios
```

No ejecutar sin revisar primero el `--dry-run`.

## Integracion con RQ Mina

RQ Mina sigue registrando requerimientos de transporte dentro del plan operativo. La pantalla no se convierte en gestion detallada de pasajeros.

Desde el plan se agrega acceso a `Transporte operativo` para gestionar servicios concretos.

## Integracion con Man Power

Cada grupo diario puede vincularse a uno o varios servicios mediante alcances. Desde Man Power se puede abrir la planificacion del contexto.

Los pasajeros se toman de integrantes activos del grupo. Retirar un integrante de Man Power retira logicamente sus pasajeros activos con motivo `INTEGRANTE_RETIRADO_DE_MAN_POWER`.

## Relacion con Asistencia

Asistencia no se reconstruye. Sigue usando grupos e integrantes de Man Power.

El transporte no altera marcaciones, cierres ni faltas. Solo consume integrantes activos para definir pasajeros.

## Metricas disponibles

Por servicio:

- capacidad;
- ocupacion;
- asientos disponibles;
- sobreocupacion;
- porcentaje de ocupacion.

Por plan, fecha y turno:

- unidades requeridas;
- servicios creados;
- confirmados;
- capacidad total;
- personas distribuidas;
- personas con transporte;
- pendientes;
- transportes sin conductor;
- transportes sin placa;
- grupos sin transporte;
- unidades de carga requeridas y asignadas.

## Limitaciones pendientes

- No se implemento dashboard final de KPI.
- No se implemento GPS ni rutas geograficas.
- No se integro proveedor externo.
- No se implemento mantenimiento vehicular completo.
- No se implemento facturacion de transporte.
- No se ejecuto backfill real.
- No se recalcula valor real desde Asistencia.
- No se implemento el 15 % de suplentes EECC.
- No se cambio el 20 % de backup.

## Validacion local 2026-07-24

### Bloqueo inicial MySQL

La conexion local fallo porque XAMPP MySQL estaba detenido: no habia proceso `mysqld` activo y el puerto `127.0.0.1:3306` no respondia.

Se inicio el servicio local con `C:\xampp\mysql_start.bat`. Despues de eso `mysqld.exe` quedo activo desde `C:\xampp\mysql\bin\mysqld.exe` y el puerto 3306 respondio.

La configuracion efectiva de testing fue:

- `APP_ENV=testing`
- `DB_CONNECTION=mysql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_DATABASE=proserge_app_test`
- `DB_USERNAME=root`

No se uso `.env` de desarrollo ni una base productiva. La base efectiva contiene `_test`.

### Recuperacion del esquema de testing

`php artisan migrate:fresh --env=testing --force` expuso que las migraciones historicas no son autocontenidas para partir desde cero: `2026_04_22_000100_add_contact_fields_to_personal_table` intenta alterar `personal` antes de que exista.

Para recuperar solo el entorno local de pruebas se reconstruyo `proserge_app_test` desde `database/setup/*.sql`, se completo la tabla `migrations` con las migraciones existentes y se dejo la migracion de transporte en batch 2. Como los SQL de setup estaban atrasados frente a migraciones historicas, se corrigieron:

- `database/setup/001_initial_schema.sql`: tabla `usuario_roles` y columnas operativas de `personal_mina`.
- `database/setup/013_rq_mina_plan_operativo.sql`: columna reservada `real` con backticks.
- `database/setup/015_personal_contratos.sql`: columnas historicas de contratos, tipo de contrato, firma, renovacion, decision y cierre/no renovacion.
- `database/setup/019_transporte_operativo.sql`: columnas logisticas legacy y documentos en `rq_mina_actividad_transportes`.

Despues de reconstruir el esquema de pruebas se eliminaron los roles sembrados por setup en `proserge_app_test`, porque las pruebas crean sus propios roles y los seeds duplicaban nombres.

### Migracion y rollback

La migracion aplicada fue `2026_07_24_000100_create_transporte_operativo_tables`.

Tablas verificadas:

- `transporte_servicios`
- `transporte_servicio_alcances`
- `transporte_servicio_pasajeros`
- `transporte_servicio_eventos`

Se ejecuto rollback controlado de la ultima migracion sobre `proserge_app_test` y luego se volvio a aplicar. `migrate:status --env=testing` muestra la migracion de transporte como `[2] Ran`.

### Rutas revisadas

Se revisaron:

```bash
php artisan route:list --path=transporte -v
php artisan route:list --path=man-power -v
```

Transporte mostro 17 rutas. Las rutas web tienen `web.auth` y `web.permission`; las API usan `auth.token`.

Man Power mostro 24 rutas. Las rutas web conservan permisos por modulo/accion y las API usan `auth.token`.

### Matriz de cobertura

`tests/Feature/TransporteApiTest.php` cubre:

- servicio `PERSONAL` y `CARGA`;
- plan pertenece a parada;
- grupo operativo pertenece a plan;
- actividad pertenece al grupo;
- fecha dentro del rango;
- turno valido;
- servicio con dos grupos;
- grupo usado por varios servicios;
- servicio con varios SAIT;
- SAIT repetible en varios servicios;
- confirmacion exige placa, conductor, capacidad y alcance;
- carga no admite pasajeros;
- candidatos activos, no retirados, no reubicados, misma fecha, turno y grupo relacionado;
- asignacion individual y masiva;
- no asignacion parcial silenciosa al superar capacidad;
- ocupacion, asientos disponibles, sobreocupacion y porcentaje;
- bloqueo de sobreocupado al confirmar;
- no duplicar pasajero en el mismo tramo;
- permitir `IDA` y `RETORNO` separados;
- retiro con motivo obligatorio;
- reubicacion transaccional;
- retiro de Man Power retira pasajero activo;
- retiro de pasajero no retira integrante;
- copia en `BORRADOR` sin pasajeros;
- copia revalida alcances de Man Power;
- no duplicar placa ni conductor;
- consulta de plan archivado y bloqueo de modificaciones;
- scope de mina;
- permisos;
- transporte legacy visible;
- backfill inequivoco, ambiguo, idempotente y dry-run.

Regresiones relacionadas:

- `php artisan test --filter=Transporte`
- `php artisan test --filter=ManPower`
- `php artisan test --filter=RQMina`
- `php artisan test --filter=RQProserge`
- `php artisan test --filter=Asistencia`
- `php artisan test --filter=Backfill`
- `php artisan test --filter=Plan`

### Resultados ejecutados

- `php artisan test tests/Feature/TransporteApiTest.php`: 19 tests, 68 assertions, PASS.
- `php artisan test --filter=Transporte`: 22 tests, 86 assertions, PASS.
- `php artisan test --filter=ManPower`: 12 tests, 50 assertions, PASS.
- `php artisan test --filter=RQMina`: 33 tests, 178 assertions, PASS.
- `php artisan test --filter=RQProserge`: 26 tests, 105 assertions, PASS.
- `php artisan test --filter=Asistencia`: 12 tests, 34 assertions, PASS.
- `php artisan test --filter=Backfill`: 4 tests, 23 assertions, PASS.
- `php artisan test --filter=Plan`: 20 tests, 116 assertions, PASS.

La suite completa `php artisan test` se intento ejecutar, pero supero 240 segundos sin salida util. No se declara como pasada.

### Backfill

El dry-run manual se ejecuto solo contra `proserge_app_test`:

```bash
php artisan transporte:backfill-servicios --dry-run
```

Resultado:

- revisados: 0
- creados: 0
- omitidos: 0

No se ejecuto backfill real sobre datos reales.

### Validaciones estaticas

- `php -l` sobre PHP nuevos/modificados: OK, 93 archivos.
- `php artisan view:clear && php artisan view:cache && php artisan view:clear`: OK.
- `npm run build`: OK.
- `git diff --check`: OK, solo aviso CRLF en `public/css/proserge-app.css`.

### Verificacion visual

No se completo verificacion visual autenticada en navegador durante esta recuperacion. Queda pendiente revisar la pantalla con una sesion local valida en 1366x768, 1920x1080 y mobile basico.

### Confirmaciones

- No se modifico produccion.
- No se uso una base no dedicada para pruebas.
- No se ejecuto backfill real.
- No se elimino informacion historica.
- No se cambio el 20 %.
- No se implemento el 15 % EECC.
- No se creo dashboard final.
- No se recalculo `REAL` desde Asistencia.
- No se hizo commit.
