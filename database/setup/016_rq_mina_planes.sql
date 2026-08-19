CREATE TABLE IF NOT EXISTS rq_mina_planes (
  id CHAR(36) NOT NULL,
  rq_mina_id CHAR(36) NOT NULL,
  codigo VARCHAR(40) NOT NULL,
  nombre VARCHAR(191) NOT NULL,
  version INT UNSIGNED NOT NULL DEFAULT 1,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  semana_referencia VARCHAR(80) NULL,
  estado VARCHAR(30) NOT NULL DEFAULT 'BORRADOR',
  observaciones TEXT NULL,
  created_by_usuario_id CHAR(36) NULL,
  updated_by_usuario_id CHAR(36) NULL,
  created_at TIMESTAMP NULL DEFAULT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rq_mina_planes_codigo_version (rq_mina_id, codigo, version),
  KEY idx_rq_mina_planes_rq (rq_mina_id),
  KEY idx_rq_mina_planes_rq_estado (rq_mina_id, estado),
  KEY idx_rq_mina_planes_rango (fecha_inicio, fecha_fin),
  CONSTRAINT fk_rq_mina_planes_rq FOREIGN KEY (rq_mina_id) REFERENCES rq_mina(id) ON DELETE CASCADE,
  CONSTRAINT fk_rq_mina_planes_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_rq_mina_planes_updated_by FOREIGN KEY (updated_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE rq_mina_actividad_grupos
  ADD COLUMN IF NOT EXISTS rq_mina_plan_id CHAR(36) NULL AFTER rq_mina_id;

CREATE INDEX idx_rq_mina_act_grupos_plan
  ON rq_mina_actividad_grupos (rq_mina_plan_id);

ALTER TABLE rq_mina_actividad_grupos
  ADD CONSTRAINT fk_rq_mina_act_grupos_plan
  FOREIGN KEY (rq_mina_plan_id) REFERENCES rq_mina_planes(id) ON DELETE SET NULL;
