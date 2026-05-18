# 16. Importy (Pohoda XML, ISDOC, PDF/A-3)

Pokud máš historické vystavené faktury v jiném systému (Pohoda, iDoklad,
Fakturoid, Superfaktura nebo jiný fakturační software podporující ISDOC),
můžeš je do MyInvoice **naimportovat** — nemusíš je opisovat ručně.

> **Importují se jen tvoje vystavené faktury** (ne přijaté, ne nákupní doklady
> jiné firmy). Dodavatel ve vstupním souboru se musí shodovat s aktuálně
> zvoleným dodavatelem v aplikaci.

## 16.1 Obrazovka importů

V hlavním menu **Systém → Importy**.

Formulář:

- **Soubory** — přetáhni nebo klikni pro výběr. Akceptuje:
  - `.xml` (Pohoda dataPack)
  - `.isdoc` (ISDOC 6.0.x)
  - `.pdf` (PDF/A-3 s embedded ISDOC přílohou — viz § 16.6)
  - `.zip` s libovolným počtem těchto souborů uvnitř
- **Importovat** — odešle a vrátí report (kolik vytvořeno / přeskočeno / chyba).

## 16.2 Co se založí

Pro každou fakturu v souboru:

| Entita | Logika |
|---|---|
| **Klient** | Lookup po IČ. Pokud neexistuje, načteme adresu z **ARES** (preferenčně), fallback na adresu z XML. Vznikne nový klient. |
| **Zakázka** | Když má faktura `číslo zakázky` (ISDOC `OrderReference/ID`, Pohoda `numberOrder`), přiřadí se k zakázce s tím číslem (vytvoří se, pokud chybí). Pokud nemá číslo zakázky, ale klient má v importovaném balíku **více různých e-mailů**, vytvoří se per-email zakázka s názvem `{Firma} – {email}`. Jinak `bez zakázky`. |
| **Faktura** | Přepíše se do `invoices` se zachovaným původním varsymbolem. Položky, sazby DPH, kurz, měna se převezmou. Snapshoty (klient/dodavatel/banka) se zafixují z aktuálních dat. |

## 16.3 Stav (paid vs issued) — pravidlo 30 dní

Aby ses nemusel po importu zabývat starými fakturami:

- **Datum splatnosti starší než 30 dní** → faktura se uloží jako **Zaplacená**
  (`paid_at` = DUZP nebo datum vystavení). Předpoklad: starý doklad už dávno
  zaplacený.
- **Datum splatnosti v posledních 30 dnech (nebo v budoucnu)** → faktura se
  uloží jako **Vystavená**. Můžeš platbu spárovat standardním flow přes
  bankovní výpis nebo ručně označit jako zaplacenou.

## 16.4 Co se přeskočí

- **Cizí dodavatel** — celý soubor se přeskočí, pokud IČ dodavatele v souboru
  neodpovídá aktuálnímu dodavateli v aplikaci. (Hláška v reportu.)
- **Duplicita** — pokud faktura s daným varsymbolem u tohoto dodavatele už
  existuje, přeskočí se. V reportu se zobrazí důvod a id existující faktury.

## 16.5 Report

Po importu vidíš tabulku:

| Sloupec | Význam |
|---|---|
| Soubor | Cesta v balíku (název ZIPu / interní cesta) |
| Stav | `vytvořeno` / `přeskočeno` / `chyba` |
| Var. symbol | Z faktury |
| Detail | Link na vytvořenou fakturu, badge `paid`/`issued`, štítky `+ klient` / `+ zakázka` (pokud něco vzniklo). U přeskočených/chybných: důvod. |

## 16.6 PDF/A-3 import (embedded ISDOC)

Většina českých fakturačních systémů (**iDoklad**, **Fakturoid**, **Superfaktura**,
**Pohoda**, **MyInvoice**) dnes vkládá ISDOC XML přímo do PDF dokumentu jako
přílohu — viz standard **PDF/A-3** + ISDOC spec. Pokud máš v ruce jen PDF
faktury (typicky to, co ti přišlo emailem od dodavatele), můžeš ho importovat
přímo — MyInvoice z něj vytáhne embedded `*.isdoc` přílohu a importuje stejně,
jako kdybys nahrál samostatný `.isdoc` soubor.

**Jak to poznáš, jestli PDF má embedded ISDOC?**

- Otevři PDF v jakémkoli prohlížeči, klikni na ikonu **přílohy / sponky**.
  Pokud uvidíš soubor typu `*.isdoc` (často `invoice.isdoc`, ale třeba iDoklad
  ho pojmenuje `Vydaná faktura - 20230005-invoice.isdoc`), je to ono.
- V `Adobe Reader` najdeš přílohu v levém panelu pod ikonou kancelářské sponky.
- Můžeš to taky zjistit příkazem `pdfdetach -list <soubor>.pdf` (z balíku
  `poppler-utils`), nebo jakýmkoli PDF prohlížečem podporujícím přílohy.

**Co když PDF přílohu nemá?**

Pak ho **nelze automaticky importovat** — pure PDF nemá strukturovaná data
faktury, jen vizuální layout. Import vyhodí čitelnou chybu „PDF neobsahuje
ISDOC přílohu". V tom případě:

- Buď v původním systému (iDoklad, Pohoda …) **stáhni XML/ISDOC samostatně**
  a importuj ten soubor.
- Nebo fakturu zadej ručně.

**Co se podporuje:**

- ✅ PDF/A-3 s `/Type /EmbeddedFile` + filename končící `.isdoc` (oficiální
  ISDOC PDF spec).
- ✅ PDF s embedded ISDOC pod jiným jménem (content sniff podle ISDOC
  namespace `http://isdoc.cz/namespace/2013`).
- ✅ PDF s *compressed object streams* (`/Type /ObjStm`, PDF 1.5+).
  Spec sice ObjStm zavedlo, ale **stream objekty (a tím i `EmbeddedFile`)
  v ObjStm být nesmí** — vždy zůstávají na top-level, takže náš scanner
  je najde i v takových PDF.

**Limity:**

- ❌ **Šifrované PDF** (heslem nebo certifikátem). Stream byty jsou
  zašifrované, extractor je neumí dekódovat. Otevři PDF v Adobe Readeru,
  zadej heslo, ulož znovu bez šifrování, a pak nahraj.
- ❌ **Non-FlateDecode stream filtr** (LZW, RunLengthDecode, ASCII85
  bez následného Flate). Extractor zvládá jen FlateDecode (drtivá
  většina dnešních PDF). U starších/legacy producentů můžeš narazit.
- ❌ **Vícestupňový filter chain** (`/Filter [/ASCII85Decode /FlateDecode]`).
  Vzácné, ale existuje. Workaround: stáhni si ISDOC samostatně v původním
  systému.

## 16.7 Tipy

- **Před importem nahraj klienty z ARES** — ne nutné, ale pokud máš čas, můžeš
  je založit ručně se správnou výchozí měnou a paušálem; import pak jen použije
  existující ID a nebude tahat ARES.
- **Pohoda → MyInvoice** — exportuj v Pohodě data balíček (XML), nahraj sem.
  Pohoda neukládá `číslo zakázky` per fakturu, takže se importují bez zakázky
  (pokud klient nemá více emailů — viz § 16.2).
- **Multi-supplier** — přepni v aplikaci na cílového dodavatele předtím, než
  spustíš import. IČ z XML se ověří proti tomuto kontextu.
- **Co dělat, když import vyhodí chybu** — soubor zkontroluj v textovém
  editoru, jestli má validní XML a očekávaný root element (`<dat:dataPack>`
  pro Pohodu, `<Invoice>` v ISDOC namespace pro ISDOC). Pro PDF zkontroluj,
  jestli má `.isdoc` přílohu (viz § 16.6).
