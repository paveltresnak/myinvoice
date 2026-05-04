-- MyInvoice.cz — Per-klient přepínač automatického posílání upomínek.
--
-- Když je 0, cron `bin/cron-send-reminders.php` přeskočí všechny faktury
-- daného klienta. Ruční upomínky (jednotlivé i hromadné z UI) fungují dál.
-- Default 1 = upomínky se posílají (zachovává stávající chování).

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN auto_send_reminders TINYINT(1) NOT NULL DEFAULT 1
    AFTER reverse_charge;
