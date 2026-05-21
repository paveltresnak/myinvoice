# 25. Daň z příjmů (DPFO / DPPO)

V menu **Daně → Daň z příjmů** najdeš nástroj pro **roční přiznání**:
- **DPFO** (Daň z příjmů Fyzických osob, formulář DPFDP5) — pro OSVČ
- **DPPO** (Daň z příjmů Právnických osob, formulář DPPDP9) — pro s.r.o., a.s.

> [!CAUTION]
> **⚠️ Pouze foundation — výkaz NENÍ kompletní.** Tento výkaz obsahuje jen orientační čísla z fakturačního systému (tržby z vydaných faktur, náklady z přijatých) a identifikaci poplatníka. **Skutečné daňové přiznání vyžaduje účetní data, která MyInvoice.cz neeviduje**:
> - Daňové odpisy hmotného majetku
> - Mzdové náklady + odvody
> - Sociální a zdravotní pojištění OSVČ
> - Zálohy na daň zaplacené během roku
> - Slevy na dani (na poplatníka, manželku, děti)
> - Daňově neuznatelné výdaje
>
> **Před podáním VŽDY doplňte** ve spolupráci s účetní/poradcem nebo v účetnickém software.

## K čemu to slouží

XML kostra obsahuje:
- **Identifikaci poplatníka** (z Nastavení → Daňové)
- **Identifikaci finančního úřadu** (kód FÚ + ÚzP)
- **Orientační hospodářský výsledek** (tržby − náklady z faktur) jako startovací bod
- **Upozornění v XML**: "Tato čísla jsou orientační z invoicing systému, ne účetní výkaz."

Účetní si pak doplní:
- Daňové odpisy (vyžaduje evidence majetku)
- Mzdové náklady + odvody
- Soc + zdrav pojištění OSVČ
- Slevy na dani
- ...

## Použití

1. **Cesta:** `Daně → Daň z příjmů`
2. **Toggle DPFO / DPPO** v topbar
3. **Year picker** — default předchozí rok (daně se podávají za uplynulý)
4. **4 karty:** Tržby orientačně / Náklady / Hospodářský výsledek / Termín podání
5. **Stáhnout XML kostru** — generuje DPFDP5 / DPPDP9 verze 05.01 / 09.01

## Termíny podání

- **Daň z příjmů FO (OSVČ bez účetní):** **1.4. následujícího roku** (např. za rok 2026 → do 1.4.2027)
- **S účetní:** prodloužený termín **1.7.**
- **Daň z příjmů PO:** podle účetního období (typicky 1.4. nebo 1.7.)

## Doporučený workflow

1. Vyplňte v MyInvoice **všechny faktury** za rok (vydané + přijaté)
2. Stáhněte XML kostru jako referenci pro účetní
3. Účetní:
   - Použije čísla z MyInvoice jako základ
   - Doplní účetní data (odpisy, mzdy, atd.)
   - Vygeneruje finální XML ve svém software (Pohoda, Money S3, …)
4. Podání na EPO portál MF ČR

## Kde získat plnohodnotný výkaz

MyInvoice.cz je **invoicing software**, ne plné účetnictví. Pro plný daňový výkaz použijte:
- **Pohoda / Money S3 / Helios** — desktop accounting software
- **iDoklad / Fakturoid** — online (jsou napojené přes naši **Externí integrace**)
- **Externí účetní** — předá data v Pohoda XML / ISDOC formátu, MyInvoice ji umí importovat

## Plánované rozšíření (v4.0+)

V budoucnu plánujeme:
- **Evidence majetku + odpisy** (modul Assets)
- **Evidence záloh na daň** (per quarter)
- **Slevy na dani** v Nastavení
- **Plný DPFO / DPPO výkaz** s validací proti XSD

Do té doby je nástroj jen jako **startovací bod**.
