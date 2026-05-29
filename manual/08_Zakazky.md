# 8. Zakázky

Zakázka = projekt nebo dlouhodobá spolupráce s klientem. Pod jedním klientem
můžeš mít více zakázek (vývoj e-shopu, správa marketing kampaně, retainer).

Zakázka má smysl, pokud chceš:

- **Sledovat obrat per projekt** (kolik jsme za rok 2026 vyfakturovali za
  „Migraci CRM").
- **Mít separátní fakturační e-maily** (jiná účetní pro každý projekt
  klienta).
- **Sledovat budget** (celkový rozpočet projektu, jednotlivý měsíční / roční
  limit).
- **Nastavit specifickou hodinovou sazbu** (jiná pro retainer než pro projekt).
- **Generovat čísla projektů / smluv** v hlavičce faktury.

Pokud nepotřebuješ tyhle věci, můžeš fakturovat **bez zakázky** — faktura se
přiřadí jen ke klientovi.

## 8.1 Seznam zakázek

V hlavním menu klikni **Zakázky**.

![Seznam zakázek](img/07_zakazky_list.webp)

Tabulka:

| Sloupec | Význam |
|---|---|
| Klient | Pod kterým klientem |
| Název | Identifikace zakázky |
| Stav | `Aktivní` / `Pozastavené` / `Uzavřené` |
| Hodinová sazba | Default sazba pro položky typu „hodina" |
| Měna | Výchozí pro nové faktury |
| Splatnost | Preset **7 dnů / 14 dnů / Měsíc / Vlastní**; „Měsíc" = kalendářní měsíc. Zakázka přebíjí klienta i dodavatele |
| Obrat letos | Suma vystavených faktur na zakázce |

Nahoře je filtr **Klient** (dropdown se všemi klienty) — zúží seznam jen na
zakázky vybraného klienta.

## 8.2 Nová zakázka

Z **detailu klienta → záložka Zakázky → + Nová zakázka**.

![Nová zakázka](img/07_zakazka_novy.webp)

| Pole | Význam |
|---|---|
| Název | Krátké pojmenování („Web e-shop", „Retainer 2026") |
| Číslo projektu | Volitelné — bude na faktuře v rámečku „Projekt č." |
| Číslo smlouvy | Volitelné — bude na faktuře v rámečku „Smlouva č." |
| Hodinová sazba | Defaultní sazba pro položky typu „hodina" v editoru faktury |
| Měna | CZK / EUR / … |
| Splatnost | Preset 7/14 dnů, **Měsíc** (kalendářní) nebo **Vlastní** počet dní |
| Rozpočet — celkem | Volitelný horní limit za celou zakázku (varování v editoru, pokud překročíš) |
| Rozpočet — ročně | Volitelný roční limit |
| Rozpočet — měsíčně | Volitelný měsíční limit |
| Stav | `Aktivní` (default) / `Pozastavené` / `Uzavřené` |
| Poznámka | Interní text |

### 8.2.1 Fakturační e-maily

Pod hlavními poli je sekce **Fakturační e-maily**. Můžeš přidat až 3 adresy,
kam se má kromě hlavního klienta posílat každá vystavená faktura — typicky
účetní, projektový manažer, asistentka.

| Pole | Význam |
|---|---|
| Pozice | 1 / 2 / 3 (řazení) |
| E-mail | Povinný |
| Popisek | Volitelný — „účetní", „PM", „asistentka" |

Při odesílání faktury jdou kopie na: `klient.hlavni_email + zakazka.fakturacni_emaily[]`.
Pokud faktura nemá zakázku, jde jen na hlavní e-mail klienta.

## 8.3 Detail zakázky

Klik na název zakázky v seznamu.

![Detail zakázky](img/07_zakazka_detail.webp)

Detail ukazuje:

- **Přehled** — všechny údaje + obrat letos / loni / celkem
- **Faktury** — seznam faktur na zakázce (filtr stavu)
- **Výkazy víceprací** — pokud používáš work_report v editoru, zde je seznam
  všech výkazů (PDF se sčítá per faktura)

## 8.4 Editace zakázky

Tlačítko **Upravit** vpravo nahoře.

Změna **hodinové sazby** se projeví jen na NOVÝCH položkách v editoru faktury.
Stávající koncepty si zachovají původní sazbu, dokud je ručně neupravíš.

## 8.5 Pozastavení / uzavření zakázky

- **Pozastavit** — zakázka zůstává v seznamu, ale v editoru faktury se objeví
  varování „Pozastaveno". Pro nové faktury použij jinou.
- **Uzavřít** — zakázka se schová z výchozího filtru. Faktury zůstávají,
  obrat se počítá. Lze obnovit zpět na `Aktivní`.
- **Smazat** je možné jen pokud zakázka nemá žádné faktury.

## 8.6 Schvalování výkazu zákazníkem

V editoru zakázky (sekce **Schvalování výkazu**) je checkbox
**„Vyžaduje schválení výkazu práce zákazníkem"**.

Když ho zaškrtneš, faktury patřící této zakázce, **které mají výkaz víceprací**,
nepůjde vystavit, dokud zákazník výkaz neschválí přes e-mailový odkaz. Po
schválení se faktura **automaticky vystaví a odešle**.

> 💡 Pokud na zakázce vystavíš fakturu **bez výkazu víceprací** (např. fixní
> paušál), schvalovací proces se přeskočí — faktura jde vystavit normálně.
> Schvalování se týká jen faktur s výkazem.

### Kam jde e-mail se žádostí o schválení

| Konfigurace zakázky | Příjemce schvalovacího e-mailu |
|---|---|
| Zakázka má **fakturační e-maily** (sekce 7.2.1) | Jen na ně, hlavní e-mail klienta NEDOSTANE |
| Zakázka **nemá** fakturační e-maily | Hlavní e-mail klienta |

> Záměr: na schvalovacím e-mailu může být účetní (fakturační e-mail), zákazník
> se o vícepracích dozví až s hotovou fakturou.

Detailní popis workflow schvalování (tlačítka, stavy, public stránka) viz
kapitola [11. Faktura — editor a výkaz víceprací § 11.7](11_Faktura_editor.md).

## 8.7 Tipy

- **Bez zakázky lze fakturovat** — v editoru nech pole „Zakázka" prázdné.
  Užitečné pro jednorázové faktury (poradenství, license).
- **Hodinová sazba je default** — vždy ji můžeš v editoru přepsat per
  položka.
- **Číslo projektu / smlouvy** se na PDF zobrazí jen pokud je vyplněné.
- **Fakturační e-maily** doporučujeme vždy — admin firmy obvykle nemá rád, když
  faktura jde jen jemu osobně místo na účetní oddělení.
