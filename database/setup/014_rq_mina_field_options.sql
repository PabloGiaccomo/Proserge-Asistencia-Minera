CREATE TABLE IF NOT EXISTS rq_mina_field_options (
  id CHAR(36) NOT NULL,
  field_key VARCHAR(120) NOT NULL,
  value TEXT NOT NULL,
  value_normalized VARCHAR(191) NOT NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 1,
  created_by_usuario_id CHAR(36) NULL,
  created_at TIMESTAMP NULL,
  updated_at TIMESTAMP NULL,
  PRIMARY KEY (id),
  UNIQUE KEY rq_mina_field_options_unique_value (field_key, value_normalized),
  KEY rq_mina_field_options_field_usage (field_key, usage_count),
  KEY rq_mina_field_options_usuario_fk (created_by_usuario_id),
  CONSTRAINT rq_mina_field_options_usuario_fk FOREIGN KEY (created_by_usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
