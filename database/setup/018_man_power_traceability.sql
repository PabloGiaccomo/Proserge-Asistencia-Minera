ALTER TABLE grupo_trabajo
  ADD COLUMN IF NOT EXISTS rq_mina_plan_id CHAR(36) NULL AFTER rq_mina_id,
  ADD COLUMN IF NOT EXISTS rq_mina_actividad_grupo_id CHAR(36) NULL AFTER rq_mina_plan_id,
  ADD COLUMN IF NOT EXISTS codigo_grupo VARCHAR(80) NULL AFTER rq_mina_actividad_grupo_id,
  ADD COLUMN IF NOT EXISTS nombre_snapshot VARCHAR(191) NULL AFTER codigo_grupo,
  ADD COLUMN IF NOT EXISTS area_snapshot VARCHAR(191) NULL AFTER nombre_snapshot,
  ADD COLUMN IF NOT EXISTS sector_snapshot VARCHAR(191) NULL AFTER area_snapshot,
  ADD COLUMN IF NOT EXISTS modulo_snapshot VARCHAR(191) NULL AFTER sector_snapshot,
  ADD COLUMN IF NOT EXISTS sait_snapshot TEXT NULL AFTER modulo_snapshot,
  ADD COLUMN IF NOT EXISTS supervisor_operativo_snapshot VARCHAR(191) NULL AFTER sait_snapshot,
  ADD COLUMN IF NOT EXISTS supervisor_seguridad_snapshot VARCHAR(191) NULL AFTER supervisor_operativo_snapshot,
  ADD COLUMN IF NOT EXISTS cantidad_planificada_snapshot INT UNSIGNED NULL AFTER supervisor_seguridad_snapshot,
  ADD COLUMN IF NOT EXISTS observacion_planificacion TEXT NULL AFTER observaciones,
  ADD COLUMN IF NOT EXISTS justificacion_brecha TEXT NULL AFTER observacion_planificacion,
  ADD COLUMN IF NOT EXISTS updated_by_id CHAR(36) NULL AFTER created_by_id;

CREATE INDEX IF NOT EXISTS idx_grupo_trabajo_rq_fecha_turno
  ON grupo_trabajo (rq_mina_id, fecha, turno);
CREATE INDEX IF NOT EXISTS idx_grupo_trabajo_plan_fecha_turno
  ON grupo_trabajo (rq_mina_plan_id, fecha, turno);
CREATE INDEX IF NOT EXISTS idx_grupo_trabajo_act_grupo_fecha_turno
  ON grupo_trabajo (rq_mina_actividad_grupo_id, fecha, turno);
CREATE INDEX IF NOT EXISTS idx_grupo_trabajo_rp_fecha_turno
  ON grupo_trabajo (rq_proserge_id, fecha, turno);

CREATE TABLE IF NOT EXISTS grupo_trabajo_actividades (
  id CHAR(36) NOT NULL,
  grupo_trabajo_id CHAR(36) NOT NULL,
  rq_mina_actividad_id CHAR(36) NOT NULL,
  cantidad_planificada_snapshot INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grupo_trabajo_actividad (grupo_trabajo_id, rq_mina_actividad_id),
  KEY idx_grupo_trabajo_actividades_actividad (rq_mina_actividad_id),
  CONSTRAINT fk_grupo_trabajo_actividades_grupo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id) ON DELETE CASCADE,
  CONSTRAINT fk_grupo_trabajo_actividades_actividad FOREIGN KEY (rq_mina_actividad_id) REFERENCES rq_mina_actividades(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE grupo_trabajo_detalle
  ADD COLUMN IF NOT EXISTS rq_proserge_detalle_id CHAR(36) NULL AFTER personal_id,
  ADD COLUMN IF NOT EXISTS puesto_asignado_snapshot VARCHAR(191) NULL AFTER rq_proserge_detalle_id,
  ADD COLUMN IF NOT EXISTS posicion_asignacion_snapshot VARCHAR(20) NULL AFTER puesto_asignado_snapshot,
  ADD COLUMN IF NOT EXISTS tipo_asignacion_snapshot VARCHAR(20) NULL AFTER posicion_asignacion_snapshot,
  ADD COLUMN IF NOT EXISTS estado_habilitacion_snapshot VARCHAR(40) NULL AFTER tipo_asignacion_snapshot,
  ADD COLUMN IF NOT EXISTS estado_distribucion VARCHAR(20) NOT NULL DEFAULT 'ASIGNADO' AFTER estado_habilitacion_snapshot,
  ADD COLUMN IF NOT EXISTS asignado_por_id CHAR(36) NULL AFTER estado_distribucion,
  ADD COLUMN IF NOT EXISTS asignado_at TIMESTAMP NULL AFTER asignado_por_id,
  ADD COLUMN IF NOT EXISTS retirado_por_id CHAR(36) NULL AFTER asignado_at,
  ADD COLUMN IF NOT EXISTS retirado_at TIMESTAMP NULL AFTER retirado_por_id,
  ADD COLUMN IF NOT EXISTS motivo_retiro TEXT NULL AFTER retirado_at,
  ADD COLUMN IF NOT EXISTS observacion TEXT NULL AFTER motivo_retiro;

CREATE INDEX IF NOT EXISTS idx_grupo_detalle_rq_proserge_detalle
  ON grupo_trabajo_detalle (rq_proserge_detalle_id);
CREATE INDEX IF NOT EXISTS idx_grupo_detalle_grupo_estado_dist
  ON grupo_trabajo_detalle (grupo_trabajo_id, estado_distribucion);
CREATE INDEX IF NOT EXISTS idx_grupo_detalle_asignado_at
  ON grupo_trabajo_detalle (asignado_at);
CREATE INDEX IF NOT EXISTS idx_grupo_detalle_retirado_at
  ON grupo_trabajo_detalle (retirado_at);
