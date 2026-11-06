# Evaluaciones: dashboard y despliegue por WinSCP

Fecha: 2026-10-26.

## Cambio

Busqueda por nombre o documento, filtros agrupados, indicadores en tarjetas y
vista adaptable. Se conservan permisos, scope por mina, autoria y calculos.
No hay cambios de esquema ni migraciones para esta correccion.

El dashboard dependia de CSS y JS sueltos fuera del build. Transferir solamente
`public/build` no los incluia. Ahora ambos forman parte de las entradas de Vite;
las vistas de Evaluaciones y carga historica no hacen `filemtime` sobre esos
archivos externos. La prueba de navegador usa los enlaces reales del HTML.

## Archivos

El paquete de entrega incluye `upload/` con rutas relativas a la raiz Laravel:

- `app/Modules/Evaluaciones/Controllers/EvaluacionDesempenoPageController.php`
- `app/Modules/Evaluaciones/Services/EvaluacionesDashboardService.php`
- `app/Shared/Support/SearchTerms.php` (dependencia compartida de la busqueda)
- `resources/views/evaluaciones/index.blade.php`
- `resources/views/evaluaciones/historical-import.blade.php`
- `resources/views/evaluaciones/partials/dashboard.blade.php`
- `resources/css/app.css` y `resources/css/modules/evaluations-dashboard.css`
- `resources/js/app.js` y `resources/js/modules/evaluations-dashboard.js`
- `public/build/manifest.json` y **todos** los archivos de `public/build/assets/`

Los recursos se compilan localmente con `npm run build`. cPanel no necesita npm.
`public/build` coincide con una regla de `.gitignore`, aunque existen assets
anteriores ya versionados. Los hashes nuevos pueden quedar sin seguimiento:
no asumir que un `git pull` entrega el build nuevo sin revisar los archivos
versionados. Para WinSCP, transferir el build completo incluido en el paquete.
Los archivos antiguos `public/css/evaluations-dashboard.css` y
`public/js/evaluations-dashboard.js` pueden permanecer en el servidor; las vistas
nuevas ya no los utilizan. No copiar `public/hot`.

## Orden de transferencia

1. Guardar una copia de los archivos destino y del manifiesto/build anterior
   fuera del directorio publico, para poder revertir juntos PHP, vistas y assets.
2. Abrir en WinSCP la raiz del proyecto Laravel que contiene `artisan`, `app`,
   `resources` y `public`. La ruta documentada del ambiente actual es
   `~/public_html/sistema.proserge.com/Proserge-Asistencia-Minera`.
3. Subir primero los assets nuevos de `upload/public/build/assets/`, conservando
   los assets anteriores mientras se completa la transferencia. No sincronizar
   con borrado automatico.
4. Subir `app/`, `resources/` y finalmente `public/build/manifest.json`, respetando
   esas rutas. No crear un segundo `public/public` ni subir `upload` como carpeta
   adicional dentro de la aplicacion.
5. Si el alojamiento separa el DocumentRoot y la carpeta `public` de Laravel,
   el build debe existir tambien en el directorio que atiende la URL `/build/`.
   Comprobarlo desde el navegador; el archivo en disco no basta para demostrar
   que el servidor entrega CSS/JS correctamente.
6. En Terminal de cPanel, desde la raiz del proyecto:

   ```bash
   php artisan optimize:clear
   php artisan view:cache
   ```

   Esta correccion **no requiere** `migrate`, reinstalar dependencias ni cambiar
   `.env`. No subir bases locales, documentos privados ni archivos de prueba.

## Comprobacion despues de subir

- Abrir `/evaluaciones?tipo=dashboard` con una cuenta que tenga permiso.
- En Red/Network, los archivos CSS y JS de `/build/assets/` deben responder 200
  con contenido CSS/JS, no una pagina HTML, 404 o redireccion al login.
- Confirmar tarjetas, filtros alineados y ausencia de desbordamiento en movil.
- Buscar una persona conocida dentro de su periodo; comprobar indicadores,
  seleccionar tipo, cambiar fechas y restablecer. El documento no debe recargarse
  al aplicar filtros si JavaScript esta disponible.
- Si el navegador conserva una pagina anterior, hacer recarga completa. Los
  hashes nuevos del manifiesto cambian las URL y evitan reutilizar CSS/JS viejos.

Validacion local no equivale a validar el servidor remoto. La transferencia y
esta comprobacion deben realizarse en el ambiente de destino.
