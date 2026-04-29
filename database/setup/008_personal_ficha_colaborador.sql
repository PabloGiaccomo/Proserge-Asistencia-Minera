SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE personal ADD COLUMN IF NOT EXISTS tipo_documento VARCHAR(40) NULL AFTER dni;
ALTER TABLE personal ADD COLUMN IF NOT EXISTS numero_documento VARCHAR(40) NULL AFTER tipo_documento;
ALTER TABLE personal MODIFY estado VARCHAR(40) NOT NULL DEFAULT 'ACTIVO';

UPDATE personal
SET tipo_documento = 'DNI'
WHERE tipo_documento IS NULL;

UPDATE personal
SET numero_documento = dni
WHERE numero_documento IS NULL;

CREATE TABLE IF NOT EXISTS personal_fichas (
  id CHAR(36) NOT NULL,
  personal_id CHAR(36) NOT NULL,
  estado VARCHAR(40) NOT NULL DEFAULT 'PENDIENTE_COMPLETAR_FICHA',
  tipo_documento VARCHAR(40) NOT NULL,
  numero_documento VARCHAR(40) NOT NULL,
  macro_tipo_contrato VARCHAR(80) NULL,
  macro_original_nombre VARCHAR(191) NULL,
  macro_original_path VARCHAR(500) NULL,
  datos_detectados_json JSON NULL,
  datos_json JSON NULL,
  campos_verificacion_json JSON NULL,
  advertencias_json JSON NULL,
  firma_base64 LONGTEXT NULL,
  huella_path VARCHAR(500) NULL,
  created_by_usuario_id CHAR(36) NULL,
  submitted_at TIMESTAMP NULL,
  approved_at TIMESTAMP NULL,
  approved_by_usuario_id CHAR(36) NULL,
  observed_at TIMESTAMP NULL,
  rejected_at TIMESTAMP NULL,
  observaciones_revision TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_personal_fichas_personal_estado (personal_id, estado),
  KEY idx_personal_fichas_documento (tipo_documento, numero_documento),
  KEY idx_personal_fichas_estado (estado),
  CONSTRAINT fk_personal_fichas_personal FOREIGN KEY (personal_id) REFERENCES personal(id) ON DELETE CASCADE,
  CONSTRAINT fk_personal_fichas_creador FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_personal_fichas_aprobador FOREIGN KEY (approved_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_ficha_links (
  id CHAR(36) NOT NULL,
  personal_ficha_id CHAR(36) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'ACTIVO',
  expires_at TIMESTAMP NOT NULL,
  read_until TIMESTAMP NULL,
  submitted_at TIMESTAMP NULL,
  disabled_at TIMESTAMP NULL,
  last_accessed_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ficha_links_token_hash (token_hash),
  KEY idx_ficha_links_ficha_estado (personal_ficha_id, estado),
  KEY idx_ficha_links_expires (expires_at),
  KEY idx_ficha_links_read_until (read_until),
  CONSTRAINT fk_ficha_links_ficha FOREIGN KEY (personal_ficha_id) REFERENCES personal_fichas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_ficha_familiares (
  id CHAR(36) NOT NULL,
  personal_ficha_id CHAR(36) NOT NULL,
  nombres_apellidos VARCHAR(191) NOT NULL,
  parentesco VARCHAR(80) NULL,
  tipo_documento VARCHAR(40) NULL,
  numero_documento VARCHAR(40) NULL,
  telefono VARCHAR(30) NULL,
  vive_con_trabajador TINYINT(1) NOT NULL DEFAULT 0,
  contacto_emergencia TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ficha_familiares_ficha (personal_ficha_id),
  CONSTRAINT fk_ficha_familiares_ficha FOREIGN KEY (personal_ficha_id) REFERENCES personal_fichas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS personal_ficha_archivos (
  id CHAR(36) NOT NULL,
  personal_ficha_id CHAR(36) NOT NULL,
  tipo VARCHAR(40) NOT NULL,
  nombre_original VARCHAR(191) NULL,
  path VARCHAR(500) NOT NULL,
  mime VARCHAR(120) NULL,
  size BIGINT UNSIGNED NULL,
  uploaded_by_usuario_id CHAR(36) NULL,
  uploaded_by_public TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ficha_archivos_tipo (personal_ficha_id, tipo),
  CONSTRAINT fk_ficha_archivos_ficha FOREIGN KEY (personal_ficha_id) REFERENCES personal_fichas(id) ON DELETE CASCADE,
  CONSTRAINT fk_ficha_archivos_usuario FOREIGN KEY (uploaded_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_types (
  id, code, module, category, default_priority, required_permission_module,
  required_permission_action, default_title, default_action_label,
  default_action_route, is_active, created_at, updated_at
) VALUES (
  UUID(), 'personal_ficha_completada', 'personal', 'accion_requerida', 'high',
  'personal', 'editar', 'Ficha de colaborador completada', 'Revisar ficha',
  '/personal/fichas/{entity_id}/revisar', 1, NOW(), NOW()
) ON DUPLICATE KEY UPDATE
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
