-- MyInvoice.cz — HTTP Basic autentizace k TSA serveru (PAdES-T)
--
-- Produkční / kvalifikované TSA (PostSignum, komerční) vyžadují přihlášení.
-- Jméno se ukládá v plaintextu, heslo šifrované (SecretEncryption, enc:v1:).
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS signing_tsa_username VARCHAR(190) NULL DEFAULT NULL
    COMMENT 'Uživatelské jméno pro HTTP Basic auth k TSA serveru (PAdES-T).' AFTER signing_tsa_url,
  ADD COLUMN IF NOT EXISTS signing_tsa_password_enc VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Heslo k TSA serveru, šifrované SecretEncryption (enc:v1:...).' AFTER signing_tsa_username;
