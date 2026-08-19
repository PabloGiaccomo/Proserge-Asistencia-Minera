ALTER TABLE rq_proserge_detalle
  ADD COLUMN IF NOT EXISTS posicion_asignacion VARCHAR(20) NULL AFTER ultimo_turno_referencia,
  ADD COLUMN IF NOT EXISTS tipo_asignacion VARCHAR(20) NULL AFTER posicion_asignacion,
  ADD COLUMN IF NOT EXISTS puesto_asignado_snapshot VARCHAR(191) NULL AFTER puesto_asignado,
  ADD COLUMN IF NOT EXISTS estado_habilitacion_snapshot VARCHAR(40) NULL AFTER tipo_asignacion,
  ADD COLUMN IF NOT EXISTS disponibilidad_snapshot JSON NULL AFTER estado_habilitacion_snapshot,
  ADD COLUMN IF NOT EXISTS asignado_por_id CHAR(36) NULL AFTER disponibilidad_snapshot,
  ADD COLUMN IF NOT EXISTS asignado_at TIMESTAMP NULL AFTER asignado_por_id,
  ADD COLUMN IF NOT EXISTS actualizado_por_id CHAR(36) NULL AFTER asignado_at,
  ADD COLUMN IF NOT EXISTS reemplaza_a_id CHAR(36) NULL AFTER actualizado_por_id,
  ADD COLUMN IF NOT EXISTS retirado_por_id CHAR(36) NULL AFTER reemplaza_a_id,
  ADD COLUMN IF NOT EXISTS retirado_at TIMESTAMP NULL AFTER retirado_por_id,
  ADD COLUMN IF NOT EXISTS motivo_retiro TEXT NULL AFTER retirado_at;

CREATE INDEX idx_rq_proserge_detalle_unique_legacy
  ON rq_proserge_detalle (rq_proserge_id, rq_mina_detalle_id, personal_id, fecha_inicio, fecha_fin);

CREATE INDEX idx_rq_proserge_detalle_rq_estado
  ON rq_proserge_detalle (rq_proserge_id, estado);

CREATE INDEX idx_rq_proserge_detalle_posicion
  ON rq_proserge_detalle (rq_mina_detalle_id, posicion_asignacion);

CREATE INDEX idx_rq_proserge_detalle_tipo
  ON rq_proserge_detalle (rq_mina_detalle_id, tipo_asignacion);

CREATE INDEX idx_rq_proserge_detalle_personal_rango
  ON rq_proserge_detalle (personal_id, fecha_inicio, fecha_fin);

CREATE INDEX idx_rq_proserge_detalle_asignado_por
  ON rq_proserge_detalle (asignado_por_id);

CREATE INDEX idx_rq_proserge_detalle_reemplaza
  ON rq_proserge_detalle (reemplaza_a_id);

CREATE INDEX idx_rq_proserge_detalle_retirado_at
  ON rq_proserge_detalle (retirado_at);

DROP INDEX uq_rq_proserge_detalle ON rq_proserge_detalle;

ALTER TABLE rq_proserge_detalle
  ADD CONSTRAINT fk_rq_proserge_detalle_asignado_por
    FOREIGN KEY (asignado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rq_proserge_detalle_actualizado_por
    FOREIGN KEY (actualizado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rq_proserge_detalle_retirado_por
    FOREIGN KEY (retirado_por_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_rq_proserge_detalle_reemplaza
    FOREIGN KEY (reemplaza_a_id) REFERENCES rq_proserge_detalle(id) ON DELETE SET NULL;
