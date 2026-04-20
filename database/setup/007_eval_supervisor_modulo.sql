ALTER TABLE evaluacion_supervisor
  MODIFY COLUMN mina_id CHAR(36) NULL,
  ADD COLUMN IF NOT EXISTS grupo_trabajo_id CHAR(36) NULL AFTER mina_id,
  ADD COLUMN IF NOT EXISTS asistencia_encabezado_id CHAR(36) NULL AFTER grupo_trabajo_id,
  ADD COLUMN IF NOT EXISTS estado VARCHAR(20) NOT NULL DEFAULT 'REGISTRADA' AFTER respuestas,
  ADD COLUMN IF NOT EXISTS created_by_usuario_id CHAR(36) NULL AFTER estado,
  ADD COLUMN IF NOT EXISTS updated_by_usuario_id CHAR(36) NULL AFTER created_by_usuario_id,
  ADD UNIQUE KEY IF NOT EXISTS uq_eval_supervisor_contexto (evaluado_id, grupo_trabajo_id, fecha),
  ADD KEY IF NOT EXISTS idx_eval_sup_grupo (grupo_trabajo_id),
  ADD KEY IF NOT EXISTS idx_eval_sup_asistencia (asistencia_encabezado_id),
  ADD CONSTRAINT fk_eval_sup_grupo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id),
  ADD CONSTRAINT fk_eval_sup_asistencia FOREIGN KEY (asistencia_encabezado_id) REFERENCES asistencia_encabezado(id),
  ADD CONSTRAINT fk_eval_sup_created_by FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id),
  ADD CONSTRAINT fk_eval_sup_updated_by FOREIGN KEY (updated_by_usuario_id) REFERENCES usuarios(id);
