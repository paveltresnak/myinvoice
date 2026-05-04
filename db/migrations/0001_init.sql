-- MyInvoice.cz — initial schema (konsolidované 0001-0006)
-- Spec: source/02-database.md
-- Kompatibilní s MariaDB 10.6+ (VARBINARY(16) pro IP místo INET6 z 10.10).

SET NAMES utf8mb4;
SET sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
-- 1. Číselníky (countries, currencies, vat_rates)
-- ==========================================================================

CREATE TABLE countries (
  id        SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  iso2      CHAR(2) NOT NULL,
  iso3      CHAR(3) NOT NULL,
  name_cs   VARCHAR(120) NOT NULL,
  name_en   VARCHAR(120) NOT NULL,
  is_eu     TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_country_iso2 (iso2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Currencies = podporované měny + dodavatelovo bankovní spojení pro každou (single-tenant, 1:1).
-- Pro CZK se vyplňuje account_number + bank_code + bank_name.
-- Pro EUR / non-CZK se vyplňuje iban + bic + bank_name.
CREATE TABLE currencies (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     TINYINT UNSIGNED NOT NULL,               -- multi-tenant: každá řádka patří jednomu supplierovi
  code            CHAR(3) NOT NULL,                        -- ISO 4217 (CZK, EUR, USD)
  label           VARCHAR(60) NOT NULL,                    -- "CZK — Fio Bank" — víc účtů per code OK
  symbol          VARCHAR(8) NOT NULL,
  name_cs         VARCHAR(60) NOT NULL,
  name_en         VARCHAR(60) NOT NULL,
  decimals        TINYINT UNSIGNED NOT NULL DEFAULT 2,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,           -- vypnout měnu = nelze pro nové faktury
  is_default      TINYINT(1) NOT NULL DEFAULT 0,           -- výchozí účet pro (supplier, code) (app-level)

  -- Supplier bank account info (1 účet per řádek)
  account_number  VARCHAR(30) NULL,
  bank_code       CHAR(4) NULL,
  bank_name       VARCHAR(120) NULL,
  iban            VARCHAR(34) NULL,
  bic             VARCHAR(11) NULL,

  KEY idx_currencies_code (code),
  KEY idx_currencies_active (is_active),
  KEY idx_currencies_supplier (supplier_id)
  -- FK na supplier je přidaný DOLE (cyklický odkaz: supplier.default_currency_id → currencies.id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vat_rates (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code               VARCHAR(20) NOT NULL,
  rate_percent       DECIMAL(5,2) NOT NULL,
  country            CHAR(2) NOT NULL DEFAULT 'CZ',
  label_cs           VARCHAR(60) NOT NULL,
  label_en           VARCHAR(60) NOT NULL,
  is_default         TINYINT(1) NOT NULL DEFAULT 0,
  is_reverse_charge  TINYINT(1) NOT NULL DEFAULT 0,
  valid_from         DATE NOT NULL,
  valid_to           DATE NULL,
  display_order      INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_vat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 2. Users + auth
-- ==========================================================================

CREATE TABLE users (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL,
  password_hash   CHAR(60) NOT NULL,
  totp_secret     VARCHAR(255) NULL,           -- base32 secret pro TOTP (RFC 6238) — šifrovaný AES-256-GCM (~87 znaků s prefixem enc:v1:)
  totp_enabled    TINYINT(1) NOT NULL DEFAULT 0, -- 2FA aktivní (set až po ověření prvního kódu)
  name            VARCHAR(120) NOT NULL,
  role            ENUM('admin','accountant','readonly') NOT NULL DEFAULT 'admin',
  locale          ENUM('cs','en') NOT NULL DEFAULT 'cs',
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at   TIMESTAMP NULL,
  last_login_ip   VARBINARY(16) NULL,        -- packed IPv4 (4B) nebo IPv6 (16B), inet_pton/inet_ntop
  last_login_ua   VARCHAR(255) NULL,         -- User-Agent posledního loginu (audit)
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sessions (
  id          CHAR(64) PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  csrf_token  CHAR(64) NOT NULL,
  ip          VARBINARY(16) NOT NULL,    -- packed IPv4 (4B) nebo IPv6 (16B)
  user_agent  VARCHAR(255) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  KEY idx_sess_user (user_id, expires_at),
  CONSTRAINT fk_sess_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_resets (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  expires_at  TIMESTAMP NOT NULL,
  used_at     TIMESTAMP NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip          VARBINARY(16) NOT NULL,    -- packed IPv4 (4B) nebo IPv6 (16B)
  KEY idx_reset_token (token_hash),
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- MEMORY engine — brute-force buckets, levné random access bez I/O
CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket_key  VARCHAR(80) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  ip_packed   VARBINARY(16) NOT NULL,                  -- inet_pton() v PHP
  success     TINYINT(1) NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_la_bucket (bucket_key, created_at),
  KEY idx_la_email (email, created_at)
) ENGINE=MEMORY DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 3. Supplier (multi-tenant, id AUTO_INCREMENT)
-- ==========================================================================

CREATE TABLE supplier (
  id                       TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  company_name             VARCHAR(190) NOT NULL,
  display_name             VARCHAR(190) NULL,
  street                   VARCHAR(190) NOT NULL,
  city                     VARCHAR(120) NOT NULL,
  zip                      VARCHAR(10) NOT NULL,
  country_id               SMALLINT UNSIGNED NOT NULL,
  ic                       VARCHAR(10) NULL,
  dic                      VARCHAR(20) NULL,
  is_vat_payer             TINYINT(1) NOT NULL DEFAULT 1,
  email                    VARCHAR(190) NOT NULL,
  phone                    VARCHAR(40) NULL,
  web                      VARCHAR(190) NULL,
  tagline                  VARCHAR(190) NULL,                  -- email patička, např. "programujeme cokoliv…"
  default_currency_id      INT UNSIGNED NOT NULL,
  default_vat_rate_id      INT UNSIGNED NOT NULL,
  default_payment_due_days INT UNSIGNED NOT NULL DEFAULT 7,
  default_hourly_rate      DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
  auto_send_reminders      TINYINT(1) NOT NULL DEFAULT 1,         -- cron-send-reminders přeskočí supplier=0; ruční pořád jdou
  logo_path                VARCHAR(255) NULL,
  signature_path           VARCHAR(255) NULL,
  -- Pohoda XML export — kódy pro číselníky (NULL = nepoužívat)
  pohoda_account_code      VARCHAR(20) NULL,
  pohoda_centre_code       VARCHAR(20) NULL,
  pohoda_activity_code     VARCHAR(20) NULL,
  pohoda_contract_code     VARCHAR(20) NULL,
  created_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_sup_country  FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_sup_vat      FOREIGN KEY (default_vat_rate_id) REFERENCES vat_rates(id),
  CONSTRAINT fk_sup_currency FOREIGN KEY (default_currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 4. Clients + projects
-- ==========================================================================

CREATE TABLE clients (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          TINYINT UNSIGNED NOT NULL,
  company_name         VARCHAR(190) NOT NULL,
  first_name           VARCHAR(60) NULL,
  last_name            VARCHAR(60) NULL,
  ic                   VARCHAR(10) NULL,
  dic                  VARCHAR(20) NULL,
  street               VARCHAR(190) NOT NULL,
  city                 VARCHAR(120) NOT NULL,
  zip                  VARCHAR(10) NOT NULL,
  country_id           SMALLINT UNSIGNED NOT NULL,
  main_email           VARCHAR(190) NOT NULL,
  phone                VARCHAR(40) NULL,
  language             ENUM('cs','en') NOT NULL DEFAULT 'cs',
  currency_default_id  INT UNSIGNED NOT NULL,
  vat_rate_default_id  INT UNSIGNED NULL,
  reverse_charge       TINYINT(1) NOT NULL DEFAULT 0,
  auto_send_reminders  TINYINT(1) NOT NULL DEFAULT 1,             -- cron-send-reminders přeskočí klienta=0; ruční pořád jdou
  payment_due_default  INT UNSIGNED NULL,
  note                 TEXT NULL,
  archived_at          TIMESTAMP NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_clients_company  (company_name),
  KEY idx_clients_ic       (ic),
  KEY idx_clients_archived (archived_at),
  KEY idx_clients_supplier (supplier_id),
  CONSTRAINT fk_cli_country  FOREIGN KEY (country_id) REFERENCES countries(id),
  CONSTRAINT fk_cli_vat      FOREIGN KEY (vat_rate_default_id) REFERENCES vat_rates(id),
  CONSTRAINT fk_cli_currency FOREIGN KEY (currency_default_id) REFERENCES currencies(id),
  CONSTRAINT fk_cli_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  client_id         BIGINT UNSIGNED NOT NULL,
  name              VARCHAR(190) NOT NULL,
  payment_due_days  INT UNSIGNED NOT NULL DEFAULT 7,
  project_number    VARCHAR(50) NULL,
  contract_number   VARCHAR(50) NULL,
  budget_total      DECIMAL(12,2) NULL,
  budget_yearly     DECIMAL(12,2) NULL,
  budget_monthly    DECIMAL(12,2) NULL,
  hourly_rate       DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
  currency_id       INT UNSIGNED NOT NULL,
  status            ENUM('active','paused','closed') NOT NULL DEFAULT 'active',
  note              TEXT NULL,
  archived_at       TIMESTAMP NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_proj_client (client_id, status),
  CONSTRAINT fk_proj_client   FOREIGN KEY (client_id)   REFERENCES clients(id) ON DELETE RESTRICT,
  CONSTRAINT fk_proj_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fakturační emaily per zakázka (0..3 emailů, slot 1/2/3).
CREATE TABLE project_billing_emails (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  position    TINYINT UNSIGNED NOT NULL,         -- 1, 2, 3
  label       VARCHAR(60) NULL,                  -- volitelný popisek "účetní", "PM"
  KEY idx_pbe_project (project_id),
  UNIQUE KEY uq_pbe_pos (project_id, position),
  CONSTRAINT fk_pbe_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT chk_pbe_pos CHECK (position BETWEEN 1 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 5. Invoices + items + work reports
-- ==========================================================================

CREATE TABLE invoices (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         TINYINT UNSIGNED NOT NULL,                -- multi-tenant denorm; varsymbol/counter scoped
  varsymbol           VARCHAR(20) NULL,
  invoice_type        ENUM('invoice','proforma','credit_note','cancellation') NOT NULL DEFAULT 'invoice',
  parent_invoice_id   BIGINT UNSIGNED NULL,
  client_id           BIGINT UNSIGNED NOT NULL,
  project_id          BIGINT UNSIGNED NULL,
  issue_date          DATE NOT NULL,
  tax_date            DATE NULL,
  due_date            DATE NOT NULL,
  currency_id         INT UNSIGNED NOT NULL,
  reverse_charge      TINYINT(1) NOT NULL DEFAULT 0,
  language            ENUM('cs','en') NOT NULL DEFAULT 'cs',
  note_above_items    TEXT NULL,
  note_below_items    TEXT NULL,
  advance_paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  -- Generated column (MariaDB 10.2+): vždy konzistentní, nelze nastavit ručně
  amount_to_pay       DECIMAL(12,2) AS (total_with_vat - advance_paid_amount) STORED,
  client_snapshot     JSON NULL,
  supplier_snapshot   JSON NULL,
  bank_snapshot       JSON NULL,
  total_without_vat   DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_vat           DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_with_vat      DECIMAL(12,2) NOT NULL DEFAULT 0,
  rounding            DECIMAL(6,2) NOT NULL DEFAULT 0,
  status              ENUM('draft','issued','sent','reminded','paid','cancelled') NOT NULL DEFAULT 'draft',
  sent_at             TIMESTAMP NULL,
  last_reminder_at    TIMESTAMP NULL DEFAULT NULL,
  reminder_count      INT UNSIGNED NOT NULL DEFAULT 0,
  paid_at             DATE NULL,
  cancelled_at        TIMESTAMP NULL,
  pdf_path            VARCHAR(255) NULL,
  pdf_generated_at    TIMESTAMP NULL,
  created_by          BIGINT UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_supplier_varsymbol (supplier_id, varsymbol),
  KEY idx_inv_client     (client_id, issue_date DESC),
  KEY idx_inv_project    (project_id, issue_date DESC),
  KEY idx_inv_status     (status, due_date),
  KEY idx_inv_type_month (invoice_type, issue_date),
  KEY idx_inv_parent     (parent_invoice_id),
  KEY idx_inv_reminders  (status, due_date, last_reminder_at),
  KEY idx_inv_supplier   (supplier_id),
  CONSTRAINT fk_inv_client   FOREIGN KEY (client_id)         REFERENCES clients(id),
  CONSTRAINT fk_inv_project  FOREIGN KEY (project_id)        REFERENCES projects(id),
  CONSTRAINT fk_inv_currency FOREIGN KEY (currency_id)       REFERENCES currencies(id),
  CONSTRAINT fk_inv_parent   FOREIGN KEY (parent_invoice_id) REFERENCES invoices(id),
  CONSTRAINT fk_inv_user     FOREIGN KEY (created_by)        REFERENCES users(id),
  CONSTRAINT fk_inv_supplier FOREIGN KEY (supplier_id)       REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_reports (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id   BIGINT UNSIGNED NOT NULL,
  project_id   BIGINT UNSIGNED NOT NULL,
  title        VARCHAR(190) NOT NULL,
  total_hours  DECIMAL(8,2) NOT NULL DEFAULT 0,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wr_invoice (invoice_id),
  CONSTRAINT fk_wr_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_wr_project FOREIGN KEY (project_id) REFERENCES projects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_items (
  id                       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  invoice_id               BIGINT UNSIGNED NOT NULL,
  description              TEXT NOT NULL,
  quantity                 DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit                     VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price_without_vat   DECIMAL(12,2) NOT NULL,
  vat_rate_id              INT UNSIGNED NOT NULL,
  vat_rate_snapshot        DECIMAL(5,2) NOT NULL,
  total_without_vat        DECIMAL(12,2) NOT NULL,
  total_vat                DECIMAL(12,2) NOT NULL,
  total_with_vat           DECIMAL(12,2) NOT NULL,
  order_index              INT NOT NULL DEFAULT 0,
  linked_work_report_id    BIGINT UNSIGNED NULL,
  KEY idx_ii_invoice (invoice_id, order_index),
  CONSTRAINT fk_ii_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
  CONSTRAINT fk_ii_vat     FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id),
  CONSTRAINT fk_ii_wr      FOREIGN KEY (linked_work_report_id) REFERENCES work_reports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE work_report_items (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_report_id  BIGINT UNSIGNED NOT NULL,
  description     TEXT NOT NULL,
  work_date       DATE NULL,
  hours           DECIMAL(6,2) NOT NULL,
  rate            DECIMAL(10,2) NOT NULL,
  total_amount    DECIMAL(12,2) NOT NULL,
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_wri_wr (work_report_id, order_index),
  CONSTRAINT fk_wri_wr FOREIGN KEY (work_report_id) REFERENCES work_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE invoice_counters (
  supplier_id  TINYINT UNSIGNED NOT NULL,
  invoice_type ENUM('invoice','proforma','credit_note') NOT NULL,
  period       CHAR(6) NOT NULL,                -- "YYYYMM", např. "202604"
  last_number  INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (supplier_id, invoice_type, period),
  CONSTRAINT fk_ic_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 6. Activity log + caches
-- ==========================================================================

CREATE TABLE activity_log (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id  TINYINT UNSIGNED NULL,     -- multi-supplier scope (NULL = cross-cutting jako login/setup)
  user_id      BIGINT UNSIGNED NULL,
  action       VARCHAR(50) NOT NULL,
  entity_type  VARCHAR(40) NULL,
  entity_id    BIGINT UNSIGNED NULL,
  payload      JSON NULL,
  ip           VARBINARY(16) NULL,        -- packed IPv4 (4B) nebo IPv6 (16B)
  user_agent   VARCHAR(255) NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_al_user     (user_id, created_at),
  KEY idx_al_entity   (entity_type, entity_id, created_at),
  KEY idx_al_supplier (supplier_id, created_at),
  KEY idx_al_action (action, created_at),
  CONSTRAINT fk_al_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=COMPRESSED;

CREATE TABLE ares_cache (
  ic         VARCHAR(10) PRIMARY KEY,
  payload    JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vies_cache (
  vat_id     VARCHAR(20) PRIMARY KEY,
  is_valid   TINYINT(1) NOT NULL,
  payload    JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 7. Bank statements (M5b — GPC/ABO import + auto-matching)
-- ==========================================================================

CREATE TABLE bank_statements (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  file_name           VARCHAR(255) NOT NULL,
  file_hash           CHAR(64) NOT NULL,                  -- SHA256 pro dedupe
  account_number      VARCHAR(40) NOT NULL,
  bank_code           CHAR(4) NULL,
  currency            CHAR(3) NULL,
  statement_number    VARCHAR(20) NULL,
  statement_date      DATE NOT NULL,
  prev_balance        DECIMAL(14,2) NULL,
  curr_balance        DECIMAL(14,2) NULL,
  credit_total        DECIMAL(14,2) NULL,
  debit_total         DECIMAL(14,2) NULL,
  transaction_count   INT UNSIGNED NOT NULL DEFAULT 0,
  matched_count       INT UNSIGNED NOT NULL DEFAULT 0,
  imported_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  imported_by         BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_bs_hash (file_hash),
  KEY idx_bs_date (statement_date),
  CONSTRAINT fk_bs_user FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE bank_transactions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  statement_id      BIGINT UNSIGNED NOT NULL,
  posted_at         DATE NOT NULL,
  amount            DECIMAL(14,2) NOT NULL,             -- + příchozí, - odchozí
  currency          CHAR(3) NULL,
  variable_symbol   VARCHAR(20) NULL,
  constant_symbol   VARCHAR(10) NULL,
  specific_symbol   VARCHAR(20) NULL,
  counterparty_account VARCHAR(40) NULL,
  counterparty_bank    CHAR(4) NULL,
  counterparty_name    VARCHAR(190) NULL,
  description       VARCHAR(255) NULL,
  bank_ref          VARCHAR(40) NULL,
  matched_invoice_id BIGINT UNSIGNED NULL,
  match_status      ENUM('unmatched', 'auto_exact', 'auto_partial', 'manual', 'ignored') NOT NULL DEFAULT 'unmatched',
  matched_at        TIMESTAMP NULL,
  matched_by        BIGINT UNSIGNED NULL,
  KEY idx_bt_statement (statement_id),
  KEY idx_bt_vs (variable_symbol),
  KEY idx_bt_match (matched_invoice_id),
  KEY idx_bt_status (match_status, posted_at),
  CONSTRAINT fk_bt_statement FOREIGN KEY (statement_id) REFERENCES bank_statements(id) ON DELETE CASCADE,
  CONSTRAINT fk_bt_invoice FOREIGN KEY (matched_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_bt_user FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 7. Stats cache + email šablony
-- ==========================================================================

-- Per-currency cache obratu pro zakázky a klienty.
-- Místo korelovaných subqueries v listech: denormalizované hodnoty, přepočítávané
-- skrz Service/Stats/StatsRecomputer při create/update/issue/cancel/delete faktury.
CREATE TABLE project_revenue_cache (
  project_id        BIGINT UNSIGNED NOT NULL,
  currency_id       INT UNSIGNED NOT NULL,
  revenue           DECIMAL(14,2) NOT NULL DEFAULT 0,
  last_invoice_date DATE NULL,
  invoice_count     INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, currency_id),
  KEY idx_prc_revenue           (revenue),
  KEY idx_prc_last_invoice_date (last_invoice_date),
  CONSTRAINT fk_prc_project  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_prc_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE client_revenue_cache (
  client_id         BIGINT UNSIGNED NOT NULL,
  currency_id       INT UNSIGNED NOT NULL,
  revenue           DECIMAL(14,2) NOT NULL DEFAULT 0,
  last_invoice_date DATE NULL,
  invoice_count     INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (client_id, currency_id),
  KEY idx_crc_revenue           (revenue),
  KEY idx_crc_last_invoice_date (last_invoice_date),
  CONSTRAINT fk_crc_client   FOREIGN KEY (client_id)   REFERENCES clients(id) ON DELETE CASCADE,
  CONSTRAINT fk_crc_currency FOREIGN KEY (currency_id) REFERENCES currencies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email šablony (override file defaults v api/templates/email/)
CREATE TABLE email_templates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(64) NOT NULL,            -- 'invoice_send' | 'password_reset'
  locale      CHAR(2)     NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body_html   MEDIUMTEXT NOT NULL,
  body_text   MEDIUMTEXT NOT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  updated_by  BIGINT UNSIGNED NULL,
  UNIQUE KEY uq_code_locale (code, locale),
  CONSTRAINT fk_email_tpl_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
-- 8. Seed dat — countries, vat_rates
--    Currencies se NESEDUJÍ zde (vyžadují supplier_id), seedují se v setup.php
--    po vytvoření prvního supplier řádku.
-- ==========================================================================

-- Po vytvoření tabulek doplníme cyklický FK currencies → supplier (supplier ho má naopak)
ALTER TABLE currencies
  ADD CONSTRAINT fk_cur_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id);

INSERT INTO vat_rates (code, rate_percent, country, label_cs, label_en, is_default, is_reverse_charge, valid_from, display_order) VALUES
('CZ-21', 21.00, 'CZ', 'Základní 21 %',  'Standard 21 %',  1, 0, '2024-01-01', 10),
('CZ-12', 12.00, 'CZ', 'Snížená 12 %',   'Reduced 12 %',   0, 0, '2024-01-01', 20),
('CZ-0',   0.00, 'CZ', 'Osvobozeno',     'Exempt',         0, 0, '2024-01-01', 30),
('CZ-RC',  0.00, 'CZ', 'Reverse charge', 'Reverse charge', 0, 1, '2024-01-01', 40);

-- EU + okolí + nejčastější mimo-EU
INSERT INTO countries (iso2, iso3, name_cs, name_en, is_eu) VALUES
('CZ','CZE','Česká republika','Czech Republic',1),
('SK','SVK','Slovensko','Slovakia',1),
('AT','AUT','Rakousko','Austria',1),
('DE','DEU','Německo','Germany',1),
('PL','POL','Polsko','Poland',1),
('HU','HUN','Maďarsko','Hungary',1),
('FR','FRA','Francie','France',1),
('IT','ITA','Itálie','Italy',1),
('ES','ESP','Španělsko','Spain',1),
('NL','NLD','Nizozemsko','Netherlands',1),
('BE','BEL','Belgie','Belgium',1),
('LU','LUX','Lucembursko','Luxembourg',1),
('IE','IRL','Irsko','Ireland',1),
('PT','PRT','Portugalsko','Portugal',1),
('FI','FIN','Finsko','Finland',1),
('SE','SWE','Švédsko','Sweden',1),
('DK','DNK','Dánsko','Denmark',1),
('EE','EST','Estonsko','Estonia',1),
('LV','LVA','Lotyšsko','Latvia',1),
('LT','LTU','Litva','Lithuania',1),
('SI','SVN','Slovinsko','Slovenia',1),
('HR','HRV','Chorvatsko','Croatia',1),
('RO','ROU','Rumunsko','Romania',1),
('BG','BGR','Bulharsko','Bulgaria',1),
('GR','GRC','Řecko','Greece',1),
('CY','CYP','Kypr','Cyprus',1),
('MT','MLT','Malta','Malta',1),
('GB','GBR','Velká Británie','United Kingdom',0),
('CH','CHE','Švýcarsko','Switzerland',0),
('NO','NOR','Norsko','Norway',0),
('US','USA','Spojené státy','United States',0),
('CA','CAN','Kanada','Canada',0),
('UA','UKR','Ukrajina','Ukraine',0);
