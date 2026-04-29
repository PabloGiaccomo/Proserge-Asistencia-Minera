SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS roles (
  id CHAR(36) NOT NULL,
  nombre VARCHAR(120) NOT NULL,
  permisos JSON NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal (
  id CHAR(36) NOT NULL,
  dni VARCHAR(20) NOT NULL,
  nombre_completo VARCHAR(191) NOT NULL,
  puesto VARCHAR(120) NOT NULL,
  ocupacion VARCHAR(120) NULL,
  contrato VARCHAR(40) NULL,
  es_supervisor TINYINT(1) NOT NULL DEFAULT 0,
  qr_code VARCHAR(191) NOT NULL,
  fecha_ingreso DATE NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  telefono VARCHAR(30) NULL,
  correo VARCHAR(191) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_personal_dni (dni),
  UNIQUE KEY uq_personal_qr (qr_code),
  KEY idx_personal_estado (estado),
  KEY idx_personal_supervisor (es_supervisor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuarios (
  id CHAR(36) NOT NULL,
  email VARCHAR(191) NOT NULL,
  password VARCHAR(255) NOT NULL,
  rol_id CHAR(36) NOT NULL,
  personal_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuarios_email (email),
  UNIQUE KEY uq_usuarios_personal (personal_id),
  KEY idx_usuarios_rol (rol_id),
  CONSTRAINT fk_usuarios_rol FOREIGN KEY (rol_id) REFERENCES roles(id),
  CONSTRAINT fk_usuarios_personal FOREIGN KEY (personal_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS minas (
  id CHAR(36) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  unidad_minera VARCHAR(191) NOT NULL,
  ubicacion VARCHAR(191) NULL,
  link_ubicacion VARCHAR(500) NULL,
  color VARCHAR(30) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_minas_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS talleres (
  id CHAR(36) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  ubicacion VARCHAR(191) NULL,
  link_ubicacion VARCHAR(500) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS oficinas (
  id CHAR(36) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  ubicacion VARCHAR(191) NULL,
  link_ubicacion VARCHAR(500) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mina_paraderos (
  id CHAR(36) NOT NULL,
  mina_id CHAR(36) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  ubicacion VARCHAR(191) NULL,
  link_ubicacion VARCHAR(500) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mina_paraderos_mina (mina_id),
  CONSTRAINT fk_mina_paraderos_mina FOREIGN KEY (mina_id) REFERENCES minas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS usuario_mina_scope (
  id CHAR(36) NOT NULL,
  usuario_id CHAR(36) NOT NULL,
  mina_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_usuario_mina_scope (usuario_id, mina_id),
  KEY idx_usuario_mina_scope_usuario (usuario_id),
  KEY idx_usuario_mina_scope_mina (mina_id),
  CONSTRAINT fk_usuario_mina_scope_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_usuario_mina_scope_mina FOREIGN KEY (mina_id) REFERENCES minas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_mina (
  id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  mina_id CHAR(36) NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'EN_PROCESO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_personal_mina (personal_id, mina_id),
  KEY idx_personal_mina_estado (estado),
  CONSTRAINT fk_personal_mina_personal FOREIGN KEY (personal_id) REFERENCES personal(id) ON DELETE CASCADE,
  CONSTRAINT fk_personal_mina_mina FOREIGN KEY (mina_id) REFERENCES minas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_mina (
  id CHAR(36) NOT NULL,
  mina_id CHAR(36) NOT NULL,
  area VARCHAR(191) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  observaciones TEXT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  created_by_usuario_id CHAR(36) NOT NULL,
  enviado_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rq_mina_mina (mina_id),
  KEY idx_rq_mina_estado (estado),
  KEY idx_rq_mina_creador (created_by_usuario_id),
  CONSTRAINT fk_rq_mina_mina FOREIGN KEY (mina_id) REFERENCES minas(id),
  CONSTRAINT fk_rq_mina_creador FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_mina_detalle (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  puesto VARCHAR(191) NOT NULL,
  cantidad INT NOT NULL,
  cantidad_atendida INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rq_mina_detalle_rq (rq_mina_id),
  CONSTRAINT fk_rq_mina_detalle_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_proserge (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  mina_id CHAR(36) NOT NULL,
  responsable_rrhh_id CHAR(36) NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  comentario_planner TEXT NULL,
  comentario_rrhh TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rq_proserge_rq_mina (rq_mina_id),
  KEY idx_rq_proserge_mina (mina_id),
  KEY idx_rq_proserge_rrhh (responsable_rrhh_id),
  CONSTRAINT fk_rq_proserge_rq_mina FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE,
  CONSTRAINT fk_rq_proserge_mina FOREIGN KEY (mina_id) REFERENCES minas(id),
  CONSTRAINT fk_rq_proserge_rrhh FOREIGN KEY (responsable_rrhh_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_proserge_detalle (
  id CHAR(36) NOT NULL,
  rq_proserge_id CHAR(36) NOT NULL,
  rq_mina_detalle_id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  puesto_asignado VARCHAR(191) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  comentario TEXT NULL,
  ultimo_turno_referencia VARCHAR(10) NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ASIGNADO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rq_proserge_detalle (rq_proserge_id, rq_mina_detalle_id, personal_id, fecha_inicio, fecha_fin),
  KEY idx_rq_proserge_detalle_personal (personal_id),
  KEY idx_rq_proserge_detalle_rango (fecha_inicio, fecha_fin),
  CONSTRAINT fk_rq_proserge_detalle_rq FOREIGN KEY (rq_proserge_id) REFERENCES rq_proserge(id) ON DELETE CASCADE,
  CONSTRAINT fk_rq_proserge_detalle_rq_det FOREIGN KEY (rq_mina_detalle_id) REFERENCES rq_mina_detalle(id),
  CONSTRAINT fk_rq_proserge_detalle_personal FOREIGN KEY (personal_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grupo_trabajo (
  id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  supervisor_id CHAR(36) NOT NULL,
  mina VARCHAR(191) NULL,
  rq_mina_id CHAR(36) NULL,
  rq_proserge_id CHAR(36) NULL,
  servicio VARCHAR(191) NOT NULL,
  area VARCHAR(191) NOT NULL,
  paradero VARCHAR(191) NULL,
  paradero_link VARCHAR(500) NULL,
  unidad VARCHAR(191) NULL,
  horario_salida TIME NOT NULL,
  turno VARCHAR(10) NOT NULL DEFAULT 'DIA',
  estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
  observaciones TEXT NULL,
  created_by_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_grupo_trabajo_fecha (fecha),
  KEY idx_grupo_trabajo_supervisor (supervisor_id),
  KEY idx_grupo_trabajo_rq_mina (rq_mina_id),
  KEY idx_grupo_trabajo_estado (estado),
  CONSTRAINT fk_grupo_trabajo_supervisor FOREIGN KEY (supervisor_id) REFERENCES personal(id),
  CONSTRAINT fk_grupo_trabajo_creador FOREIGN KEY (created_by_id) REFERENCES usuarios(id),
  CONSTRAINT fk_grupo_trabajo_rq_mina FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS grupo_trabajo_detalle (
  id CHAR(36) NOT NULL,
  grupo_trabajo_id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  hora_marcado TIME NULL,
  estado_asistencia VARCHAR(20) NOT NULL DEFAULT 'AUSENTE',
  observaciones TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grupo_trabajo_detalle (grupo_trabajo_id, personal_id),
  KEY idx_grupo_trabajo_detalle_personal (personal_id),
  CONSTRAINT fk_grupo_trabajo_detalle_grupo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id) ON DELETE CASCADE,
  CONSTRAINT fk_grupo_trabajo_detalle_personal FOREIGN KEY (personal_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_encabezado (
  id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  hora_ingreso TIME NOT NULL,
  mina_id CHAR(36) NOT NULL,
  reporte_suceso TEXT NULL,
  supervisor_id CHAR(36) NOT NULL,
  actividad_realizada TEXT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'REGISTRADO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_asistencia_encabezado_fecha (fecha),
  CONSTRAINT fk_asistencia_encabezado_mina FOREIGN KEY (mina_id) REFERENCES minas(id),
  CONSTRAINT fk_asistencia_encabezado_supervisor FOREIGN KEY (supervisor_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_detalle (
  id CHAR(36) NOT NULL,
  asistencia_id CHAR(36) NOT NULL,
  trabajador_id CHAR(36) NOT NULL,
  hora_marcado TIME NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'PRESENTE',
  observaciones TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_asistencia_detalle (asistencia_id, trabajador_id),
  CONSTRAINT fk_asistencia_detalle_asistencia FOREIGN KEY (asistencia_id) REFERENCES asistencia_encabezado(id) ON DELETE CASCADE,
  CONSTRAINT fk_asistencia_detalle_trabajador FOREIGN KEY (trabajador_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_bloqueo (
  id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  motivo VARCHAR(191) NOT NULL,
  detalle TEXT NULL,
  bloqueado_por_id CHAR(36) NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  visible_para_planner TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_personal_bloqueo_personal (personal_id),
  KEY idx_personal_bloqueo_rango (fecha_inicio, fecha_fin),
  CONSTRAINT fk_personal_bloqueo_personal FOREIGN KEY (personal_id) REFERENCES personal(id),
  CONSTRAINT fk_personal_bloqueo_usuario FOREIGN KEY (bloqueado_por_id) REFERENCES usuarios(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS faltas (
  id CHAR(36) NOT NULL,
  trabajador_id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  motivo VARCHAR(40) NOT NULL,
  descripcion TEXT NULL,
  observaciones TEXT NULL,
  registrada_por_id CHAR(36) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faltas_trabajador_fecha (trabajador_id, fecha),
  CONSTRAINT fk_faltas_trabajador FOREIGN KEY (trabajador_id) REFERENCES personal(id),
  CONSTRAINT fk_faltas_registrador FOREIGN KEY (registrada_por_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluacion_desempeno (
  id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  hora TIME NOT NULL,
  mina_id CHAR(36) NOT NULL,
  semana_parada INT NULL,
  desempeno_trabajo INT NOT NULL,
  orden_limpieza INT NOT NULL,
  compromiso INT NOT NULL,
  respuesta_emocional INT NOT NULL,
  seguridad_trabajo INT NOT NULL,
  total INT NOT NULL,
  observaciones TEXT NULL,
  supervisor_id CHAR(36) NOT NULL,
  trabajador_id CHAR(36) NOT NULL,
  tuvo_incidencia TINYINT(1) NOT NULL DEFAULT 0,
  descripcion_incidencia TEXT NULL,
  asistencia_detalle_id CHAR(36) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_eval_des_trabajador (trabajador_id),
  CONSTRAINT fk_eval_des_mina FOREIGN KEY (mina_id) REFERENCES minas(id),
  CONSTRAINT fk_eval_des_supervisor FOREIGN KEY (supervisor_id) REFERENCES personal(id),
  CONSTRAINT fk_eval_des_trabajador FOREIGN KEY (trabajador_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS promedio_desempeno (
  id CHAR(36) NOT NULL,
  trabajador_id CHAR(36) NOT NULL,
  cantidad_evaluaciones INT NOT NULL DEFAULT 0,
  promedio_total DECIMAL(8,2) NOT NULL DEFAULT 0,
  ultima_evaluacion DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_promedio_des_trabajador (trabajador_id),
  CONSTRAINT fk_promedio_des_trabajador FOREIGN KEY (trabajador_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluacion_supervisor (
  id CHAR(36) NOT NULL,
  evaluador_id CHAR(36) NOT NULL,
  evaluado_id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  mina_id CHAR(36) NOT NULL,
  resultado_final DECIMAL(8,2) NOT NULL,
  comentarios_finales TEXT NULL,
  aspectos_positivos TEXT NULL,
  capacitaciones_recomendadas TEXT NULL,
  firma_supervisor TEXT NULL,
  respuestas JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_eval_sup_evaldor FOREIGN KEY (evaluador_id) REFERENCES personal(id),
  CONSTRAINT fk_eval_sup_evaldo FOREIGN KEY (evaluado_id) REFERENCES personal(id),
  CONSTRAINT fk_eval_sup_mina FOREIGN KEY (mina_id) REFERENCES minas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS evaluacion_residente (
  id CHAR(36) NOT NULL,
  fecha DATE NOT NULL,
  indicadores_kpi DECIMAL(8,2) NOT NULL,
  costos_servicio DECIMAL(8,2) NOT NULL,
  eventos_seguridad DECIMAL(8,2) NOT NULL,
  reportes_calidad DECIMAL(8,2) NOT NULL,
  liderazgo_gestion DECIMAL(8,2) NOT NULL,
  innovacion DECIMAL(8,2) NOT NULL,
  total DECIMAL(8,2) NOT NULL,
  residente_id CHAR(36) NOT NULL,
  evaluador_id CHAR(36) NOT NULL,
  comentarios TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_eval_res_residente FOREIGN KEY (residente_id) REFERENCES personal(id),
  CONSTRAINT fk_eval_res_evaluador FOREIGN KEY (evaluador_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_remota (
  id CHAR(36) NOT NULL,
  jefe_id CHAR(36) NOT NULL,
  asistente_id CHAR(36) NOT NULL,
  tarea VARCHAR(191) NOT NULL,
  fecha DATE NOT NULL,
  horario_inicio TIME NOT NULL,
  horario_fin TIME NOT NULL,
  aprobacion VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
  firma TEXT NULL,
  observaciones TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_asistencia_remota_jefe FOREIGN KEY (jefe_id) REFERENCES personal(id),
  CONSTRAINT fk_asistencia_remota_asistente FOREIGN KEY (asistente_id) REFERENCES personal(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS epp_registro (
  id CHAR(36) NOT NULL,
  codigo VARCHAR(120) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  categoria VARCHAR(30) NOT NULL,
  unidad_minera VARCHAR(191) NULL,
  precio_unitario DECIMAL(12,2) NOT NULL DEFAULT 0,
  precio_alquiler DECIMAL(12,2) NULL,
  proveedor VARCHAR(191) NULL,
  orden_compra VARCHAR(120) NULL,
  facturacion VARCHAR(120) NULL,
  stock INT NOT NULL DEFAULT 0,
  estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_epp_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_types (
  id CHAR(36) NOT NULL,
  code VARCHAR(80) NOT NULL,
  module VARCHAR(50) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'operacion',
  default_priority VARCHAR(20) NOT NULL DEFAULT 'medium',
  required_permission_module VARCHAR(50) NULL,
  required_permission_action VARCHAR(30) NULL,
  default_title VARCHAR(191) NOT NULL,
  default_action_label VARCHAR(80) NULL,
  default_action_route VARCHAR(191) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_types_code (code),
  KEY idx_notification_types_module_active (module, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_events (
  id CHAR(36) NOT NULL,
  notification_type_id CHAR(36) NOT NULL,
  actor_usuario_id CHAR(36) NULL,
  mina_id CHAR(36) NULL,
  module VARCHAR(50) NOT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'medium',
  title VARCHAR(191) NOT NULL,
  message VARCHAR(500) NOT NULL,
  action_label VARCHAR(80) NULL,
  action_route VARCHAR(191) NULL,
  entity_type VARCHAR(80) NULL,
  entity_id VARCHAR(80) NULL,
  payload JSON NULL,
  dedupe_key VARCHAR(191) NULL,
  occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_events_dedupe (dedupe_key),
  KEY idx_notification_events_module_priority (module, priority),
  KEY idx_notification_events_mina_occurred (mina_id, occurred_at),
  KEY idx_notification_events_expires (expires_at),
  CONSTRAINT fk_notification_events_type FOREIGN KEY (notification_type_id) REFERENCES notification_types(id),
  CONSTRAINT fk_notification_events_actor FOREIGN KEY (actor_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_notification_events_mina FOREIGN KEY (mina_id) REFERENCES minas(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_recipients (
  id CHAR(36) NOT NULL,
  notification_event_id CHAR(36) NOT NULL,
  usuario_id CHAR(36) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'UNREAD',
  delivered_at TIMESTAMP NULL,
  read_at TIMESTAMP NULL,
  archived_at TIMESTAMP NULL,
  actioned_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_recipients_event_user (notification_event_id, usuario_id),
  KEY idx_notification_recipients_user_status (usuario_id, status, created_at),
  CONSTRAINT fk_notification_recipients_event FOREIGN KEY (notification_event_id) REFERENCES notification_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_recipients_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notification_preferences (
  id CHAR(36) NOT NULL,
  usuario_id CHAR(36) NOT NULL,
  notification_type_id CHAR(36) NOT NULL,
  in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
  email_enabled TINYINT(1) NOT NULL DEFAULT 0,
  minimum_priority VARCHAR(20) NOT NULL DEFAULT 'low',
  mute_until TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_preferences_user_type (usuario_id, notification_type_id),
  CONSTRAINT fk_notification_preferences_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_notification_preferences_type FOREIGN KEY (notification_type_id) REFERENCES notification_types(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- ACTUALIZACIONES ADICIONALES PARA CAMPOS AGREGADOS DESDE LA IMPLEMENTACION
-- =============================================================================

-- Agregar columnas de telefono分裂 y descripcion a roles
ALTER TABLE roles ADD COLUMN descripcion VARCHAR(255) NULL AFTER nombre;

-- Agregar columnas de telefono分裂 a personal
ALTER TABLE personal ADD COLUMN telefono_1 VARCHAR(30) NULL AFTER telefono;
ALTER TABLE personal ADD COLUMN telefono_2 VARCHAR(30) NULL AFTER telefono_1;

-- Agregar columna de estado a usuarios
ALTER TABLE usuarios ADD COLUMN estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVO' AFTER personal_id;

-- =============================================================================
-- DATOS INICIALES (seed) - Estos datos se insertan al crear la DB
-- =============================================================================

INSERT INTO roles (id, nombre, descripcion, permisos, estado, created_at, updated_at) VALUES
(UUID(), 'ADMIN', 'Usuario con acceso total al sistema', '{\"personal\":{\"ver\":true,\"crear\":true,\"editar\":true,\"eliminar\":true},\"rq_mina\":{\"ver\":true,\"crear\":true,\"editar\":true,\"eliminar\":true},\"usuarios\":{\"ver\":true,\"crear\":true,\"editar\":true,\"eliminar\":true},\"roles\":{\"ver\":true,\"crear\":true,\"editar\":true,\"eliminar\":true},\"catalogos\":{\"ver\":true,\"crear\":true,\"editar\":true,\"eliminar\":true}}', 'ACTIVO', NOW(), NOW()),
(UUID(), 'GERENTE', 'Usuario gerente con permisos avanzados', '{\"personal\":{\"ver\":true,\"crear\":true,\"editar\":true},\"rq_mina\":{\"ver\":true,\"crear\":true,\"editar\":true},\"usuarios\":{\"ver\":true},\"roles\":{\"ver\":true},\"catalogos\":{\"ver\":true,\"crear\":true,\"editar\":true}}', 'ACTIVO', NOW(), NOW()),
(UUID(), 'SUPERVISOR', 'Supervisor de mina', '{\"personal\":{\"ver\":true},\"rq_mina\":{\"ver\":true,\"crear\":true},\"usuarios\":{\"ver\":true},\"catalogos\":{\"ver\":true}}', 'ACTIVO', NOW(), NOW()),
(UUID(), 'RRHH', 'Recursos Humanos', '{\"personal\":{\"ver\":true,\"crear\":true,\"editar\":true},\"rq_mina\":{\"ver\":true},\"usuarios\":{\"ver\":true,\"crear\":true,\"editar\":true},\"roles\":{\"ver\":true},\"catalogos\":{\"ver\":true}}', 'ACTIVO', NOW(), NOW()),
(UUID(), 'OPERACIONES', 'Usuario de operaciones', '{\"personal\":{\"ver\":true}}', 'ACTIVO', NOW(), NOW()),
(UUID(), 'USUARIO', 'Usuario basico', '{\"personal\":{\"ver\":true}}', 'ACTIVO', NOW(), NOW())
ON DUPLICATE KEY UPDATE updated_at = NOW();

INSERT INTO notification_types (id, code, module, category, default_priority, required_permission_module, required_permission_action, default_title, default_action_label, default_action_route, is_active, created_at, updated_at) VALUES
(UUID(), 'rq_mina_enviado', 'rq_mina', 'operacion', 'high', 'rq_proserge', 'ver', 'RQ Mina enviado', 'Ver RQ Mina', '/rq-mina/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'rq_proserge_parcial', 'rq_proserge', 'operacion', 'high', 'rq_proserge', 'editar', 'RQ Proserge parcialmente atendido', 'Ver RQ Proserge', '/rq-proserge/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'rq_proserge_completado', 'rq_proserge', 'operacion', 'medium', 'rq_proserge', 'ver', 'RQ Proserge completado', 'Ver RQ Proserge', '/rq-proserge/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'grupo_dia_pendiente', 'man_power', 'operacion', 'high', 'man_power', 'crear', 'Grupo de dia pendiente', 'Crear grupo', '/man-power/grupos/crear', 1, NOW(), NOW()),
(UUID(), 'grupo_noche_pendiente', 'man_power', 'operacion', 'high', 'man_power', 'crear', 'Grupo de noche pendiente', 'Crear grupo', '/man-power/grupos/crear', 1, NOW(), NOW()),
(UUID(), 'grupo_sin_supervisor', 'man_power', 'advertencia', 'high', 'man_power', 'editar', 'Grupo sin supervisor', 'Revisar grupo', '/man-power/grupos/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'grupo_sin_cerrar', 'man_power', 'advertencia', 'high', 'man_power', 'cerrar', 'Grupo sin cerrar', 'Cerrar asistencia', '/asistencia/grupos/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'asistencia_pendiente_marcar', 'asistencias', 'accion_requerida', 'high', 'asistencias', 'crear', 'Asistencia pendiente de marcar', 'Marcar asistencia', '/asistencia/grupos/{entity_id}/marcar', 1, NOW(), NOW()),
(UUID(), 'asistencia_pendiente_cerrar', 'asistencias', 'accion_requerida', 'high', 'asistencias', 'cerrar', 'Asistencia pendiente de cerrar', 'Cerrar asistencia', '/asistencia/grupos/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'faltas_generadas', 'faltas', 'advertencia', 'medium', 'faltas', 'ver', 'Se generaron faltas', 'Ver faltas', '/faltas', 1, NOW(), NOW()),
(UUID(), 'import_personal_error', 'personal', 'sistema', 'critical', 'personal', 'importar', 'Error en importacion de personal', 'Revisar importacion', '/personal/importar', 1, NOW(), NOW()),
(UUID(), 'personal_datos_incompletos', 'personal', 'advertencia', 'medium', 'personal', 'editar', 'Personal con datos incompletos', 'Ver personal', '/personal', 1, NOW(), NOW()),
(UUID(), 'personal_bloqueado_bienestar', 'bienestar', 'operacion', 'high', 'bienestar', 'ver', 'Personal bloqueado por bienestar', 'Ver bienestar', '/bienestar/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'personal_no_disponible', 'personal', 'operacion', 'high', 'personal', 'ver', 'Personal no disponible para parada', 'Ver personal', '/personal', 1, NOW(), NOW()),
(UUID(), 'rol_modificado', 'roles', 'seguridad', 'critical', 'roles', 'administrar', 'Rol modificado', 'Ver rol', '/seguridad/roles/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'permisos_modificados', 'roles', 'seguridad', 'critical', 'roles', 'administrar', 'Permisos modificados', 'Ver roles', '/seguridad/roles', 1, NOW(), NOW()),
(UUID(), 'usuario_creado', 'usuarios', 'seguridad', 'high', 'usuarios', 'crear', 'Nuevo usuario creado', 'Ver usuario', '/usuarios/{entity_id}', 1, NOW(), NOW()),
(UUID(), 'usuario_desactivado', 'usuarios', 'seguridad', 'high', 'usuarios', 'administrar', 'Usuario desactivado', 'Ver usuario', '/usuarios/{entity_id}', 1, NOW(), NOW())
ON DUPLICATE KEY UPDATE
  module = VALUES(module),
  category = VALUES(category),
  default_priority = VALUES(default_priority),
  required_permission_module = VALUES(required_permission_module),
  required_permission_action = VALUES(required_permission_action),
  default_title = VALUES(default_title),
  default_action_label = VALUES(default_action_label),
  default_action_route = VALUES(default_action_route),
  is_active = VALUES(is_active),
  updated_at = NOW();
