-- MyInvoice.cz — Per-client číselné řady faktur
--
-- Před touto migrací byl varsymbol řízen jen per-supplier templatem (sloupce na
-- supplier.invoice_number_format / proforma_number_format / credit_note_number_format)
-- a counter `invoice_counters` byl scope-ovaný na (supplier_id, invoice_type, period).
--
-- Pro tenanty, kteří fakturují více klientům s historicky odlišnou číselnou řadou
-- (typicky převod z Fakturoidu, kde řada šla per klient), je tahle struktura málo.
-- Přidáváme:
--
--   * clients.{invoice|proforma|credit_note}_number_format — per-client template
--     override. NULL = fallback na supplier-level template (a dál na cfg).
--   * clients.invoice_number_period — per-client period override
--     ('year'|'month'|'none'). NULL = fallback na supplier.invoice_number_period.
--   * invoice_counters.client_id — counter scope. `0` znamená supplier-wide
--     (žádný per-client template, používáme supplier-level template + counter).
--
-- Idempotence: MariaDB-native `IF NOT EXISTS` / `IF EXISTS` guards. Re-run safe.

SET NAMES utf8mb4;

-- ── clients: per-client číselný formát ────────────────────────────────────
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS invoice_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro vydanou fakturu. NULL = dědit ze supplieru.'
    AFTER note;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS proforma_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro proformu. NULL = dědit ze supplieru.'
    AFTER invoice_number_format;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS credit_note_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-client template pro dobropis. NULL = dědit ze supplieru.'
    AFTER proforma_number_format;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS invoice_number_period ENUM('year','month','none') NULL DEFAULT NULL
    COMMENT 'Per-client období counteru. NULL = dědit ze supplieru.'
    AFTER credit_note_number_format;

-- ── invoice_counters: rozšířit scope o client_id ──────────────────────────
-- `0` (NOT NULL DEFAULT 0) = supplier-wide counter (existující řádky se tím
-- automaticky převedou). Per-client counter má client_id = clients.id.

ALTER TABLE invoice_counters
  ADD COLUMN IF NOT EXISTS client_id BIGINT UNSIGNED NOT NULL DEFAULT 0
    COMMENT '0 = supplier-wide counter, jinak clients.id pro per-client řadu.'
    AFTER supplier_id;

-- Drop původní PK (supplier_id, invoice_type, period) a nahraď ho rozšířeným.
-- MariaDB neumí "DROP PRIMARY KEY IF EXISTS", takže checkneme přes information_schema.
SET @has_old_pk := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'invoice_counters'
     AND INDEX_NAME   = 'PRIMARY'
     AND COLUMN_NAME  = 'supplier_id'
     AND SEQ_IN_INDEX = 1
     AND (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoice_counters' AND INDEX_NAME = 'PRIMARY') = 3
);
SET @sql := IF(@has_old_pk = 1,
  'ALTER TABLE invoice_counters DROP PRIMARY KEY, ADD PRIMARY KEY (supplier_id, client_id, invoice_type, period)',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
