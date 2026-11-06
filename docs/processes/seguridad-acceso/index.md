# Acceso y seguridad

Inicio de sesion web/API, cierre web y administracion de usuarios, roles y scope por mina.

## Procesos

| Proceso | BPMN | Visual | Alcance |
|---|---|---|---|
| Administrar usuarios, roles y scope | [BPMN](./administrar-usuarios-roles-scope.bpmn) | [SVG](./administrar-usuarios-roles-scope.svg) | Protege la administracion de identidades, roles y minas visibles, incluida la proteccion del ultimo administrador. |
| Crear varios usuarios | | | Selecciona trabajadores de Personal, revisa el correo y el rol común, crea las cuentas y entrega sus credenciales en una tabla privada y un Excel. |
| Iniciar sesion web | [BPMN](./iniciar-sesion-web.bpmn) | [SVG](./iniciar-sesion-web.svg) | Valida correo, clave, limite de intentos y estado ACTIVO; crea la sesion web propia. |
| Iniciar sesion API | [BPMN](./iniciar-sesion-api.bpmn) | [SVG](./iniciar-sesion-api.svg) | Valida correo y clave; crea un Bearer token hash con vencimiento a las 12 horas. |
| Cerrar sesion web | [BPMN](./cerrar-sesion-web.bpmn) | [SVG](./cerrar-sesion-web.svg) | Borra la sesion web y regenera el token CSRF; no revoca tokens Bearer de API. |

## Actores y datos

- Usuario
- Administrador de seguridad
- Sistema

## Alta individual y múltiple

En **Usuarios → Nuevo Usuario**, la búsqueda empieza al escribir dos caracteres.
Busca por nombre, DNI, documento o puesto. **Crear varios** permite
agregar hasta 25 trabajadores sin perder la selección al hacer otra búsqueda.
Cada correo de acceso se revisa antes de guardar y el rol inicial se aplica al
lote. El alta múltiple requiere los permisos **Crear** y **Asignar roles**.

Si un trabajador ya tiene cuenta, se repite un correo o el rol deja de estar
activo, se rechaza el lote completo. Un reintento con los mismos datos recupera
el resultado del lote. El alta mantiene la vinculación de una cuenta por
trabajador y deja sus datos de Personal como estaban.

El servidor combina el nombre y el documento del trabajador con una clave
aleatoria independiente al generar cada contraseña inicial. La cuenta conserva
el hash de la contraseña. El resultado cifrado permanece disponible durante
15 minutos para el operador que creó el lote y conserva ambos permisos.
El Excel contiene trabajador, usuario y contraseña inicial.
La pantalla presenta las horas en la zona horaria de visualización configurada.

**Lotes recientes** permite volver a consultar el resultado mientras esté
vigente. Al vencer se bloquea la consulta y la descarga. La tarea programada
elimina las credenciales cifradas vencidas y conserva el actor, el rol, la fecha
y los identificadores de las cuentas creadas.
Las altas completadas generan el aviso habitual de creación para Seguridad.
La emisión del aviso ocurre después de guardar las cuentas y el resultado.

## Evidencia principal

- `routes/web.php`
- `app/Modules/Seguridad/Controllers/UsuarioPageController.php`
- `app/Modules/Seguridad/Controllers/RolPageController.php`
- `app/Modules/Seguridad/Services/RoleManagementService.php`
- `app/Modules/Seguridad/Services/AdminProtectionService.php`
- `app/Modules/Seguridad/Controllers/UsuarioAltaLoteController.php`
- `app/Modules/Seguridad/Services/UsuarioAltaLoteService.php`
- `app/Modules/Seguridad/Services/UsuarioTrabajadorSearchService.php`
- `tests/Feature/UsuarioAltaLoteTest.php`
- `tests/Feature/UsuarioTrabajadorSearchTest.php`
- `tests/Browser/usuario-alta-lote.cjs`
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
