# 5. Přehled (dashboard)

Přehled je úvodní obrazovka po přihlášení — okamžitý report, kolik jsi vystavil,
co je po splatnosti, jaký je obrat za letošní a loňský rok, a kdo jsou tví
top klienti.

![Přehled (dashboard)](img/05_dashboard.webp)

## 5.1 KPI dlaždice (horní řada)

Šířka řady se přizpůsobí počtu aktivních měn (4–6 dlaždic):

| Dlaždice | Význam |
|---|---|
| **Obrat YYYY (CZK)** | Součet všech vystavených (i nezaplacených) faktur v CZK za aktuální rok. Pod číslem je pro porovnání obrat minulého roku ve stejném období. |
| **Obrat YYYY (EUR)** | Totéž pro EUR (jen pokud máš EUR měnu aktivní v Číselnících). |
| **Vystaveno YYYY** | Počet faktur za rok (všechny stavy kromě konceptů). |
| **Po splatnosti** | Suma neuhrazených faktur, které jsou po splatnosti. Zobrazená v CZK + EUR součtu, červené barvy. Klik proklikne na filtrovaný seznam. |
| **Ø doba úhrady** | Průměrný počet dní mezi vystavením a zaplacením (jen pro letošní zaplacené faktury). |

## 5.2 Top klienti — koláč

Levý koláč ukazuje **3 největší klienty letos**, pravý **3 největší loni**.
Hover nad výsečí ukáže jméno klienta + obrat. Klik na legendu odfiltruje.

> 💡 Pokud máš multi-supplier (více dodavatelů), koláč ukazuje data jen pro
> aktuálně vybraného dodavatele (přepínač v horní liště).

## 5.3 Stav faktur — koláč

Pravý koláč rozdělí letošní faktury podle stavu:

- 🟢 **Zaplaceno** — `paid`
- 🟣 **Odesláno** — `sent` (klientovi šel e-mail s PDF, čekáme na platbu)
- 🟡 **Vystaveno (neodesláno)** — `issued` (vystaveno, ale ještě jsme neposlali)
- 🟠 **Upomínka** — `reminded` (po splatnosti, byla odeslána upomínka)
- ⚫ **Storno / dobropis** — `cancellation` / `credit_note`

## 5.4 Obrat po měsících (line / bar chart)

Spodní dva grafy ukazují měsíční obrat (CZK a EUR samostatně) — letošní rok
plnou barvou, minulý rok prázdnou pro porovnání. Hover nad sloupcem ukáže
přesnou částku.

## 5.5 Po splatnosti + nezaplacené faktury

Pod grafy je tabulka:

- **Po splatnosti** (červené) — faktury, které jsou v stavu `sent` / `issued` /
  `reminded` a překročily splatnost. Tlačítko **Upomínka** odešle upomínací
  e-mail.
- **Nezaplacené** — faktury v stavu `sent` / `issued` / `reminded`, ještě před
  splatností.

Klik na číslo faktury otevře [Detail faktury](12_Faktura_PDF.md).

## 5.6 Rychlé akce (vpravo nahoře)

- **+ Nová faktura** — otevře [Editor faktury](11_Faktura_editor.md), prázdný koncept
- **+ Nový klient** — otevře modal pro založení klienta (s ARES lookupem)

## 5.7 Aktualizace dat

Statistiky se nepočítají v reálném čase — používají agregační cache
(`project_revenue_cache`, `client_revenue_cache`), která se přepočítá pokaždé,
když vystavíš / zrušíš / označíš zaplacenou fakturu. Pokud někdy zjistíš, že
čísla nesedí (např. po manuální úpravě v DB), spusť z CLI:

```bash
php api/bin/recompute-stats.php
```

> 🛈 Sample data (vygenerovaná během setup wizardu) automaticky přepočítají
> stats hned po dokončení — nemusíš nic dělat.

## 5.8 Vzhled — světlý a tmavý režim

V horní liště (vpravo, vedle přepínače jazyka) je přepínač barevného motivu se
třemi stavy:

- **Systém** — řídí se nastavením operačního systému / prohlížeče
  (`prefers-color-scheme`). Výchozí volba.
- **Světlý** — vždy světlé téma.
- **Tmavý** — vždy tmavé téma.

Volba se ukládá do prohlížeče (per zařízení) a platí napříč celou aplikací
včetně grafů. Na mobilu je přepínač v rozbalovacím menu (☰) dole, vedle
přepínače jazyka.
