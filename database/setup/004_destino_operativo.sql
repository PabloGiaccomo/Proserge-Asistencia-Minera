ALTER TABLE grupo_trabajo
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER unidad,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo;

ALTER TABLE asistencia_encabezado
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER mina_id,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo;

ALTER TABLE faltas
  ADD COLUMN IF NOT EXISTS asistencia_encabezado_id CHAR(36) NULL AFTER registrada_por_id,
  ADD COLUMN IF NOT EXISTS asistencia_detalle_id CHAR(36) NULL AFTER asistencia_encabezado_id,
  ADD COLUMN IF NOT EXISTS destino_tipo VARCHAR(20) NULL AFTER asistencia_detalle_id,
  ADD COLUMN IF NOT EXISTS destino_id CHAR(36) NULL AFTER destino_tipo;
