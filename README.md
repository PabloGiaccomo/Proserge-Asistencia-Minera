# Sistema Proserge

Aplicacion interna para administrar personal, fichas de colaborador, contratos,
requerimientos de mina, plan operativo, herramientas por parada, Man Power,
asistencia, faltas, evaluaciones, catalogos, usuarios, permisos y notificaciones.

## Stack principal

- PHP 8.2+ y Laravel 12
- MySQL
- Blade, JavaScript y CSS
- Tailwind CSS 4 y Vite 7
- PhpSpreadsheet, Dompdf, FPDF y FPDI
- PHPUnit 11

## Documentacion de traspaso

- [Guia tecnica integral](docs/GUIA_TECNICA_TRASPASO.md)
- [Mapa de archivos y puntos de cambio](docs/MAPA_ARCHIVOS.md)
- [Operacion, instalacion y despliegue](docs/OPERACION_DESPLIEGUE.md)

La guia tecnica es el documento principal para entender la arquitectura, los
modulos, los flujos de negocio, el modelo de datos y los riesgos conocidos.

## Inicio rapido local

Requisitos: PHP con `pdo_mysql`, Composer, MySQL y Node.js/npm.

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --force
npm install
npm run build
php artisan serve
```

Antes de ejecutar la aplicacion, ajuste `.env` para usar MySQL. El archivo
`.env.example` aun conserva valores genericos de Laravel y no representa por
completo la configuracion real del proyecto.

Para desarrollo con servidor, cola, logs y Vite:

```bash
composer dev
```

Para pruebas:

```bash
composer test
```

Las pruebas usan la base MySQL `proserge_app_test` configurada en `phpunit.xml`.
No deben ejecutarse apuntando a una base de datos con informacion real.
