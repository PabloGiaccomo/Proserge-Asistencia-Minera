ALTER TABLE personal_ficha_links
  ADD COLUMN IF NOT EXISTS token_encrypted TEXT NULL AFTER token_hash;

ALTER TABLE personal_ficha_links
  MODIFY expires_at DATETIME NOT NULL,
  MODIFY read_until DATETIME NULL,
  MODIFY submitted_at DATETIME NULL,
  MODIFY disabled_at DATETIME NULL,
  MODIFY last_accessed_at DATETIME NULL;
