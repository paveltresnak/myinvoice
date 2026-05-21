# 24. Výkazy DPH (DPHDP3 + KH + SH)

MyInvoice.cz generuje XML pro EPO portál MFČR:
- **DPH přiznání (DPHDP3)** — měsíční nebo kvartální
- **Kontrolní hlášení (DPHKH1)** — vždy měsíčně (i pro kvartální plátce DPH)
- **Souhrnné hlášení (DPHSHV)** — EU dodání zboží/služeb, měsíčně

Najdeš v menu **Daně**.

> [!WARNING]
> **⚠️ Vygenerovaný XML je pouze pomůcka.** Před odesláním na EPO portál MFČR VŽDY ověř s účetní nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost — testováno na omezené sadě dat.

## Předpoklady před prvním podáním

V **Nastavení → Daňové nastavení** vyplň:

1. **Typ poplatníka** — FO (OSVČ) nebo PO (s.r.o., a.s.)
2. **Perioda DPH přiznání** — Měsíční nebo Kvartální
3. **Kód finančního úřadu** (např. 451 = Praha 1)
4. **Kód územního pracoviště (ÚzP)** — pokud existuje
5. **DIČ** v Identifikaci firmy (povinné)
6. Volitelně: CZ-NACE, datová schránka, sestavitel přiznání

> [!NOTE]
> **Kontrolní hlášení se podává VŽDY měsíčně**, i pro kvartální plátce DPH. Jen DPH přiznání může být kvartální.

## DPH přiznání (DPHDP3)

### Cesta: `Daně → DPH přiznání`

#### Topbar

- **Toggle Měsíčně / Kvartálně** — override podle `supplier.vat_period`
- **Month / Year picker** — pro měsíční; **Q1/Q2/Q3/Q4 picker** pro kvartální
- **Stáhnout XML** — generuje DPHDP3 verze 03.01 pro EPO portál

#### 4 KPI karty

- **DPH na výstupu** — z vydaných faktur (řádky 1-29)
- **DPH na vstupu** — z přijatých faktur (řádky 40+)
- **Daň k odvodu** NEBO **Nadměrný odpočet** (color coded)
- **Termín podání** — 25. den následujícího měsíce (po kvartálu) s **countdown** (kolik dní zbývá, červené pokud po termínu)

#### Trend graf

12 měsíců DPH na výstupu / vstupu / net due (rozdíl). Pro rychlou orientaci, jak se podání vyvíjí.

#### Tabulky DPH na výstupu (řádky 1-29) a vstupu (40+)

Per řádek: kód, popis, základ, DPH. Hodnoty se počítají agregací `invoice_items` / `purchase_invoice_items` per `vat_classification_code`.

### Jak fungují VAT klasifikační kódy

Každá faktura (nebo její řádek) má `vat_classification_code` (např. "1", "40", "5", "20"). Tento kód určuje na který **řádek DPH přiznání** položka patří.

**Standardní kódy (CZ, 2025-2026):**

| Vystavené (sale) | Přijaté (purchase) |
|---|---|
| **1** — Tuzemsko 21% (řádek 1 DPHDP3) | **40** — Tuzemsko 21% s odpočtem |
| **2** — Tuzemsko 12% (řádek 2) | **41** — Tuzemsko 12% s odpočtem |
| **3** — Osvobozeno (řádek 3) | **42** — Bez nároku na odpočet |
| **20** — EU dodání zboží (řádek 20) | **5** — Tuzemský reverse charge (řádek 10) |
| **22** — EU služby | **23** — EU acquisition zboží (řádek 3) |
| **26** — Export do 3. země | **24** — Přijatá služba z EU (řádek 5) |

### Auto-default klasifikace

Pokud na fakturu/řádek manuálně nevybereš kód, systém **automaticky přiřadí** podle:
- VAT sazby na řádku (`vat_rate_snapshot`)
- Reverse charge flagu na faktuře
- Direction (sale → vystavené kódy, purchase → přijaté kódy)
- Tax date faktury (pro budoucí změny sazby)

Mapování čte z databáze `vat_classifications` table. Pokud admin v Codebooks tabu **Klasifikace DPH** upraví sazbu (např. 21% → 20% k 1.1.2027), defaulter automaticky chytne novou hodnotu.

### Override per řádek nebo header

V editoru faktury (vystavené i přijaté) je sekce **Klasifikace** s VAT picker dropdown. Můžeš:
- Nechat prázdné → auto-default
- Vybrat konkrétní kód → manual override (např. specifický kód pro export)

## Kontrolní hlášení (DPHKH1)

### Cesta: `Daně → Kontrolní hlášení`

KH se podává **vždy měsíčně** s sekcemi:

- **A.1** — Plnění v režimu přenesené daňové povinnosti (dodavatel)
- **A.4** — Tuzemská plnění s DPH **nad 10 000 Kč** (individuálně)
- **A.5** — Tuzemská plnění s DPH **do 10 000 Kč** (sumace)
- **B.1** — Přenesená daňová povinnost (odběratel)
- **B.2** — Přijatá tuzemská plnění nad 10 000 Kč
- **B.3** — Přijatá tuzemská plnění do 10 000 Kč (sumace)

UI ukazuje **count řádků per sekce** + deadline countdown.

## Souhrnné hlášení (DPHSHV)

### Cesta: `Daně → Souhrnné hlášení`

Souhrnné hlášení (anglicky **Recapitulative Statement**) je výkaz **EU dodání zboží a služeb** v režimu B2B (vystavené faktury klientům — plátcům DPH v jiných členských státech EU). Podává se měsíčně.

> [!IMPORTANT]
> Souhrnné hlášení **podávají i identifikované osoby** (neplátci DPH), pokud poskytují B2B služby plátcům v EU, nebo nakupují zboží z EU nad limit.

### Co se generuje

Per VAT_ID protistrany + typ plnění:

| Kód | Typ plnění | VAT klasifikační kód v MyInvoice |
|---|---|---|
| **0** | Dodání zboží do jiného členského státu EU | **20** |
| **1** | Trojstranný obchod (prostředník) | **21** (pokud máte custom kód) |
| **2** | Poskytnutí služby s místem plnění v EU | **22** |
| **3** | Přemístění zboží | — |

Hodnota plnění = suma `total_without_vat` (základ daně, BEZ DPH) v CZK.

### Předpoklady

1. Vystavené faktury klientům **z EU** (country_iso2 ≠ CZ AND countries.is_eu = 1)
2. Klient má vyplněné **DIČ** (pro EU obvykle s prefixem země: SK1234567890, DE123456789, atd.)
3. Faktury musí mít VAT klasifikační kód 20 (zboží) nebo 22 (služby) — auto-default je řeší, ale ověř manuálně

### XML formát

Generuje DPHSHV verze 06.01. Per řádek VetaA1:
- `k_stat` = ISO2 kódu země (SK, DE, FR, …)
- `vatid_pod` = VAT ID s prefixem
- `kod_plneni` = 0/1/2/3
- `pln_hodnota` = celé Kč (zaokrouhleno)
- `pln_pocet` = počet faktur agregovaných pod tento řádek

### Termín podání

**Vždy 25. den následujícího měsíce** (stejně jako KH).

## Změna VAT sazby v budoucnu (např. 21% → 20% v 2027)

Pokud se sazba změní, postupuj:

1. **Codebooks → Sazby DPH:**
   - U existující CZ-21 nastav `valid_to = 2026-12-31`
   - Vytvoř novou CZ-20 s `rate_percent = 20.00`, `valid_from = 2027-01-01`
2. **Codebooks → Klasifikace DPH:**
   - U kódu "1" (vystavená 21%) — buď uprav `vat_rate` na 20, nebo nech a budou se používat **oba** kódy (jen historicky).
3. **Pro historické faktury 2026** — sazba 21% zůstane na řádku (snapshot, immutable po vystavení).
4. **Pro nové faktury 2027+** — systém auto-default najde novou sazbu/kód.

## Časté chyby

### "Chybí kód finančního úřadu"
→ Doplň v Nastavení → Daňové nastavení.

### "Faktura nemá VAT klasifikační kód"
→ Auto-default by ho měl přiřadit. Pokud ne, znamená to, že VAT sazba na řádku nemá v `vat_classifications` defaultní kód. Buď přidej kód v Codebooks, nebo vyber manual v editoru.

### "DIČ klienta není ve formátu CZxxxxxxxx"
→ Pro KH XML potřebuje DIČ být čisté číslo (bez prefixu CZ). Systém to ořezává automaticky, ale pokud klient nemá DIČ, A.4/B.2 řádky ho budou mít prázdné — ověř že klient má DIČ vyplněné.

## Podpora pro daňového poradce

Pokud XML zpracovává externí účetní:
1. Vyplň v Nastavení **Sestavitel přiznání** (jméno, funkce, telefon, email)
2. Doporučujeme: u poradce ověřit XML před prvním podáním
3. Pro testovací podání používej **EPO portal v módu "Testovací podání"** (https://adisspr.mfcr.cz)
