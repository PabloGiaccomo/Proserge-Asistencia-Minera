# Operacion, instalacion y despliegue

## 1. Requisitos

- PHP 8.2 o superior con `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `zip`
  y extensiones requeridas por Laravel/PhpSpreadsheet.
- Composer.
- MySQL.
- Node.js y npm para compilar Vite.
- Servidor web apuntando a `public/`.
- Permisos de escritura para `storage/` y `bootstrap/cache/`.

## 2. Variables de ambiente

No copiar `.env` entre equipos por canales inseguros ni versionarlo. Como
minimo revisar:

```dotenv
APP_NAME=Proserge
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sistema.proserge.com

DB_CONNECTION=mysql
DB_HOST=...
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
FILESYSTEM_DISK=local
```

La plantilla `.env.example` actual aun usa valores genericos de Laravel y debe
corregirse antes de usarla para una instalacion nueva.

Laravel reporta actualmente la zona horaria `UTC` y `config/app.php` la tiene
escrita directamente, sin variable de ambiente. Antes de cambiarla, revisar
fechas ya almacenadas y probar los calculos de semana, vencimientos, alertas y
asistencia. Luego definir la zona acordada en `config/app.php` o adaptar esa
configuracion para leer una variable de ambiente.

## 3. Instalacion local recomendada

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
php artisan serve
```

En Windows, si PowerShell bloquea npm, usar `npm.cmd`. El archivo
`PASOS_INSTALACION_LOCAL.txt` documenta el entorno Laragon que se uso
originalmente, pero sus rutas son especificas de una computadora.

Para desarrollo:

```bash
composer dev
```

Esto inicia servidor, queue listener, Pail y Vite mediante `concurrently`.

## 4. Base de datos

Para una instalacion existente:

```bash
php artisan migrate --force
```

Para una instalacion totalmente nueva, validar primero si se usara el esquema
de `database/setup` mas migraciones o solo migraciones. Actualmente conviven
ambas estrategias y no debe asumirse que `migrate:fresh` reproduce todos los
datos maestros requeridos.

Antes de migrar produccion:

1. Crear backup de MySQL.
2. Crear backup de `storage/app/private`.
3. Revisar SQL de la nueva migracion.
4. Probar sobre una copia.
5. Ejecutar despliegue.
6. Verificar rutas, login y flujo modificado.

Riesgo conocido: la migracion
`2026_06_01_000200_add_grupo_trabajo_id_to_asistencia_encabezado.php` produjo
un conflicto de indice duplicado en una instalacion local.

## 5. Assets frontend

Toda vista que usa `@vite` requiere:

```text
public/build/manifest.json
public/build/assets/*
```

Compilar:

```bash
npm ci
npm run build
```

Si el manifiesto falta, Laravel lanza `ViteManifestNotFoundException` y la
aplicacion responde 500. En el cPanel actual `npm` no esta disponible, por lo
que hay dos opciones correctas:

1. Compilar en CI o en una maquina controlada y desplegar `public/build`.
2. Habilitar una version compatible de Node/npm en cPanel.

No ejecutar `php artisan view:cache` antes de asegurar que el build existe.

## 6. Despliegue cPanel actual

Ruta conocida:

```text
~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera
```

Comando usado cuando el build ya esta incluido en el repositorio/despliegue:

```bash
cd ~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera && git pull origin main && php artisan migrate --force && php artisan optimize:clear && php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Despues ejecutar comprobaciones:

```bash
git log -1 --oneline
test -f public/build/manifest.json && echo "Vite OK"
php artisan migrate:status
php artisan route:list
php artisan about
```

No incluir `npm ci && npm run build` en el comando de cPanel mientras `npm` no
exista en ese servidor.

## 7. Cron y tareas programadas

Configurar un cron cada minuto:

```cron
* * * * * cd /home/trjytmzf/public_html/sistema.proserge.com/Proserge-Asistencia-Minera && php artisan schedule:run >> /dev/null 2>&1
```

El scheduler ejecuta:

- 02:10: limpieza de notificaciones vencidas.
- 07:30: alertas de herramientas por parada.

Comprobar manualmente:

```bash
php artisan schedule:list
php artisan notifications:cleanup-expired
php artisan herramientas-parada:alertas-vencimiento
```

## 8. Cola

El script `composer dev` levanta `queue:listen`, pero el codigo actual usa
principalmente ejecucion sincrona. Si se migra correo, exportaciones o
notificaciones a jobs, produccion necesitara un worker persistente administrado
por Supervisor, systemd o una funcion equivalente de cPanel.

## 9. Backups

Respaldar como una sola unidad consistente:

- base MySQL;
- `storage/app/private`;
- `.env` en almacen seguro;
- plantillas de contrato y recursos publicados.

El backup de base sin archivos deja fichas y contratos incompletos. El backup
de archivos sin base pierde relaciones y metadata.

Probar restauracion periodicamente en un ambiente aislado.

## 10. Checklist posterior al despliegue

1. `GET /up` responde correctamente.
2. Login web funciona.
3. La cabecera, sidebar y favicon cargan.
4. `public/build/manifest.json` existe.
5. La ultima migracion aparece ejecutada.
6. Un usuario no administrador conserva permisos correctos.
7. Personal lista y abre documentos autorizados.
8. RQ Mina lista, abre y conserva plan operativo.
9. Herramientas por parada abre.
10. La campana de notificaciones aparece para un usuario permitido.
11. Scheduler y logs no muestran errores nuevos.

## 11. Diagnostico de incidentes frecuentes

### Error 500 por Vite

Sintoma: `ViteManifestNotFoundException`.

Accion: restaurar o publicar `public/build`, luego limpiar cache.

### Una ruta no existe despues del pull

```bash
php artisan optimize:clear
php artisan route:list
php artisan route:cache
```

Verificar que el commit esperado este realmente desplegado.

### Un usuario recibe 403

Revisar:

- estado del usuario;
- rol principal y roles adicionales;
- accion de la ruta en `routes/web.php`;
- matriz efectiva;
- `usuario_mina_scope`;
- log `web.permission_denied`.

### No aparecen notificaciones

Revisar:

- `notification_user_settings.in_app_enabled`;
- preferencia por rol y por tipo;
- permiso requerido del tipo;
- scope de mina;
- tipo activo;
- logs `notificaciones.*`.

### No se entrega correo de ficha

El flujo actual usa Outlook en Windows o devuelve `mailto:`. No asumir que
cPanel envia automaticamente. Revisar el resultado del servicio y considerar
migrar a SMTP.

### Documento no disponible

Confirmar fila de metadata, ruta registrada, existencia dentro de
`storage/app/private` y permisos del directorio. No mover documentos a `public`
como solucion rapida.

## 12. Seguridad operativa

- Mantener `APP_DEBUG=false` en produccion.
- Rotar credenciales compartidas y la cuenta local por defecto.
- No publicar `.env`, backups ni documentos.
- Restringir rutas API de catalogos que escriben sin `auth.token`.
- Auditar la exposicion automatica de `storage/{path}`.
- Mantener Laravel, PHP y dependencias actualizadas tras probar regresiones.
- Registrar quien descarga, aprueba, cesa o elimina datos sensibles.
