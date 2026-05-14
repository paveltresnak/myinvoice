-- MyInvoice.cz — VAT-aware revenue procedures.
--
-- Pro plátce DPH (supplier.is_vat_payer = 1) bereme obrat z `total_without_vat`,
-- pro neplátce z `total_with_vat`. Záměr: pro plátce je relevantní pro DPH limit
-- (2 mil. CZK / 12 měsíců) obrat bez DPH; neplátce vidí celkové fakturované částky.
--
-- Idempotentní: DROP IF EXISTS + CREATE.

DROP PROCEDURE IF EXISTS sp_recompute_client_revenue;
DROP PROCEDURE IF EXISTS sp_recompute_project_revenue;
DROP PROCEDURE IF EXISTS sp_recompute_all_caches;

DELIMITER //

-- Mirror StatsRecomputer::recomputeClient — VAT-aware sloupec dle is_vat_payer dodavatele.
CREATE PROCEDURE sp_recompute_client_revenue(IN p_client_id INT)
BEGIN
    DELETE FROM client_revenue_cache WHERE client_id = p_client_id;
    INSERT INTO client_revenue_cache (client_id, currency_id, revenue, last_invoice_date, invoice_count)
    SELECT i.client_id,
           i.currency_id,
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note')
                     THEN CASE WHEN s.is_vat_payer = 1 THEN i.total_without_vat ELSE i.total_with_vat END
                     ELSE 0 END),
           MAX(COALESCE(i.tax_date, i.issue_date)),
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN 1 ELSE 0 END)
      FROM invoices i
      JOIN supplier s ON s.id = i.supplier_id
     WHERE i.client_id = p_client_id
       AND i.status IN ('issued','sent','reminded','paid')
       AND i.invoice_type != 'cancellation'
  GROUP BY i.client_id, i.currency_id;
END //

-- Mirror StatsRecomputer::recomputeProject — VAT-aware sloupec dle is_vat_payer dodavatele.
CREATE PROCEDURE sp_recompute_project_revenue(IN p_project_id INT)
BEGIN
    DELETE FROM project_revenue_cache WHERE project_id = p_project_id;
    INSERT INTO project_revenue_cache (project_id, currency_id, revenue, last_invoice_date, invoice_count)
    SELECT i.project_id,
           i.currency_id,
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note')
                     THEN CASE WHEN s.is_vat_payer = 1 THEN i.total_without_vat ELSE i.total_with_vat END
                     ELSE 0 END),
           MAX(COALESCE(i.tax_date, i.issue_date)),
           SUM(CASE WHEN i.invoice_type IN ('invoice','credit_note') THEN 1 ELSE 0 END)
      FROM invoices i
      JOIN supplier s ON s.id = i.supplier_id
     WHERE i.project_id = p_project_id
       AND i.status IN ('issued','sent','reminded','paid')
       AND i.invoice_type != 'cancellation'
  GROUP BY i.project_id, i.currency_id;
END //

-- Full rebuild všech cache — opětovně použitelné po změně is_vat_payer dodavatele.
CREATE PROCEDURE sp_recompute_all_caches()
BEGIN
    DECLARE v_done TINYINT DEFAULT 0;
    DECLARE v_id INT;
    DECLARE cur_clients CURSOR FOR SELECT id FROM clients;
    DECLARE cur_projects CURSOR FOR SELECT id FROM projects;
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET v_done = 1;

    DELETE FROM client_revenue_cache;
    DELETE FROM project_revenue_cache;

    OPEN cur_clients;
    cli_loop: LOOP
        FETCH cur_clients INTO v_id;
        IF v_done THEN LEAVE cli_loop; END IF;
        CALL sp_recompute_client_revenue(v_id);
    END LOOP;
    CLOSE cur_clients;

    SET v_done = 0;
    OPEN cur_projects;
    prj_loop: LOOP
        FETCH cur_projects INTO v_id;
        IF v_done THEN LEAVE prj_loop; END IF;
        CALL sp_recompute_project_revenue(v_id);
    END LOOP;
    CLOSE cur_projects;
END //

DELIMITER ;

-- Přepočet cache po nasazení (per-supplier is_vat_payer se mohl změnit, nebo se mění logika).
CALL sp_recompute_all_caches();
