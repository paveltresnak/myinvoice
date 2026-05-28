-- MyInvoice.cz — CRM recompute: vyloučit spárovanou/zaplacenou zálohu z nákladů
--
-- Redefinice sp_recompute_crm_monthly_summary (0036): nákladová strana (přijaté
-- faktury) nově vynechává zálohové doklady (document_kind='advance'), které jsou
-- zaplacené NEBO spárované s finální fakturou — ta pak nese náklad. Bez toho se
-- náklad (záloha + vyúčtovací faktura) započítával 2×. Nezaplacená a nespárovaná
-- záloha se počítá dál (očekávaný budoucí náklad). Příjmová strana proformu už
-- vylučuje (invoice_type != 'proforma').
--
-- Idempotentní: DROP PROCEDURE IF EXISTS + CREATE. migrate.php umí DELIMITER.

DELIMITER //

DROP PROCEDURE IF EXISTS sp_recompute_crm_monthly_summary //

CREATE PROCEDURE sp_recompute_crm_monthly_summary(IN p_supplier_id TINYINT UNSIGNED)
BEGIN
    DECLARE v_cutoff DATE;
    SET v_cutoff = DATE_SUB(CURDATE(), INTERVAL 13 MONTH);

    DELETE FROM crm_monthly_summary
     WHERE supplier_id = p_supplier_id
       AND period_ym >= DATE_FORMAT(v_cutoff, '%Y-%m');

    -- Revenue + invoice count z vydaných (status NOT IN draft, cancelled)
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, invoice_count,
         costs, costs_net, purchase_count, vat_output, vat_input)
    SELECT
        i.supplier_id,
        DATE_FORMAT(i.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        SUM(COALESCE(i.total_with_vat, 0))    AS revenue,
        SUM(COALESCE(i.total_without_vat, 0)) AS revenue_net,
        COUNT(*) AS invoice_count,
        0, 0, 0,
        SUM(COALESCE(i.total_with_vat, 0) - COALESCE(i.total_without_vat, 0)) AS vat_output,
        0
      FROM invoices i
 LEFT JOIN currencies c ON c.id = i.currency_id
     WHERE i.supplier_id = p_supplier_id
       AND i.status NOT IN ('draft', 'cancelled')
       AND i.issue_date >= v_cutoff
       AND i.invoice_type != 'proforma'  -- proformy vynechat (nejsou daňový doklad)
  GROUP BY i.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           revenue       = VALUES(revenue),
           revenue_net   = VALUES(revenue_net),
           invoice_count = VALUES(invoice_count),
           vat_output    = VALUES(vat_output);

    -- Costs + purchase count z přijatých (status NOT IN draft, cancelled).
    -- Zálohu (advance) vyřaď, pokud je zaplacená NEBO spárovaná s finální fakturou
    -- (proti dvojímu započtení nákladu). Nezaplacená nespárovaná záloha se počítá.
    INSERT INTO crm_monthly_summary
        (supplier_id, period_ym, currency, revenue, revenue_net, invoice_count,
         costs, costs_net, purchase_count, vat_output, vat_input)
    SELECT
        pi.supplier_id,
        DATE_FORMAT(pi.issue_date, '%Y-%m') AS ym,
        COALESCE(c.code, 'CZK') AS currency,
        0, 0, 0,
        SUM(COALESCE(pi.total_with_vat, 0))    AS costs,
        SUM(COALESCE(pi.total_without_vat, 0)) AS costs_net,
        COUNT(*) AS purchase_count,
        0,
        SUM(COALESCE(pi.total_with_vat, 0) - COALESCE(pi.total_without_vat, 0)) AS vat_input
      FROM purchase_invoices pi
 LEFT JOIN currencies c ON c.id = pi.currency_id
     WHERE pi.supplier_id = p_supplier_id
       AND pi.status NOT IN ('draft', 'cancelled')
       AND pi.issue_date >= v_cutoff
       AND NOT (COALESCE(pi.document_kind, '') = 'advance'
                AND (pi.status = 'paid'
                     OR EXISTS (SELECT 1 FROM purchase_invoices adv_s
                                 WHERE adv_s.advance_purchase_invoice_id = pi.id)))
  GROUP BY pi.supplier_id, ym, currency
       ON DUPLICATE KEY UPDATE
           costs          = VALUES(costs),
           costs_net      = VALUES(costs_net),
           purchase_count = VALUES(purchase_count),
           vat_input      = VALUES(vat_input);
END //

DELIMITER ;
