# 7. Klienti

Klient = firma nebo osoba, které vystavuješ faktury. Každý klient má alespoň
jeden hlavní e-mail (povinný kontakt). Pod klientem můžeš mít jednu nebo více
**zakázek** (viz [8. Zakázky](08_Zakazky.md)) — typicky 1 zakázka = 1 projekt
nebo dlouhodobá spolupráce.

## 7.1 Seznam klientů

V hlavním menu klikni **Klienti**.

![Seznam klientů](img/06_klienti_list.webp)

Tabulka ukazuje:

| Sloupec | Význam |
|---|---|
| Jméno | Název firmy nebo osoby (klikatelný — otevře detail) |
| IČO | České IČO, pokud je vyplněné |
| Stát | 2-písmenný kód (CZ, SK, DE, …) |
| Měna | Výchozí měna pro nové faktury |
| Hlavní e-mail | Kontakt pro odesílání faktur |
| Obrat letos | Suma vystavených faktur v aktuálním roce, v měně klienta |

Nad tabulkou je vyhledávač (jméno / IČO) a filtr stavu (`Aktivní` / `Archivovaní`).

### 7.1.1 Akce na řádku

- **Klik na jméno** → detail klienta
- **Tlačítko Smazat** se zobrazí jen pokud klient nemá žádné faktury ani zakázky.
  Pokud má, smazání vrátí 409 a UI tlačítko skryje.

## 7.2 Nový klient

Tlačítko **+ Nový klient** vpravo nahoře otevře modal.

![Nový klient — ARES lookup](img/06_klient_novy.webp)

Doporučený postup pro českého klienta:

1. Zadej **IČO** (8 cifer).
2. Klikni **Načíst z ARES** — server stáhne data z oficiálního registru a
   předvyplní: název firmy, DIČ, adresu, stát.
3. Doplň **Hlavní e-mail** (povinný).
4. (Volitelně) změň **Měnu** (CZK / EUR / …) a **Jazyk faktury** (CZ / EN).
5. Pokud je klient z EU s DIČ, klikni **Ověřit DIČ (VIES)** — pokud je platné,
   přidáme stříbrný badge „VIES OK".
6. (Volitelně) zaškrtni **Reverse charge** — faktura v této měně bude bez DPH
   s textem „Daň přiznává odběratel".
7. **Uložit**.

### 7.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Firma / jméno | Název na faktuře |
| Křestní jméno + Příjmení | Jen pro fyzické osoby (volitelné) |
| IČO | České IČO (8 cifer); slovenské také funguje s ARES SK |
| DIČ | Daňové ID; ČR „CZ12345678", SK „SK1234567890", EU různě |
| Ulice / Město / PSČ / Stát | Adresa pro fakturu |
| Hlavní e-mail | **Povinný** — pro odesílání faktur a upomínek |
| Telefon | Volitelný |
| Jazyk | `cs` nebo `en` — určuje jazyk PDF, e-mailových šablon, currency formátu |
| Výchozí měna | Pro nové faktury (lze přepsat per faktura) |
| Výchozí DPH | Volitelný override (jinak se použije systémový default) |
| Reverse charge | Zatrhni pro EU B2B klienty s DIČ — DPH 0 % + text „Daň přiznává odběratel" |
| Splatnost | Preset **7 dnů / 14 dnů / Měsíc / Vlastní**, nebo **Použít výchozí** = dědit z dodavatele. „Měsíc" = kalendářní měsíc (1. 2. → 1. 3., 31. 1. → 28. 2.), ne fixních 30 dní |
| Poznámka | Interní text — nezobrazí se na faktuře |

## 7.3 Detail klienta

Klik na jméno v seznamu → detail.

![Detail klienta](img/06_klient_detail.webp)

Detail má 4 záložky:

### 7.3.1 Přehled

Sumář: kontakt, výchozí nastavení, obraty (letos / loni), počet zakázek,
počet faktur podle stavu.

### 7.3.2 Zakázky

Seznam zakázek pod klientem. Tlačítko **+ Nová zakázka** otevře editor —
viz [8. Zakázky](08_Zakazky.md).

### 7.3.3 Faktury

Seznam faktur klienta (všechny zakázky + faktury bez zakázky). Filtr stavu
+ pagination.

### 7.3.4 Aktivita

Activity log — kdo a kdy klienta vytvořil / upravil / odeslal mu fakturu.

## 7.4 Editace klienta

Na detailu klikni **Upravit** (ikona tužky vpravo nahoře).

Změny se okamžitě projeví na nových fakturách. Faktury, které už jsou
**vystavené** (status `issued` a vyšší), mají vlastní **snapshot** údajů
klienta — tam se editace neprojeví. Tím se zajišťuje neměnnost vystavených
dokladů.

## 7.5 Archivace klienta

Klik na **Archivovat** — klient se schová z výchozího filtru, ale data zůstanou
zachována (faktury, statistiky). Archivovaného klienta najdeš ve filtru
„Archivovaní" v seznamu, kde ho můžeš obnovit (**Obnovit**).

## 7.6 Tipy

- **ARES** funguje jen pro česká IČO. Pro SK použij interní lookup `/api/clients/ares-lookup-sk?ic=...`.
- **VIES** je pomalý (~1–2 sekundy) a občas nedostupný — výsledek se cachuje
  na 24 hodin v `vies_cache` tabulce.
- Pokud klient nemá IČO (fyzická osoba), zadej alespoň jméno + adresu ručně.
- Reverse charge se nastavuje **per klient**, ale lze přepsat per faktura
  v editoru.
