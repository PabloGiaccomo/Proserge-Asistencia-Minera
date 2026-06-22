CREATE TABLE IF NOT EXISTS parada_herramienta_listas (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  anio_iso SMALLINT UNSIGNED NOT NULL,
  semana_iso TINYINT UNSIGNED NOT NULL,
  fecha_limite_envio DATE NOT NULL,
  estado VARCHAR(20) NOT NULL DEFAULT 'BORRADOR',
  observaciones TEXT NULL,
  enviado_at TIMESTAMP NULL,
  created_by_usuario_id CHAR(36) NULL,
  updated_by_usuario_id CHAR(36) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parada_herramienta_lista_rq (rq_mina_id),
  KEY idx_parada_herr_lista_semana (anio_iso, semana_iso),
  KEY idx_parada_herr_lista_estado_limite (estado, fecha_limite_envio),
  CONSTRAINT fk_parada_herr_lista_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE,
  CONSTRAINT fk_parada_herr_lista_creador FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_parada_herr_lista_editor FOREIGN KEY (updated_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_herramienta_grupos (
  id CHAR(36) NOT NULL,
  lista_id CHAR(36) NOT NULL,
  grupo_trabajo_id CHAR(36) NULL,
  nombre VARCHAR(191) NOT NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  observaciones TEXT NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_parada_herr_grupos_lista (lista_id),
  KEY idx_parada_herr_grupos_trabajo (grupo_trabajo_id),
  CONSTRAINT fk_parada_herr_grupos_lista FOREIGN KEY (lista_id) REFERENCES parada_herramienta_listas(id) ON DELETE CASCADE,
  CONSTRAINT fk_parada_herr_grupos_trabajo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_herramienta_items (
  id CHAR(36) NOT NULL,
  grupo_id CHAR(36) NOT NULL,
  tipo VARCHAR(20) NOT NULL DEFAULT 'BASE',
  categoria VARCHAR(20) NOT NULL DEFAULT 'HERRAMIENTA',
  descripcion VARCHAR(300) NOT NULL,
  cantidad_solicitada INT UNSIGNED NOT NULL DEFAULT 1,
  cantidad_entregada INT UNSIGNED NOT NULL DEFAULT 0,
  cantidad_recibida INT UNSIGNED NOT NULL DEFAULT 0,
  unidad VARCHAR(40) NULL,
  observaciones TEXT NULL,
  pedido_solicitado_at DATE NULL,
  pedido_llego_at DATE NULL,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_parada_herr_items_grupo_tipo (grupo_id, tipo),
  KEY idx_parada_herr_items_grupo_categoria_tipo (grupo_id, categoria, tipo),
  CONSTRAINT fk_parada_herr_items_grupo FOREIGN KEY (grupo_id) REFERENCES parada_herramienta_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_herramienta_catalogos (
  id CHAR(36) NOT NULL,
  categoria VARCHAR(20) NOT NULL DEFAULT 'HERRAMIENTA',
  descripcion VARCHAR(300) NOT NULL,
  descripcion_normalizada VARCHAR(320) NOT NULL,
  unidad VARCHAR(40) NULL,
  unidad_normalizada VARCHAR(40) NOT NULL DEFAULT '',
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_by_usuario_id CHAR(36) NULL,
  updated_by_usuario_id CHAR(36) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parada_herr_catalogo_desc (categoria, descripcion_normalizada, unidad_normalizada),
  KEY idx_parada_herr_catalogo_categoria (categoria, activo),
  KEY idx_parada_herr_catalogo_creador (created_by_usuario_id),
  KEY idx_parada_herr_catalogo_editor (updated_by_usuario_id),
  CONSTRAINT fk_parada_herr_catalogo_creador FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_parada_herr_catalogo_editor FOREIGN KEY (updated_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parada_herramienta_catalogo_observaciones (
  id CHAR(36) NOT NULL,
  catalogo_id CHAR(36) NOT NULL,
  observacion TEXT NOT NULL,
  observacion_normalizada VARCHAR(500) NOT NULL,
  observacion_hash CHAR(40) NOT NULL,
  usos INT UNSIGNED NOT NULL DEFAULT 1,
  last_used_at TIMESTAMP NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_parada_herr_catalogo_obs (catalogo_id, observacion_hash),
  KEY idx_parada_herr_catalogo_obs_usos (catalogo_id, usos),
  CONSTRAINT fk_parada_herr_catalogo_obs_catalogo FOREIGN KEY (catalogo_id) REFERENCES parada_herramienta_catalogos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notification_types (
  id, code, module, category, default_priority, required_permission_module,
  required_permission_action, default_title, default_action_label,
  default_action_route, is_active, created_at, updated_at
)
SELECT UUID(), 'lista_herramientas_por_vencer', 'man_power', 'accion_requerida', 'high', 'man_power',
       'ver', 'Lista de herramientas por vencer', 'Revisar lista',
       '/herramientas-parada/{entity_id}', 1, NOW(), NOW()
WHERE NOT EXISTS (
  SELECT 1 FROM notification_types WHERE code = 'lista_herramientas_por_vencer'
);
