-- MyInvoice.cz — Propojení přijaté zálohy (advance) s finální fakturou
--
-- Na přijaté straně dosud chyběla vazba mezi zálohovou fakturou
-- (document_kind='advance') a finální vyúčtovací fakturou, kterou dodavatel
-- pošle samostatně. Bez vazby se stejný náklad počítal 2× (záloha + faktura)
-- v Nákladech, CRM statistikách i dani z příjmů. Tato migrace přidává:
--
--   • advance_purchase_invoice_id — FK na zálohu, nastavený NA FINÁLNÍ faktuře
--     (vzor vystavené strany invoices.parent_invoice_id). UNIQUE = 1 záloha smí
--     být navázaná max jednou (1:1). ON DELETE SET NULL: zálohu i finální drží
--     dodavatel nezávisle — smazání zálohy NESMÍ smazat přijatou finální fakturu
--     (proto NE CASCADE jako u vystavených).
--   • advance_link_suggested_id — návrh propojení od AI (suggest & confirm).
--     Drží se odděleně od extraction_warning (to je volnotextové a maže se při
--     přechodu stavu). Bez UNIQUE — víc finálních může navrhovat tutéž zálohu,
--     dokud uživatel nepotvrdí. Po potvrzení se přesune do advance_purchase_invoice_id.
--
-- Idempotentní: ADD COLUMN/KEY IF NOT EXISTS (MariaDB 10.6+), FK přes
-- DROP FOREIGN KEY IF EXISTS + ADD (vzor migrace 0015).

SET NAMES utf8mb4;

-- purchase_invoices.id je BIGINT UNSIGNED → FK sloupce MUSÍ být taky UNSIGNED.
ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS advance_purchase_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'FK na přijatou zálohu (document_kind=advance), kterou tato finální faktura vyúčtovává'
        AFTER document_kind,
    ADD COLUMN IF NOT EXISTS advance_link_suggested_id BIGINT UNSIGNED NULL
        COMMENT 'AI návrh propojení se zálohou (suggest & confirm); po potvrzení se vynuluje'
        AFTER advance_purchase_invoice_id;

-- Coerce na UNSIGNED i kdyby sloupce vznikly dřív jako signed (idempotentní náprava).
ALTER TABLE purchase_invoices
    MODIFY COLUMN advance_purchase_invoice_id BIGINT UNSIGNED NULL
        COMMENT 'FK na přijatou zálohu (document_kind=advance), kterou tato finální faktura vyúčtovává',
    MODIFY COLUMN advance_link_suggested_id BIGINT UNSIGNED NULL
        COMMENT 'AI návrh propojení se zálohou (suggest & confirm); po potvrzení se vynuluje';

-- 1 záloha ↔ 1 finální
ALTER TABLE purchase_invoices
    ADD UNIQUE KEY IF NOT EXISTS uq_pi_advance_link (advance_purchase_invoice_id);
ALTER TABLE purchase_invoices
    ADD KEY IF NOT EXISTS idx_pi_advance_suggested (advance_link_suggested_id);

-- FK (idempotentně přes DROP IF EXISTS + ADD)
ALTER TABLE purchase_invoices
    DROP FOREIGN KEY IF EXISTS fk_pi_advance;
ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_advance
        FOREIGN KEY (advance_purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;

ALTER TABLE purchase_invoices
    DROP FOREIGN KEY IF EXISTS fk_pi_advance_suggested;
ALTER TABLE purchase_invoices
    ADD CONSTRAINT fk_pi_advance_suggested
        FOREIGN KEY (advance_link_suggested_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL;
