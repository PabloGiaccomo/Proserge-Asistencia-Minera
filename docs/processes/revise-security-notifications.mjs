import fs from 'node:fs';

const file = new URL('./process-catalog.json', import.meta.url);
const catalog = JSON.parse(fs.readFileSync(file, 'utf8'));
const auth = catalog.modules.find((module) => module.slug === 'seguridad-acceso');
const notifications = catalog.modules.find((module) => module.slug === 'notificaciones');

function diagram(module, slug, title, summary, evidence, lanes, nodes, paths, confirm = []) {
    const value = {
        slug, title, summary, evidence, confirm, lanes,
        nodes: nodes.map(([id, type, name, lane, col, documentation, layoutRow]) => ({
            id, type, name, lane, col, documentation,
            ...(layoutRow === undefined ? {} : { layoutRow }),
        })),
        flows: paths.map(([from, to, name], index) => ({
            id: `Flow_${index}`, from, to, ...(name ? { name } : {}),
        })),
    };
    const index = module.processes.findIndex((process) => process.slug === slug);
    if (index < 0) module.processes.push(value);
    else module.processes[index] = value;
}

const userSystem = [
    { id: 'Lane_User', name: 'Usuario' },
    { id: 'Lane_System', name: 'Sistema' },
];
const webAuth = 'app/Modules/Auth/Controllers/LoginPageController.php';
const apiAuth = 'app/Modules/Auth/Controllers/AuthController.php';

auth.processes = auth.processes.filter((process) => process.slug !== 'inicio-sesion');
auth.scope = 'Inicio de sesion web/API, cierre web y administracion de usuarios, roles y scope por mina.';
auth.inconsistencies = [...new Set([
    ...auth.inconsistencies,
    'AuthController::login API no valida estado ACTIVO ni limita intentos, a diferencia del login web; AuthenticateToken tampoco comprueba estado del usuario.',
    'No hay endpoint de logout/revocacion de token API en routes/api.php; no modelar el cierre web como revocacion API.',
])];
auth.confirm = ['Politica operativa de revocacion anticipada de tokens API; actualmente expiran a las 12 horas.'];

diagram(auth, 'iniciar-sesion-web', 'Iniciar sesion web',
    'Valida correo, clave, limite de intentos y estado ACTIVO; crea la sesion web propia.',
    ['routes/web.php', webAuth], userSystem,
    [
        ['Start', 'startEvent', 'Solicita iniciar sesion web', 'Lane_User', 0, 'routes/web.php: POST /login.'],
        ['Enter', 'userTask', 'Ingresar correo y clave', 'Lane_User', 1, `${webAuth}::login`],
        ['Validate', 'businessRuleTask', 'Validar formato, limite, clave y estado', 'Lane_System', 2, `${webAuth}::login: RateLimiter por email e IP, 5 intentos; Hash::check; estado ACTIVO si columna existe.`],
        ['Allowed', 'exclusiveGateway', 'Acceso permitido?', 'Lane_System', 3, `${webAuth}::login`],
        ['Create', 'serviceTask', 'Crear sesion y regenerar ID', 'Lane_System', 4, `${webAuth}::login guarda auth_token, user, user_id y permisos; session()->regenerate(); limpia limite.`],
        ['Done', 'endEvent', 'Redirigido a inicio', 'Lane_User', 5, `${webAuth}::login`],
        ['Reject', 'endEvent', 'Acceso rechazado o limitado', 'Lane_User', 4, `${webAuth}::login: error y formulario sin password.`],
    ],
    [['Start', 'Enter'], ['Enter', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Create', 'Si'], ['Create', 'Done'], ['Allowed', 'Reject', 'No']]);

diagram(auth, 'iniciar-sesion-api', 'Iniciar sesion API',
    'Valida correo y clave; crea un Bearer token hash con vencimiento a las 12 horas.',
    ['routes/api.php', apiAuth, 'app/Http/Middleware/AuthenticateToken.php'], userSystem,
    [
        ['Start', 'startEvent', 'Solicita token API', 'Lane_User', 0, 'routes/api.php: POST /api/v1/login.'],
        ['Enter', 'userTask', 'Enviar correo y clave', 'Lane_User', 1, `${apiAuth}::login`],
        ['Validate', 'businessRuleTask', 'Validar formato y clave', 'Lane_System', 2, `${apiAuth}::login: Request::validate y Hash::check; no comprueba estado ACTIVO.`],
        ['Allowed', 'exclusiveGateway', 'Credenciales validas?', 'Lane_System', 3, `${apiAuth}::login`],
        ['Create', 'serviceTask', 'Guardar hash del token con expiracion', 'Lane_System', 4, `${apiAuth}::login: AuthToken expires_at=now()+12h, IP y user agent.`],
        ['Done', 'endEvent', 'Bearer token entregado', 'Lane_User', 5, `${apiAuth}::login: access_token, expires_at y usuario.`],
        ['Reject', 'endEvent', 'Credenciales rechazadas', 'Lane_User', 4, `${apiAuth}::login: 401 para credenciales invalidas; 422 para formato invalido.`],
    ],
    [['Start', 'Enter'], ['Enter', 'Validate'], ['Validate', 'Allowed'], ['Allowed', 'Create', 'Si'], ['Create', 'Done'], ['Allowed', 'Reject', 'No']],
    ['Por confirmar: revocacion anticipada del token; no existe ruta de logout API.']);

diagram(auth, 'cerrar-sesion-web', 'Cerrar sesion web',
    'Borra la sesion web y regenera el token CSRF; no revoca tokens Bearer de API.',
    ['routes/web.php', webAuth], userSystem,
    [
        ['Start', 'startEvent', 'Usuario solicita salir', 'Lane_User', 0, 'routes/web.php: POST /logout.'],
        ['Request', 'userTask', 'Enviar solicitud de cierre', 'Lane_User', 1, `${webAuth}::logout`],
        ['Clear', 'serviceTask', 'Olvidar datos e invalidar sesion', 'Lane_System', 2, `${webAuth}::logout: forget auth_token/user/user_id, invalidate, regenerateToken.`],
        ['Done', 'endEvent', 'Redirigido a login', 'Lane_User', 3, `${webAuth}::logout`],
    ], [['Start', 'Request'], ['Request', 'Clear'], ['Clear', 'Done']]);

const notifyService = 'app/Modules/Notificaciones/Services/NotificationService.php';
const inboxService = 'app/Modules/Notificaciones/Services/NotificationInboxService.php';
notifications.processes = notifications.processes.filter((process) => process.slug !== 'emitir-recibir-notificacion');
notifications.scope = 'Emision de eventos, gestion de bandeja y purga programada de notificaciones.';
notifications.confirm = notifications.confirm.filter((entry) => !entry.includes('retencion definitiva'));
notifications.confirm.push('Catalogo de eventos que no pueden desactivarse por preferencia.');

diagram(notifications, 'emitir-notificacion', 'Emitir notificacion operativa',
    'Un modulo emite un evento, resuelve destinatarios y guarda copias; el consumo posterior es independiente.',
    [notifyService, 'app/Modules/Notificaciones/Services/NotificationRecipientResolverService.php'],
    [{ id: 'Lane_Source', name: 'Modulo emisor' }, { id: 'Lane_System', name: 'Sistema' }],
    [
        ['Start', 'startEvent', 'Modulo invoca emit', 'Lane_Source', 0, `${notifyService}::emit`],
        ['Validate', 'businessRuleTask', 'Validar tablas, tipo activo y mensaje', 'Lane_System', 1, `${notifyService}::emit devuelve null si falta infraestructura, tipo activo o mensaje.`],
        ['Valid', 'exclusiveGateway', 'Evento valido?', 'Lane_System', 2, `${notifyService}::emit`],
        ['Resolve', 'businessRuleTask', 'Resolver destinatarios y preferencias', 'Lane_System', 3, `${notifyService}::emit llama NotificationRecipientResolverService::resolve.`],
        ['HasRecipients', 'exclusiveGateway', 'Hay destinatarios?', 'Lane_System', 4, `${notifyService}::emit`],
        ['Persist', 'serviceTask', 'Crear o deduplicar evento y copias', 'Lane_System', 5, `${notifyService}::emit: DB::transaction; dedupe_key reutiliza evento e inserta destinatarios faltantes.`],
        ['Done', 'endEvent', 'Evento disponible en bandejas', 'Lane_Source', 6, `${notifyService}::emit devuelve NotificationEvent.`],
        ['Skip', 'endEvent', 'Emision omitida', 'Lane_Source', 5, `${notifyService}::emit devuelve null.`],
    ],
    [['Start', 'Validate'], ['Validate', 'Valid'], ['Valid', 'Resolve', 'Si'], ['Valid', 'Skip', 'No'], ['Resolve', 'HasRecipients'], ['HasRecipients', 'Persist', 'Si'], ['HasRecipients', 'Skip', 'No'], ['Persist', 'Done']]);

diagram(notifications, 'gestionar-bandeja', 'Gestionar bandeja personal',
    'El usuario autenticado consulta sus copias y decide leer, abrir accion, marcar todas o archivar.',
    ['routes/web.php', 'routes/api.php', 'app/Modules/Notificaciones/Controllers/NotificacionPageController.php', 'app/Modules/Notificaciones/Controllers/NotificacionController.php', inboxService], userSystem,
    [
        ['Start', 'startEvent', 'Usuario consulta bandeja', 'Lane_User', 0, 'Rutas web /notificaciones y API /api/v1/notificaciones.'],
        ['Query', 'serviceTask', 'Listar copias propias no vencidas', 'Lane_System', 1, `${inboxService}::listForUser/ latestForHeader filtra usuario_id y evento no vencido.`],
        ['Choose', 'userTask', 'Elegir leer, abrir o archivar', 'Lane_User', 2, 'NotificacionPageController / NotificacionController: acciones distintas, no secuencia obligatoria.'],
        ['Own', 'businessRuleTask', 'Validar identidad y copia si es individual', 'Lane_System', 3, 'Marcar todas usa usuario_id autenticado; leer, abrir y archivar comprueban recipientId con findRecipientForCurrentUser / findRecipientForUser.' ],
        ['Found', 'exclusiveGateway', 'Solicitud valida?', 'Lane_System', 4, 'En accion individual, copia propia inexistente da web 404 o API NOTIFICATION_NOT_FOUND.'],
        ['Update', 'serviceTask', 'Aplicar accion elegida', 'Lane_System', 5, `${inboxService}::markAsRead/markAllAsRead/archive; NotificacionPageController::openAction marca ACTIONED y redirige.`],
        ['Done', 'endEvent', 'Bandeja actualizada', 'Lane_User', 6, 'Se devuelve respuesta o redireccion.'],
        ['Reject', 'endEvent', 'Acceso o copia rechazados', 'Lane_User', 5, 'Sesion/token faltante o recipientId ajeno/inexistente.'],
    ],
    [['Start', 'Query'], ['Query', 'Choose'], ['Choose', 'Own'], ['Own', 'Found'], ['Found', 'Update', 'Si'], ['Found', 'Reject', 'No'], ['Update', 'Done']]);

fs.writeFileSync(file, `${JSON.stringify(catalog, null, 2)}\n`);
