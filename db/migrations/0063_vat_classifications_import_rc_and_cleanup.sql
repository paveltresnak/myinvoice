-- MyInvoice.cz — Daňový audit (2026-05-28): dotažení samovyměření u dovozu +
-- vyčištění chybné kh_section
--
-- Navazuje na 0048 (is_reverse_charge pro kódy 5, 23) a 0044 (RC mirror ř.43 pro
-- 5, 23). Tyto migrace nechaly stejnou třídu "tiché chyby" u dalších kódů:
--
-- (A) **Samovyměření u dovozu služby / zboží — kódy 24 a 25**
--     Kód 24 "přijetí služby z EU / dovoz služby" (ř.12 + mirror ř.43) a kód 25
--     "dovoz zboží ze 3. země" (ř.7) měly is_reverse_charge=0. Daň na vstupu se
--     proto samovyměřila (VatLedgerService::normalize) jen když uživatel ručně
--     zaškrtl RC checkbox na dokladu — jinak ř.12/ř.7 vykázaly základ s daní 0 a
--     zrcadlový odpočet ř.43 taky 0. U plně odpočtových plnění se to v penězích
--     vynuluje, ale výkaz je věcně špatně; u kráceného/nulového odpočtu jde o
--     reálné podhodnocení daně. Nastavením flagu je chování konzistentní s 5/23.
--     Kód 25 navíc dostane secondary=43 (chyběl mu mirror odpočet), stejně jako
--     dovoz služby v 0042. Pozn.: dovoz zboží vyměřený celním úřadem (ř.42) se
--     řeší samostatným kódem — pro ten je potřeba custom klasifikace.
--
-- (B) **Kód 20 (dodání zboží do EU) měl kh_section='A.1'**
--     EU dodání do kontrolního hlášení vůbec nepatří (vykazuje se v souhrnném
--     hlášení). Hodnota byla mrtvá (KH routuje vystavené přes is_reverse_charge +
--     zeroBase, ne přes kh_section), ale matoucí — nastavíme na NULL.
--
-- Idempotentní: UPDATE guardované na aktuální hodnotu, jen globální seed (NULL).

SET NAMES utf8mb4;

-- (A) RC flag pro dovoz služby (24) a dovoz zboží (25)
UPDATE vat_classifications
   SET is_reverse_charge = 1
 WHERE code IN ('24', '25')
   AND supplier_id IS NULL
   AND is_reverse_charge = 0;

-- (A) RC mirror odpočet ř.43 pro dovoz zboží (25) — dovoz služby (24) ho má z 0042
UPDATE vat_classifications
   SET dphdp3_line_secondary = '43'
 WHERE code = '25'
   AND supplier_id IS NULL
   AND (dphdp3_line_secondary IS NULL OR dphdp3_line_secondary = '');

-- (B) Vyčištění chybné kh_section u EU dodání zboží
UPDATE vat_classifications
   SET kh_section = NULL
 WHERE code = '20'
   AND supplier_id IS NULL
   AND kh_section = 'A.1';

-- (C) **Kód 42 "tuzemsko bez nároku na odpočet" — chybný dphdp3_line='42'**
--     ř.42 (dov_cu) je ODPOČET při dovozu zboží přes celní úřad — plnění bez nároku
--     na odpočet tam (ani na žádný odpočtový řádek) nepatří. Správně NULL: takové
--     plnění se do DPHDP3 ani KH nevykazuje (je to jen účetní náklad vč. DPH).
--     DPHDP3 i KH řádky s NULL line vynechávají; Kniha DPH je přeskakuje (kód
--     přítomen, ale bez řádku → není to chybějící klasifikace, ale vědomé vyloučení).
UPDATE vat_classifications
   SET dphdp3_line = NULL
 WHERE code = '42'
   AND supplier_id IS NULL
   AND dphdp3_line = '42';

-- (D) **Kód 3 "tuzemsko osvobozeno" — chybný dphdp3_line='3'**
--     Seedová chyba "kód = řádek": ř.3 (p_zb23) je POŘÍZENÍ ZBOŽÍ Z JČS (vstup),
--     ne osvobozené tuzemské plnění. Osvobozené tuzemské vystavené plnění (sazba 0 %)
--     se navíc auto-defaultuje na kód 3 (VatClassificationDefaulter), takže jeho
--     základ tiše nadhodnocoval ř.3 přiznání (pořízení z EU) s daní 0.
--     Správný domov je ř.50/51 (osvobozená plnění → koeficient § 76), ale Veta5/§76
--     je vědomě mimo scope. Do té doby NULL: osvobozené plnění se do DPHDP3/KH/Knihy
--     nevykazuje (uživatel řeší koeficient ručně) — lepší než korumpovat ř.3.
UPDATE vat_classifications
   SET dphdp3_line = NULL
 WHERE code = '3'
   AND supplier_id IS NULL
   AND dphdp3_line = '3';
