CREATE TABLE IF NOT EXISTS rq_mina_actividad_grupos (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  area_operativa VARCHAR(80) NULL,
  modulo VARCHAR(80) NULL,
  nombre VARCHAR(191) NOT NULL,
  observaciones TEXT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_rq_mina_act_grupos_rq (rq_mina_id),
  CONSTRAINT fk_rq_mina_act_grupos_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_mina_actividades (
  id CHAR(36) NOT NULL,
  grupo_id CHAR(36) NOT NULL,
  sait VARCHAR(191) NULL,
  sector VARCHAR(191) NULL,
  area VARCHAR(191) NULL,
  ait_trabajo TEXT NULL,
  detalle_trabajos_relevantes TEXT NULL,
  supervisor_campo_dia VARCHAR(191) NULL,
  supervisor_campo_noche VARCHAR(191) NULL,
  supervisor_seguridad_dia VARCHAR(191) NULL,
  supervisor_seguridad_noche VARCHAR(191) NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_rq_mina_actividades_grupo (grupo_id),
  CONSTRAINT fk_rq_mina_actividades_grupo FOREIGN KEY (grupo_id) REFERENCES rq_mina_actividad_grupos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_mina_actividad_turnos (
  id CHAR(36) NOT NULL,
  actividad_id CHAR(36) NOT NULL,
  fecha DATE NULL,
  dia_label VARCHAR(40) NULL,
  turno_a VARCHAR(191) NULL,
  real_turno_a VARCHAR(191) NULL,
  turno_b VARCHAR(191) NULL,
  real_turno_b VARCHAR(191) NULL,
  real VARCHAR(191) NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_rq_mina_act_turnos_actividad (actividad_id),
  KEY idx_rq_mina_act_turnos_fecha (fecha),
  CONSTRAINT fk_rq_mina_act_turnos_actividad FOREIGN KEY (actividad_id) REFERENCES rq_mina_actividades(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rq_mina_actividad_transportes (
  id CHAR(36) NOT NULL,
  grupo_id CHAR(36) NOT NULL,
  actividad_id CHAR(36) NULL,
  alcance VARCHAR(191) NULL,
  unidad_carga VARCHAR(191) NULL,
  unidades_transporte TEXT NULL,
  indicaciones TEXT NULL,
  orden INT UNSIGNED NOT NULL DEFAULT 1,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  KEY idx_rq_mina_act_transportes_grupo (grupo_id),
  KEY idx_rq_mina_act_transportes_actividad (actividad_id),
  CONSTRAINT fk_rq_mina_act_transportes_grupo FOREIGN KEY (grupo_id) REFERENCES rq_mina_actividad_grupos(id) ON DELETE CASCADE,
  CONSTRAINT fk_rq_mina_act_transportes_actividad FOREIGN KEY (actividad_id) REFERENCES rq_mina_actividades(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
