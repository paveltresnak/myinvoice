# Money S3 → MyInvoice — migrační nástroj (REST API)

Převede data z účetního systému **Money S3** (Seyfor) do **MyInvoice** přes Public REST API v1.
Migruje **kontakty + vydané faktury (vč. položek) + přijaté faktury** a umí sladit
datum zdanitelného plnění (DUZP) s tvými reálně podanými přiznáními k DPH.

> **Ověřeno na reálných datech:** výstupní DPH generovaná z MyInvoice se shoduje s podanými
> přiznáními a kontrolním hlášením (ověřeno napříč více lety, doklad po dokladu).

## Předpoklady
- **Money S3** na Windows (data přes COM `mon2kdbe.BFTable`, 32-bit). **Testováno na Money S3 verze 26.300** (formát BF13 `.DAT`).
- **MyInvoice ≥ 4.1.4** běžící, dostupné přes HTTPS (4.1.0 = EU reverse-charge v cizí měně; 4.1.4 = opravy KH sekcí, issue #35).
- **API token** (Personal Access Token, scope `read_write`): MyInvoice → *Systém → API tokeny*.
- **Python 3** (stdlib; pro fáze 3/4 navíc `pypdf`), **32-bit Windows PowerShell**.
- Konfigurace přes proměnné prostředí — viz `.env.example`. **Žádná data/credentials se necommitují** (`.env` je v `.gitignore`).

## Fáze

| Fáze | Skript | Co dělá |
|---|---|---|
| 1 | `export-money.ps1` | Money S3 (COM, read-only) → `money-export.json` |
| 2 | `import-myinvoice.py` | JSON → MyInvoice REST API (idempotentní) |
| 3 *(volitelná, plátci DPH)* | `correct-duzp-from-kh.py` | sladí DUZP migrovaných faktur s podanými KH → SQL |
| 4 *(volitelná)* | `validate-dph.py` | porovná DPH z MyInvoice proti podaným přiznáním |

```powershell
# 1) export z Money (32-bit PowerShell)
$env:MONEY_ICO="12345678"
C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe -NoProfile -File export-money.ps1
```
```bash
# 2) import do MyInvoice
MI_URL="https://myinvoice.example.com:9443"  MI_TOKEN="mi_pat_..."  python import-myinvoice.py
# 3) (volitelně) sladění DUZP s podanými KH -> SQL ke kontrole a spuštění
KH_DIR="cesta/ke/kontrolnim-hlasenim"  python correct-duzp-from-kh.py
```

---

## Řešené případy a scénáře

Migrace pokrývá tyto reálné situace (a jak je řeší):

### Vydané faktury
- **Běžná tuzemská faktura (21 %)** — položky se skládají z `VFaktPol` (`PocetMJ × Cena`, sazba `SazbaDPH`), klient z `Odberatel` (index do adresáře). Datum vystavení = DUZP.
- **Více sazeb / sleva** — každý řádek vlastní sazba; sleva jako záporný řádek.
- **Neplátcovské období** — faktury před registrací k DPH (0 %); převedou se beze změny.
- **Post-datovaná / zpětně zaúčtovaná faktura** — DUZP na konci měsíce, ale `DatUcPr` (zaúčtování) až v dalším měsíci. Exportér bere `PlnenoDPH` (DUZP), takže spadne do správného období; `DUZP_OVERRIDE` / fáze 3 jen pro ruční korekce.
- **Year-end přesun** — faktura z 31. 12. vykázaná až v dalším roce (časté u OSVČ). Nemusí být v žádném KH → ponechá se dle vystavení; do žádného období „nesedne" (vědomá výjimka).

### Přijaté faktury
- **Běžná tuzemská přijatá (21 %)** — v Money často bez položek → 1 souhrnný řádek, základ = celkem / 1,21.
- **EU pořízení zboží v režimu reverse charge** — zahraniční dodavatel (DIČ mimo CZ), tuzemská daň se samovyměřuje. Migrace **věrně v původní měně** (např. EUR) + denní kurz: `currency_id` dle měny, `exchange_rate` = kurz, základ v cizí měně = `celkem / kurz` (RC = bez DPH v dokladu), `reverse_charge=true`, `vat_classification_code='23'`, `vendor_invoice_number` = číslo dokladu dodavatele (→ KH A.2 `c_evid_dd`). Výsledek: DPHDP3 ř. 3 (pořízení z JČS, CZK = cizí měna × kurz) + samovyměřená daň + ř. 43 mirror (daňově neutrální) + KH A.2. **Pozor: dodavatel musí mít zemi mimo CZ** (jinak A.2 `k_stat` vyjde chybně CZ); detekce dle prefixu DIČ. Vyžaduje MyInvoice **≥ 4.1.0**.
- **Prodej + zápočet (protiúčet) na jednom dokladu** — např. nákup vozu s výkupem starého protiúčtem: nákup = tvůj odpočet (přijatá), výkup = tvůj prodej (vydaná, samostatný doklad). Stejný partner pak vystupuje jako odběratel i dodavatel.
- **Úhrady přijatých** — Money je nemusí evidovat; volitelně lze označit všechny jako uhrazené (`MI_MARK_PAID=1`).
- **Dobropis přijatý (opravný daňový doklad)** — Money má příznak `Dobropis`; importuje se jako `document_kind='credit_note'` se **zápornými** částkami (dle manuálu MyInvoice). `vendor_invoice_number` = číslo opravného dokladu dodavatele. Daň se promítne do ř. 40 (záporně). Pozor na zařazení v KH — viz Limity.

### Datová úskalí Money S3
- **Ghost záznamy** — COM čte i smazané/nahrazené doklady (GUI je neukazuje). Dedup dle `Doklad`, ponechat nejvyšší `Cislo`.
- **Smazané unikátní doklady (limitace)** — COM (`mon2kdbe.BFTable`) čte i doklady smazané v Money GUI, které nemají duplikát (ghost-dedup je nezachytí), a **nevystavuje příznak smazání** (RecCount i indexy je zahrnují). Nelze je proto přes COM odfiltrovat → po importu zkontrolovat anomálie (nesmyslně malý/rozbitý doklad, např. fragment selhaného importu) a ručně smazat.
- **Cross-year duplikát** — doklad z přelomu roku vedený ve dvou ročnících (ROK.xxx); ponechat ten, pod kterým byl podán.
- **Adresář nemá e-maily** → placeholder `ico@imported.local`; reálné doplnit ručně po importu.

### DPH agenda
- **Velké vs malé doklady** — KH dělí na A.4/B.2 (> 10 000 Kč, per doklad) a A.5/B.3 (≤ 10 000 Kč, agregát). To určuje, co lze opravit z KH (jen velké).
- **Opravné / dodatečné přiznání** — období s opravou; pro srovnání se bere efektivní (opravená) hodnota. Pozor na § 145a (dodatečné podané před vyměřením FÚ se sloučí s řádným).

---

## Datový model Money S3 — klíčová úskalí

- **DUZP je v poli `PlnenoDPH`** (datum zdanitelného plnění, povinné u plátců DPH) — to je **autoritativní období DPH**. Exportér ho čte přímo. (U neplátce nebo starších dokladů může být prázdné → exportér použije fallback `Vystaveno`/`DatUcPr`.)
- **`Vystaveno`** = datum vystavení, **`Splatno`** = splatnost, **`Uhrazeno`** = datum úhrady.
- **`DatUcPr`** (datum účetního případu / zaúčtování) **NENÍ DUZP** — u dokladu z konce měsíce bývá až v dalším měsíci a poslal by ho do špatného období DPH. (Časté úskalí — exportér proto bere `PlnenoDPH`, ne `DatUcPr`.)
- **Položky** vydaných: vazba přes sloupec `Cislo`. **Partner**: 1-based index v `Odberatel`/`Dodavatel` do `AdresarF`.

## DPH přesnost — DUZP

Primárním zdrojem DUZP je pole **`PlnenoDPH`** z Money (čte ho exportér přímo) — období DPH tak sedí bez dalších kroků.

**Volitelné křížové ověření (fáze 3)** proti tvým **podaným kontrolním hlášením**, když chceš jistotu shody s tím, co je u FÚ:
- **A.4** (vydané > 10 000): `c_evid_dd` (číslo dokladu) + `dppd` (DUZP) → přímé sladění.
- **B.2** (přijaté > 10 000): `dic_dod` + `zakl_dane1` + `dppd` → párování DIČ + základ.

`correct-duzp-from-kh.py` to vytáhne ze `DPHKH1-*.zip` (strojový XML) i z PDF (fallback) a vygeneruje SQL ke kontrole. Užitečné hlavně tam, kde byl odpočet uplatněn v jiném období, než je DUZP.

## Limity (čím je dáno)
- **DUZP malých přijatých (B.3 ≤ 10 000)** — z `PlnenoDPH` (v Money jsou). V KH figurují jen agregátem, takže fáze 3 je neumí ověřit po jednotlivých dokladech — spoléhá se na `PlnenoDPH`. (Rozpad DPH po sazbách rekonstruuje importér z celku přes `DEFAULT_PURCHASE_VAT_RATE`, ne z položek → drobné haléřové zaokrouhlení.)
- **KH B.2 — VYŘEŠENO ve v4.1.4 (issue #35):** dříve se EU pořízení (A.2) duplikovalo i do B.2 a přijatý dobropis nad 10 000 Kč padal do sumace B.3. Od v4.1.4 opraveno (daň byla správně i předtím, šlo o zařazení do sekcí). Na starší verzi limitace platí.
- **⚠️ Snížená sazba DPH u přijatých (10 %/15 %) — DŮLEŽITÉ:** přijaté faktury jsou v Money S3 typicky **bez položek** (header), a COM (`mon2kdbe.BFTable`) **nevystavuje rozpad DPH po sazbách** (jen `CelkemSDPH`; `KodDPH` sazbu nerozlišuje). Importér je proto účtuje sazbou `DEFAULT_PURCHASE_VAT_RATE` (21 %). **Doklady se sníženou sazbou** (např. **časopisy/periodika** 10 %, potraviny 15 %) je nutné po importu **opravit ručně podle dokladu** (sazba/rozpad). Pozn.: MyInvoice číselník `vat_rates` historické 10/15 % standardně neseeduje (jen 21/12/0 od 2024) — nahlášeno autorovi (**issue #36**, seed `valid_to`); schéma to podporuje.
- **Daň z příjmů (DPFDP5)** — v MyInvoice zatím MVP (počítá příjem včetně DPH, bez podpory výdajových paušálů); ověř před použitím k podání.
- **Skenovaná PDF** bez textové vrstvy nelze strojově číst.

## Validace (fáze 4)
`validate-dph.py` porovná měsíční výstupní/celkovou DPH z MyInvoice (export z DB) proti
podaným přiznáním (`DPHDP3-*.xml` / `DPH_MFCR-*.xml`, oprava-aware). Doporučeno doplnit
namátkovou kontrolu několika dokladů proti PDF faktur.

## Konfigurace importéru (`import-myinvoice.py`, nahoře)
| Proměnná | Význam |
|---|---|
| `MI_URL` / `MI_TOKEN` / `MI_EXPORT` | URL, API token, cesta k JSON |
| `DEFAULT_PURCHASE_VAT_RATE` | sazba přijatých bez položek (def. 21) |
| `MI_MARK_PAID` (env) | `1` = označit všechny přijaté jako uhrazené (když je zdroj neeviduje) |
| `MI_SLEEP` (env) | prodleva mezi zápisy v s (default 0; `0.1` ≈ 600/min při server rate-limitu) |
| `DUZP_OVERRIDE` | ruční oprava DUZP post-datovaných `{"doklad":"YYYY-MM-DD"}` |
| `VAT_MAP` | mapování sazby → vat_rate_id (ověř `/api/v1/codebooks/vat-rates`) |

## Re-import (čistý)
1. Snapshot / záloha MyInvoice DB.
2. `truncate-transactional.sql` — vyprázdní transakční tabulky (clients, invoices*, purchase_invoices*, …) — NE supplier/users/api_tokens/číselníky.
3. `export-money.ps1` → `import-myinvoice.py` (DUZP z `PlnenoDPH`; `MI_MARK_PAID=1` pro „vše uhrazené") → *(volitelně)* `correct-duzp-from-kh.py` + `validate-dph.py`.

## Soubory
```
export-money.ps1            # fáze 1: Money COM → JSON (read-only, 32-bit PowerShell)
import-myinvoice.py         # fáze 2: JSON → REST API (idempotentní, EU reverse charge, validace IČ)
truncate-transactional.sql  # čistý re-import: vyprázdní transakční tabulky (NE supplier/users/api_tokens/číselníky)
correct-duzp-from-kh.py     # (volitelné) křížové ověření DUZP z podaných KH
validate-dph.py             # (volitelné) srovnání měsíční DPH proti podaným přiznáním
validate-kh-doklady.py      # (volitelné) kontrola čísel dokladů A.4 (vydané) + B.2 (přijaté) vs podaná KH
README.md / LICENSE
```

## Licence / sdílení
MIT. Komunitní nástroj. Před sdílením odstraň konkrétní IČO/jména/URL z konfigurací.
