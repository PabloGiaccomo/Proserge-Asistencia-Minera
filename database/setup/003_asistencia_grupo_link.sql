ALTER TABLE asistencia_encabezado
  ADD COLUMN IF NOT EXISTS grupo_trabajo_id CHAR(36) NULL AFTER id,
  ADD UNIQUE KEY IF NOT EXISTS uq_asistencia_grupo (grupo_trabajo_id),
  ADD CONSTRAINT fk_asistencia_encabezado_grupo
    FOREIGN KEY (grupo_trabajo_id) REFERENCES grupo_trabajo(id) ON DELETE CASCADE;
