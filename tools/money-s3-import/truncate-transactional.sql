-- MyInvoice — vyčištění transakčních tabulek před čistým re-importem.
-- POZOR: smaže faktury, klienty a odvozené tabulky. NESMAŽE supplier/users/api_tokens/číselníky.
-- Vždy nejprve plná záloha DB! (mariadb-dump)
USE myinvoice;
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE invoice_items;
TRUNCATE TABLE invoice_pdfs;
TRUNCATE TABLE invoice_attachments;
TRUNCATE TABLE invoice_counters;
TRUNCATE TABLE invoices;
TRUNCATE TABLE purchase_invoice_items;
TRUNCATE TABLE purchase_invoice_counters;
TRUNCATE TABLE purchase_invoices;
TRUNCATE TABLE clients;
TRUNCATE TABLE tax_submissions;
TRUNCATE TABLE client_revenue_cache;
TRUNCATE TABLE project_revenue_cache;
TRUNCATE TABLE crm_monthly_summary;
TRUNCATE TABLE payment_matches;
SET FOREIGN_KEY_CHECKS=1;
