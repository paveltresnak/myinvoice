# 10. Přijaté faktury (nákupy)

> Přidáno v3.5.0 jako součást fáze 1 integrace forku myinvoiceDph (Martin Říha).

**Přijaté faktury** jsou doklady, které **dostáváš od svých dodavatelů** — peníze
odcházejí z firmy. Oproti vystaveným fakturám:

| | Vystavené (faktura) | Přijaté (purchase invoice) |
|---|---|---|
| Směr peněz | Klient → my (příjem) | My → dodavatel (výdaj) |
| Protistrana | Zákazník (`is_customer=1`) | Dodavatel (`is_vendor=1`) — stejná tabulka klientů, jiný flag |
| DPH role | Sbíráme od klientů (výstupní DPH) | Odečítáme z dodavatelských (vstupní DPH) |
| Číslování | Naše `2605001` | Číslo dodavatele (na originálu) + naše interní `PF-202605-NNNN` |
| Status flow | draft → issued → sent → paid | draft → received → booked → paid |
| Schvalování / odesílání | Ano, klient potvrdí | Ne, doklad jen evidujeme |

V hlavním menu **Přijaté faktury**.

## 10.1 Stavy přijaté faktury

| Stav | Význam | Co lze |
|---|---|---|
| **Koncept** (draft) | Rozpracovaný — ještě jsi nepotvrdil že to je platná faktura | Upravit, smazat, přejít na Přijatá |
| **Přijatá** (received) | Doklad potvrzený jako platný — visí na nezaplacených | Označit jako zaúčtovaná, uhrazená, stornovat |
| **Zaúčtovaná** (booked) | Předala se účetní / poslala do účetnictví | Označit jako uhrazená, stornovat |
| **Uhrazená** (paid) | Zaplaceno (manuálně nebo automaticky z bankovního výpisu) | — (terminal) |
| **Stornovaná** (cancelled) | Stornovaný doklad — necháváme pro audit | — (terminal) |

Smazat jde **jen koncept**. Pro pozdější stavy použij Stornovat (zachová auditní stopu).

## 10.2 Nová přijatá faktura

V seznamu klikni **+ Nová přijatá faktura**. Otevře se formulář.

### 10.2.1 Drag & drop PDF
Nad formulářem je **drag & drop zóna**. Pokud máš PDF od dodavatele:

- Přetáhni PDF do zóny (nebo klikni a vyber soubor).
- Systém prohledá PDF zda obsahuje **embedded ISDOC** přílohu:
  - **Pokud ano** (fakturační software jako Money S3, Pohoda, Stormware, sám MyInvoice) → pole formuláře se předvyplní strukturovanými daty.
  - **Pokud ne** (běžné PDF bez přílohy) → ve fázi 1 musíš vyplnit ručně. Ve fázi 2c (plánováno) doplníme AI extrakci přes Anthropic Claude — viz `source/09-fork-integration-plan.md`.
- Originál PDF se po prvním uložení faktury automaticky **archivuje** mimo webroot a v detailu si ho můžeš kdykoli stáhnout zpět.

Limity:
- Max 20 MiB per soubor
- Akceptujeme pouze application/pdf (magic bytes `%PDF-` se ověřují server-side)
- SHA-256 deduplikace — stejný PDF už archivovaný u jiné faktury nebude akceptován

### 10.2.2 Povinná pole

| Pole | Význam |
|---|---|
| **Dodavatel** | Vyber z dropdownu (autocomplete). Pokud chybí, klikni „+ Vytvořit nového dodavatele" — využije ARES lookup podle IČO. |
| **Číslo dokladu dodavatele** | Tak jak je vytištěno na originálu (např. `FA-2026-001`). Max 50 znaků. Unique per (dodavatel, datum vystavení) — nelze importovat 2× stejnou. |
| **Naše interní číslo** | Volitelné. Pokud necháš prázdné, vygeneruje se automaticky `{PP}{YYMM}{CCC}` (např. `PF2602001`) při přechodu na stav Přijatá. Prefix `PP` odpovídá daňovému typu (viz 10.2.4): **PF/PN** plný nárok (uznatelný/ne), **KU/KN** krácený, **NU/NN** bez nároku. Počítadlo `CCC` je per měsíc (přeteče na 4+ místa nad 999 dokladů). |
| **Typ dokladu** | Faktura / Doklad o úhradě / Dobropis / Záloha (pro filtrování v seznamu). |
| **Datum vystavení** | Z faktury. |
| **DUZP (datum uskutečnění zdanitelného plnění)** | Klíčové pro DPH období. Default = datum vystavení. |
| **Splatnost** | Z platebních podmínek dodavatele. |
| **Datum přijetí** | Kdy jsi to fyzicky / e-mailem dostal. Default = dnes. |
| **Měna faktury** | Měna, ve které je doklad vystaven (USD, EUR, CZK…). |
| **Kurz k DUZP** | Pokud je měna ≠ CZK, **musíš zafixovat kurz**. Tlačítko „Načíst z ČNB" stáhne aktuální nebo poslední dostupný denní kurz. |
| **Reverse charge** | Zaškrtni, pokud je doklad B2B s přenesenou daňovou povinností (B2B EU services). DPH na řádcích bude 0, ty si daň zdaníš sám ve výkazu DPH. |

### 10.2.3 Položky

Tlačítkem **+ Přidat položku** přidej řádek. Per řádek:

- Popis
- Množství (např. 1)
- Měrná jednotka (ks / hod / kus…)
- Cena za MJ bez DPH
- Sazba DPH (z číselníku — 21 % / 12 % / 0 %)
- (volitelně) MFČR DPH klasifikační kód — pro výkazy DPH (sekce Daně, auto-default podle sazby)

Souhrn dole se přepočítá automaticky po každé změně.

### 10.2.4 Daňová uznatelnost a nárok na odpočet

V boxu **Klasifikace** jsou dva nezávislé příznaky řídící, jak faktura vstupuje do daňových výkazů:

| Příznak | Možnosti | Co ovlivňuje |
|---|---|---|
| **Nárok na odpočet DPH** | Plný / Bez nároku / Krácený | DPH evidenci |
| **Daňově uznatelný náklad** | ano / ne | daň z příjmů (DPFO/DPPO) |

- **Nárok na odpočet DPH:**
  - **Plný** (výchozí) — standardní odpočet, faktura jde do Knihy DPH, DPHDP3 (ř. 40–45) i Kontrolního hlášení.
  - **Bez nároku** — faktura **vůbec nevstupuje** do DPH evidence (Kniha DPH, DPHDP3, KH); je to jen účetní náklad. Typicky reprezentace, osobní spotřeba.
  - **Krácený (poměrný §75)** — odpočet jen v poměrné výši (např. auto 70 % pro ekonomickou činnost). Po výběru zadáš **Odpočet %** a o toto procento se zkrátí základ i daň odpočtu v Knize DPH a DPHDP3 (ř. 40–45); zbytek je nedaňová část.
- **Daňově uznatelný náklad** — řídí pouze daň z příjmů: když je vypnuto, náklad se nezahrne do orientačního hospodářského výsledku (DPFO/DPPO). S DPH to nesouvisí (faktura může mít odpočitatelné DPH a být daňově neuznatelná, i naopak).

Oba příznaky jsou vidět i v **detailu** přijaté faktury (box Měna/DPH).

### 10.2.5 Platba v jiné měně (multi-currency)

Klikni na **„Platba v jiné měně než měna faktury"** pokud máš tento scénář:

> Faktura je v USD ($1000), ale platíš ji z CZK účtu (banka konvertuje na ~24 500 Kč
> s 1–2% spread / poplatkem).

V tomto bloku zadáš:

- Měna platebního účtu (např. CZK)
- Kurz platba → měna faktury (např. 0.0408 USD/CZK, nebo opačně dle UI)
- Kolik reálně odešlo z účtu (24 500 CZK)

Systém automaticky vypočte:

- **Ekvivalent v měně faktury** — pro spárování proti `amount_to_pay`
- **Kurzový rozdíl** — v základní měně (CZK). Záporný = kurzová ztráta, kladný = zisk. Zatím se zaznamenává pro reporting; účetně se v fázi 6 (DPH výkazy) automaticky promítne do správných řádků.

## 10.3 Detail přijaté faktury

Po uložení / přechodu na detail:

- Vidíš dodavatele (s IČO/DIČ), datumy, položky, DPH rozpis, totály, K úhradě.
- Sekce **Originální PDF od dodavatele** — pokud jsi nahrál, můžeš stáhnout zpět.
- Tlačítka pro **přechod stavu** podle state-machine:
  - Z draft: Označit jako přijaté / Stornovat
  - Z received: Označit jako zaúčtované / uhrazené / Stornovat
  - Z booked: Označit jako uhrazené / Stornovat
- Tlačítko **Upravit** je dostupné jen u draft. Po označení jako přijatá je doklad immutable (kromě admin override `?force=1` u received).
- Tlačítko **Smazat** je dostupné jen u draft. Pro pozdější stavy použij Stornovat.

### 10.3.1 Propojení zálohy s vyúčtovací fakturou (proti dvojímu započtení)

> Přidáno v4.3.11.

Když ti dodavatel pošle nejdřív **zálohovou fakturu** (typ dokladu *Záloha* / proforma)
a po zaplacení samostatnou **vyúčtovací (finální) fakturu**, máš v systému dva doklady
na tentýž náklad. Bez propojení by se náklad počítal **dvakrát** (Náklady, CRM, daň
z příjmů). Proto je lze spárovat.

**Jak na to** — v detailu **finální** faktury je box **Zálohová faktura**:

- Pokud vazba není, klikni **Spárovat se zálohou** a vyber zálohu od stejného
  dodavatele. Nabídka řadí napřed zálohy ve **stejné měně** a s **nejbližší částkou**
  (porovnává hrubou částku faktury *před* odečtem zálohy, takže i faktura uhrazená
  zálohou „na 0 Kč" se napáruje správně).
- Po spárování se zobrazí odkaz na zálohu a tlačítko **Zrušit propojení**. Na finální
  fakturu se zároveň doplní odečet zálohy (`advance_paid_amount`), pokud byl nulový.
- V detailu **zálohy** vidíš reverzně, kterou fakturou je vyúčtována.

Jedna záloha může být navázaná **jen na jednu** finální fakturu.

**Co propojení (a zaplacení) ovlivní:**

| Oblast | Chování zálohy |
|---|---|
| **Náklady, CRM statistiky** | Spárovaná **nebo zaplacená** záloha se nepočítá (náklad nese vyúčtovací faktura). Nezaplacená a nespárovaná záloha se počítá jako očekávaný náklad. |
| **Daň z příjmů (DPFO/DPPO)** | Záloha **nikdy** není uznatelný náklad (není daňový doklad) — bez ohledu na zaplacení/párování. |
| **Výkazy DPH** (Kniha DPH, DPHDP3, KH, souhrnné hlášení) | Záloha do nich **nevstupuje vůbec** (není daňový doklad; tím je až vyúčtovací faktura). |
| **Závazky / cashflow** | Nezaplacená záloha zůstává jako reálný závazek k úhradě. |

**AI návrh propojení** — když naimportuješ vyúčtovací fakturu přes AI extrakci z PDF
(viz 10.7) a ta odkazuje na zálohu (text typu *„zaplaceno zálohou č. X"*), systém
zkusí najít odpovídající zálohu a v detailu nabídne **návrh propojení**. Stačí ho
**Potvrdit** (nebo **Zamítnout**) — nic se nepáruje automaticky.

## 10.4 Scan inbox — automatický import z adresáře

Pokud máš dodavatele kteří ti **posílají PDF e-mailem** nebo máš složku
sdílených dokladů, nakonfiguruj **inbox adresář** v `cfg.php`:

```php
'purchase_invoice' => [
    'inbox_dir'         => 'C:/inetpub/wwwroot/myinvoice.cz/inbox',
    'inbox_recursive'   => true,
    'allowed_exts'      => ['pdf', 'isdoc', 'xml'],
    'archive_storage'   => __DIR__ . '/storage/purchase-invoices',
],
```

V seznamu Přijaté faktury klikni **📥 Nascanovat inbox**:

- Systém rekurzivně projde nakonfigurovaný adresář.
- Pro každý soubor spočte SHA-256 — pokud už existuje faktura se stejným otiskem, soubor přeskočí.
- Z PDF s embedded ISDOC rozpozná data dodavatele a obsah.
- Plain PDF (bez ISDOC) jsou ve fázi 1 přeskakovány (s důvodem „AI extrakce dorazí v fázi 2c").

Modal po skončení zobrazí přehled: vytvořeno / přeskočeno / chyby + per-soubor detail.

**Bezpečnost:** soubory mimo configured `inbox_dir` jsou odmítnuty (path traversal guard
přes `realpath()`). Maximum 500 souborů per běh (DoS protection na velké adresáře).

## 10.5 Klienti vs. dodavatelé

V tabulce klientů jsme zavedli dva flagy:

- `is_customer` — klient, kterému fakturuješ (default `1` pro všechny existující záznamy)
- `is_vendor` — dodavatel, od kterého přijímáš faktury

Některé firmy jsou **současně zákazník i dodavatel** (např. partnerská IT firma, kterou
fakturuješ za development a od níž kupuješ hosting) — jedna entita = jedna řádka,
**oba flagy = 1**. ARES synchronizace, kontakty, historie jsou sdílené.

V hlavním menu **Klienti** vidíš defaultně jen `is_customer=1`. V budoucí verzi
přidáme oddělený view **Dodavatelé** pro `is_vendor=1`.

## 10.6 Export přijaté faktury (naše PDF / ISDOC / Pohoda)

V detailu přijaté faktury najdeš tlačítko **„Exporty"** s dropdown menu:

### Naše PDF (rekonstrukce)
Vygeneruje naši vlastní PDF kopii ze strukturovaných dat. Užitečné když:
- Importovaly se jen metadata (z iDokladu/Fakturoidu API, ne originální PDF)
- Originál není dostupný (přijatá faktura zadaná ručně)
- Potřebuješ čitelný PDF pro účetní archiv

PDF obsahuje hlavičku s dodavatelem, položky, totals, poznámky. Footer poznámka:
*„Naše rekonstrukce přijaté faktury z dat v MyInvoice.cz. Originál od dodavatele je
referenční dokument."*

### ISDOC XML
Export do ISDOC 6.0 standardu — kompatibilní s Pohoda, Money S3, iDoklad a dalšími.
Strategie: **role inversion** — v ISDOC pro přijatou fakturu je *dodavatel* =
původní vendor, *zákazník* = naše firma (opak vystavené).

### Pohoda XML
Pohoda dataPack XML pro import do účetního software Pohoda. Direction =
purchase (`<pur:purchase>` místo `<inv:invoice>`).

### Hromadný export za měsíc

V hlavním menu **Přijaté faktury → Exporty** vyber měsíc + formát:

- **PDF ZIP** — preferuje archivovaný **originál** od dodavatele (`Prijata-{vs}-{vendor}.pdf`); pokud originál chybí, doplní se **naše rekonstrukce** z dat faktury (`…-rekonstrukce.pdf`, ať ji účetní pozná). Faktura se přeskočí jen když selže i rekonstrukce.
- **ISDOC ZIP** — jeden `.isdoc` XML soubor za fakturu, sbaleno do ZIP.
- **Pohoda XML** — sloučený `<dataPack>` se všemi fakturami za měsíc (přímý import do Pohody, direction = purchase).

„Datum dle" volí, podle kterého data se faktura zařadí do měsíce: DUZP (tax),
datum vystavení (issue, default) nebo datum přijetí (received).

## 10.7 AI extrakce — kontrola výsledků

Při AI extrakci z PDF se po importu automaticky spustí **sanity check**: sečtou
se řádky bez DPH a porovnají s celkovým základem daně, který AI přečetla z PDF
„K úhradě". Pokud se hodnoty liší o víc než 2 %, faktura získá flag **„Ke
kontrole"** a uživatel by měl řádky před zaúčtováním ověřit.

### Indikátory v UI

- **Žluté zvýraznění řádku** + ikona ⚠ vedle čísla faktury v seznamu přijatých
  faktur (`/purchase-invoices`).
- **Filtr „Ke kontrole"** v topbaru seznamu — zobrazí jen faktury, kde je flag
  aktivní.
- **Žlutý warning banner** v detailu i editoru faktury s diagnostickým textem
  (např. *„součet řádků bez DPH (XX) je vyšší než AI-vrácený základ daně bez
  DPH (YY) — rozdíl Z %"*).

### Jak zrušit warning

- Tlačítko **Beru na vědomí** v banneru — pošle POST
  `/api/purchase-invoices/{id}/dismiss-extraction-warning` a flag se smaže.
- **Automaticky** při přechodu z draftu na další stav (received / booked /
  paid) — uživatel posunul stav = ověřil data.

### Auto-upgrade modelu

Pokud levnější model (Haiku 4.5) vrátí slabý výsledek (vendor se shoduje s
tenantem nebo součet řádků se výrazně liší od totalu), extractor automaticky
zkusí znovu se silnějším modelem (Sonnet 4.6, ~4× dráž za extract). Pokud máš
Sonnet/Opus jako default, retry se přeskočí.

### Katastrofální mismatch — placeholder

Když ani silnější model nezvládne rozparsovat řádky (typicky komplexní
multi-column servisní faktury) a součet řádků se liší od totalu o víc než
50 %, extractor:

1. Zachová **popisy řádků** z AI extraktu (jsou obvykle správně)
2. Vynuluje jejich **qty a unit_price** (0)
3. Přidá první řádek **KOREKCE** s AI totalem z „K úhradě", aby seděl celkový
   součet faktury

Uživatel pak postupně doplní qty/cenu k jednotlivým řádkům a nakonec smaže
korekční řádek.

### Backfill existujících faktur

CLI skript `php api/bin/recheck-ai-extracted-invoices.php` projde přijaté
faktury s PDF přílohou, re-spustí AI extrakci a porovná AI total s aktuálním
DB totalem. Při rozdílu nad práh (default 2 %) zapíše varování:

```
php api/bin/recheck-ai-extracted-invoices.php                    # dry-run
php api/bin/recheck-ai-extracted-invoices.php --apply            # zápis
php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1
php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05
```

## 10.8 Audit log

Akce s přijatými fakturami jsou logované v aktivním logu (Systém → Log):

- `purchase_invoice.created`
- `purchase_invoice.updated` / `force_updated`
- `purchase_invoice.items_updated`
- `purchase_invoice.exchange_rate_set`
- `purchase_invoice.transitioned` (s payloadem `{from, to}`)
- `purchase_invoice.extraction_warning_dismissed`
- `purchase_invoice.advance_linked` / `advance_unlinked` (propojení se zálohou)
- `purchase_invoice.advance_suggestion_dismissed` (zamítnutý AI návrh propojení)
- `purchase_invoice.deleted`
- `purchase_invoice.pdf_uploaded` / `pdf_downloaded`
- `purchase_invoice.our_pdf_downloaded`
- `purchase_invoice.isdoc_exported` / `pohoda_exported`
- `purchase_invoice.inbox_scanned`

## 10.9 REST API

Všechny operace jsou dostupné i přes REST API (`/api/v1/purchase-invoices/*`) —
viz [Swagger UI](/api/docs) nebo [Redoc](/api/reference). PAT token musí mít scope
`read_write` pro mutace.

## 10.10 Status integrace forku

Všechny fáze plánu jsou **dokončeny**:

- ✅ **Fáze 1** (v3.5.0) — základní CRUD přijatých faktur, PDF upload + inbox scan
- ✅ **Fáze 2a** (v3.6.0) — iDoklad API import (OAuth + jobs + dobropisy + attachments)
- ✅ **Fáze 2b** — Fakturoid import (BasicAuth + subjects + invoices + expenses)
- ✅ **Fáze 2c** — AI extrakce z PDF (Anthropic Claude vision, BYOK)
- ✅ **Fáze 3** (v3.7.0) — bank matching CSV + auto-match (vystavené + přijaté)
- ✅ **Fáze 5** — CRM dashboard (revenue/costs/profit/aging/DSO/concentration/churn)
- ✅ **Fáze 6** (v4.0.0) — VAT klasifikace + tax settings + DPHDP3/KH/SH/DPFO/DPPO XML
  výkazy + Naše PDF + ISDOC/Pohoda export přijatých

Detail viz `source/09-fork-integration-plan.md`.
