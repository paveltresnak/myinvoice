-- MyInvoice.cz — Daňový optimalizátor: profil OSVČ per supplier × rok
--
-- Drží vstupy pro výpočet srovnání daňových režimů, které nejsou jinde:
-- typ činnosti (výdajový paušál), rodinná situace a odpočty. Pásmo paušálu
-- (flat_tax_band) a plátcovství DPH (is_vat_payer) se primárně berou ze
-- supplier (Nastavení); ve flat_tax_band tady držíme snapshot pro daný rok,
-- aby šla dělat retrospektiva (loni jsi mohl být v jiném pásmu).
--
-- Idempotence: IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS tax_profiles (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier_id       TINYINT UNSIGNED NOT NULL,
    year              SMALLINT UNSIGNED NOT NULL,
    activity_rate     ENUM('30','40','60','80') NOT NULL DEFAULT '60'
                        COMMENT 'Výdajový paušál dle typu činnosti (60 % = IT/živnost volná)',
    flat_tax_band     ENUM('none','band1','band2','band3') NOT NULL DEFAULT 'none'
                        COMMENT 'Pásmo paušálu pro tento rok (default ze supplier.flat_tax_band)',
    is_secondary      TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Vedlejší činnost (jiná minima pojistného)',
    spouse_credit     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Nárok na slevu na manželku/manžela (příjem <68k & dítě <3)',
    children_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    mortgage_interest DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Úroky z hypotéky / rok (odpočet, max 150k)',
    pension_contrib   DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'Penzijní připojištění / rok (odpočet, max 48k)',
    life_insurance    DECIMAL(12,2) NOT NULL DEFAULT 0,
    donations         DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_tax_profile (supplier_id, year),
    CONSTRAINT fk_taxprofile_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
