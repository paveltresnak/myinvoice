-- 0040: CRM action item dismissals
-- Umožňuje uživateli skrýt "Akce pro tebe" na den / týden / navždy / pro historická data.
-- Per-user (každý uživatel má vlastní preferenci).
--
-- Mode 'historical' = uloží JSON snapshot ID, která existují PŘI dismiss
--                     -> notification se zobrazí jen pokud přibude NOVÉ ID, které není v baseline.
-- Mode 'day' / 'week' = nastaví dismissed_until na +1 den / +7 dní.
-- Mode 'forever' = dismissed_until = NULL (nikdy nevyprší).

CREATE TABLE IF NOT EXISTS `crm_action_item_dismissals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `supplier_id` TINYINT UNSIGNED NOT NULL,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `item_type` VARCHAR(40) NOT NULL COMMENT 'overdue_invoices|recurring_due|overdue_payables|tax_deadline|churn_risk',
    `mode` ENUM('day','week','forever','historical') NOT NULL,
    `dismissed_until` DATETIME NULL COMMENT 'NULL pro forever a historical (nevyprší)',
    `baseline_ids` JSON NULL COMMENT 'Pro mode=historical: pole ID, která byla aktivní při dismiss',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_supplier_user_type` (`supplier_id`, `user_id`, `item_type`),
    KEY `idx_user` (`user_id`),
    CONSTRAINT `fk_aid_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `supplier` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_aid_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
