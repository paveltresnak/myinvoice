# Dokumenty

Sekce **Dokumenty** je úložiště pro libovolné soubory, které k podnikání patří,
ale nejsou to přímo faktury — smlouvy, naskenované doklady, XML/ISDOC, datové
zprávy ze schránky (ZFO), elektronické podpisy (P7S), tabulky a další. Najdeš ji
v menu hned **před sekcí Daně**.

Vše je odděleně **per dodavatel** (firma/IČO) — co nahraješ pod jednou firmou,
nevidíš pod jinou.

![Dokumenty](img/26_dokumenty.webp)

## Organizace — složky, vazby a tagy

Dokumenty organizuješ třemi způsoby, které se doplňují:

- **Strom složek** — klasické složky a podsložky jako na disku. Složky jsou
  „virtuální" (soubor fyzicky leží podle svého otisku), takže přesun složky je
  okamžitý a nic se nekopíruje.
- **Vazby na entitu** — dokument můžeš připojit ke konkrétní **vystavené faktuře,
  přijaté faktuře, klientovi nebo zakázce**. Vazba je oboustranná: uvidíš ji
  jak v detailu dokumentu, tak v panelu *Dokumenty* v detailu té faktury/klienta.
- **Tagy** — volné štítky pro průřezové hledání (např. `smlouva`, `2026`, `GDPR`).

## Nahrávání

V pravém horním rohu jsou tři způsoby (potřebuješ právo zápisu):

- **Nahrát** — vybere jeden nebo více souborů.
- **Nahrát složku** — vybere celý adresář z disku; jeho podsložky se v aplikaci
  automaticky vytvoří.
- **Drag & drop** — přetáhni soubory **nebo celé složky** kamkoli do okna sekce.
  Struktura podsložek se zrekonstruuje.

Nové soubory se nahrají do **aktuálně otevřené složky**.

### Soubory ZIP — dva režimy

Přepínač **Soubory ZIP** určuje, co se stane s nahraným `.zip`:

- **Rozbalit a kategorizovat** — archiv se bezpečně rozbalí, podsložky uvnitř se
  promítnou do stromu složek a každý soubor se uloží samostatně.
- **Nahrát jako jeden ZIP** — archiv zůstane jako jeden soubor ke stažení.

### ZFO — datové zprávy ze schránky

Když nahraješ **ZFO** (stažená nebo odeslaná datová zpráva), aplikace ji
**automaticky rozbalí**:

- uloží se **veškerá metadata zprávy** — ID zprávy, odesílatel, příjemce,
  předmět, datum dodání i odeslání (zobrazí se v detailu v panelu *Datová zpráva*),
- jednotlivé **přílohy** zprávy se uloží jako samostatné dokumenty navázané na
  původní ZFO,
- případný odpojený podpis **P7S** se napáruje na podepsaný dokument.

## Náhledy a otevírání

U PDF a obrázků se generují **náhledy (thumbnaily)** a v detailu je **inline
náhled** přímo v aplikaci (stejně jako u přijatých faktur). Ostatní typy souborů
se vždy nabízejí ke **stažení** — z bezpečnostních důvodů se nikdy nezobrazují
přímo v prohlížeči.

## Vyhledávání

Pole **Hledat** nahoře prohledává **názvy, popisy i obsah** dokumentů. U PDF
s textovou vrstvou, dokumentů Office (DOC/XLS) a XML se text indexuje při nahrání,
takže najdeš dokument i podle slova uvnitř. Naskenované PDF bez textové vrstvy
zůstává dohledatelné podle názvu a tagů.

## Párování s fakturami a klienty

V detailu dokumentu v sekci **Souvisí s** přidáš vazbu přes **našeptávač** — píšeš
a aplikace průběžně nabízí **vystavené i přijaté faktury, klienty a zakázky**.
Hledat můžeš podle **čísla dokladu, názvu firmy, e-mailu, IČ/DIČ, názvu nebo čísla
projektu**. Klikneš na nabídku a vazba je hotová.

Obráceně: v detailu **klienta, vystavené faktury, přijaté faktury i zakázky**
najdeš panel **Dokumenty**, kde vidíš všechny připojené soubory a přes tlačítko
*Připojit dokument* k nim přidáš další.

## Hromadné akce

Zaškrtni více dokumentů **i složek současně** (v mřížce i seznamu) a v liště nahoře:

- **Přesunout** do jiné složky (přes stromový výběr cíle),
- **Otagovat** (přidat štítky — jen u souborů),
- **Stáhnout ZIP** vybraných souborů i složek (export zachová stromovou strukturu složek),
- **Smazat** (do koše — u složky včetně obsahu).

Velikost každé složky je vidět přímo v dlaždici. Na mobilu (bez najetí myší) se akce složky (přejmenovat/smazat) odkryjí prvním ťuknutím a spustí až druhým — ochrana proti nechtěnému smazání.

## Koš

Smazání je **nevratné až po vysypání koše**. Tlačítko **Koš** přepne na seznam
smazaných dokumentů i složek, kde je můžeš **Obnovit**. **Vysypat koš** je trvale
odstraní z databáze i z disku (soubor se fyzicky smaže jen tehdy, když na něj
neukazuje žádný jiný dokument — kvůli deduplikaci).

## Oprávnění

- **Jen pro čtení (readonly)** — procházení, náhledy, fulltext, stahování a export.
- **Účetní / admin** — navíc nahrávání, mazání, přesouvání, tagy a vazby.

## Zálohování

Dokumenty zálohuje **samostatná plánovaná úloha** `cron-backup-documents`
(viz *Systém → Plánované úlohy*), oddělená od zálohy PDF faktur. Zálohuje celé
úložiště `storage/documents/` (všechny typy souborů) do
`storage/backup/{db}-documents-RRRR-MM-DD.zip` s retencí 30 denních + 12 měsíčních
záloh. Náhledy se nezálohují (regenerují se).

## Bezpečnost

Sekce přijímá libovolné soubory, proto je upload chráněný: typ se ověřuje podle
**obsahu** (ne podle přípony), spustitelné soubory a HTML/SVG jsou odmítnuty,
rozbalování ZIP má ochranu proti „zip bombě" i průniku cesty (Zip Slip) a
parsování ZFO/XML je chráněno proti útokům přes XML entity (XXE).
