ALTER TABLE faltas
  ADD COLUMN IF NOT EXISTS estado VARCHAR(20) NOT NULL DEFAULT 'ACTIVA' AFTER observaciones,
  ADD COLUMN IF NOT EXISTS motivo_correccion TEXT NULL AFTER estado,
  ADD COLUMN IF NOT EXISTS motivo_anulacion TEXT NULL AFTER motivo_correccion,
  ADD COLUMN IF NOT EXISTS corregido_por_usuario_id CHAR(36) NULL AFTER motivo_anulacion,
  ADD COLUMN IF NOT EXISTS anulado_por_usuario_id CHAR(36) NULL AFTER corregido_por_usuario_id,
  ADD COLUMN IF NOT EXISTS corregido_at DATETIME NULL AFTER anulado_por_usuario_id,
  ADD COLUMN IF NOT EXISTS anulado_at DATETIME NULL AFTER corregido_at,
  ADD KEY IF NOT EXISTS idx_faltas_estado (estado);
