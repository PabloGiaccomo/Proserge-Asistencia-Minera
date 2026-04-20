ALTER TABLE evaluacion_desempeno
  ADD COLUMN IF NOT EXISTS grupo_trabajo_id CHAR(36) NULL AFTER mina_id,
  ADD COLUMN IF NOT EXISTS asistencia_encabezado_id CHAR(36) NULL AFTER asistencia_detalle_id,
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER asistencia_encabezado_id,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo,
  ADD COLUMN IF NOT EXISTS evaluado_por_usuario_id CHAR(36) NULL AFTER destino_id,
  ADD KEY IF NOT EXISTS idx_eval_des_grupo (grupo_trabajo_id),
  ADD KEY IF NOT EXISTS idx_eval_des_destino (destino_tipo, destino_id),
  ADD KEY IF NOT EXISTS idx_eval_des_origen (asistencia_encabezado_id, asistencia_detalle_id),
  ADD CONSTRAINT fk_eval_des_grupo FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id),
  ADD CONSTRAINT fk_eval_des_asistencia_enc FOREIGN KEY (asistencia_encabezado_id) REFERENCES asistencia_encabezado(id),
  ADD CONSTRAINT fk_eval_des_usuario FOREIGN KEY (evaluado_por_usuario_id) REFERENCES usuarios(id);

ALTER TABLE evaluacion_supervisor
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER mina_id,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo,
  ADD KEY IF NOT EXISTS idx_eval_sup_destino (destino_tipo, destino_id);

ALTER TABLE evaluacion_residente
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER fecha,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo,
  ADD KEY IF NOT EXISTS idx_eval_res_destino (destino_tipo, destino_id);
