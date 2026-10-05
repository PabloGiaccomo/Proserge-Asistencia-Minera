# Acceso y seguridad

Inicio de sesion web/API, cierre web y administracion de usuarios, roles y scope por mina.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Administrar usuarios, roles y scope | [BPMN](./administrar-usuarios-roles-scope.bpmn) | [SVG](./administrar-usuarios-roles-scope.svg) | Protege la administracion de identidades, roles y minas visibles, incluida la proteccion del ultimo administrador. |
| Iniciar sesion web | [BPMN](./iniciar-sesion-web.bpmn) | [SVG](./iniciar-sesion-web.svg) | Valida correo, clave, limite de intentos y estado ACTIVO; crea la sesion web propia. |
| Iniciar sesion API | [BPMN](./iniciar-sesion-api.bpmn) | [SVG](./iniciar-sesion-api.svg) | Valida correo y clave; crea un Bearer token hash con vencimiento a las 12 horas. |
| Cerrar sesion web | [BPMN](./cerrar-sesion-web.bpmn) | [SVG](./cerrar-sesion-web.svg) | Borra la sesion web y regenera el token CSRF; no revoca tokens Bearer de API. |

## Actores y datos

- Usuario
- Administrador de seguridad
- Sistema

## Evidencia principal

- `routes/web.php`
- `app/Modules/Seguridad/Controllers/UsuarioPageController.php`
- `app/Modules/Seguridad/Controllers/RolPageController.php`
- `app/Modules/Seguridad/Services/RoleManagementService.php`
- `app/Modules/Seguridad/Services/AdminProtectionService.php`
- `app/Support/Rbac/PermissionMatrix.php`
- `app/Modules/Auth/Controllers/LoginPageController.php`
- `routes/api.php`
- `app/Modules/Auth/Controllers/AuthController.php`
- `app/Http/Middleware/AuthenticateToken.php`

## Duplicidades o inconsistencias

- La proteccion web es explicita por accion; la API depende en mayor medida de auth.token y autorizacion interna.
- UsuarioPageController concentra validacion, sincronizacion de roles y scope.
- AuthController::login API no valida estado ACTIVO ni limita intentos, a diferencia del login web; AuthenticateToken tampoco comprueba estado del usuario.
- No hay endpoint de logout/revocacion de token API en routes/api.php; no modelar el cierre web como revocacion API.

## Por confirmar

- Politica operativa de revocacion anticipada de tokens API; actualmente expiran a las 12 horas.
