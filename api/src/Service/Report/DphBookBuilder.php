<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder pro **Knihu DPH** (interní VAT žurnál).
 *
 * Není to podání FÚ — je to interní účetní pomůcka která seskupí vystavené
 * i přijaté faktury podle řádků DPH přiznání (DPHDP3) a kombinuje:
 *
 *   - **Vystavené faktury** (invoices) → sekce s prefixem `36.001`, `36.002` …
 *     (řádky 1, 2, … DPHDP3 — uskutečněná plnění)
 *   - **Přijaté faktury** (purchase_invoices) → sekce `15.040`, `15.041` …
 *     (řádky 40, 41 … — přijatá tuzemská), `43.012/43.043` (dovoz služby)
 *
 * Scope = **vystavené + přijaté včetně draftů**. Drafty jsou označeny
 * `is_draft=true` v rows; UI je vizuálně odlišuje (badge "Koncept"). Storno
 * (`status='cancelled'`) je vyloučeno, proformy taky.
 *
 * Section key formát:
 *   - **15.XXX** = řádek pro přijatá plnění (sekce 15)
 *   - **36.XXX** = řádek pro vystavená plnění (sekce 36)
 *   - **43.XXX** = řádek 43 (nárok na odpočet) — pouze secondary z dovozu služby
 *
 * Pokud má klasifikační kód `dphdp3_line_secondary` (typicky dovoz služby:
 * ř.12 + ř.43), pak builder generuje DVĚ sekce ze stejné faktury (data se
 * objeví ve dvou tabulkách na PDF — viz reference DPH_LIST_KH 42026.pdf).
 *
 * Periodicita: **pouze měsíční** (year + month, range 1.-poslední den).
 */
final class DphBookBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatLedgerService $ledger,
    ) {}

    /**
     * @return array{
     *   period: array{year:int, month:int, start:string, end:string, label:string},
     *   supplier: array<string,mixed>,
     *   sections: list<array<string,mixed>>,
     *   totals: array{
     *     issued: array{base:float, vat:float, total:float},
     *     received: array{base:float, vat:float, total:float},
     *     vat_balance: float
     *   }
     * }
     */
    public function build(int $supplierId, int $year, int $month): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        // Kanonické řádky ze sdílené VatLedgerService (vč. draftů — Kniha je pracovní
        // žurnál). Seskupíme per (doklad, kód, sazba) do jednoho řádku přehledu a
        // zařadíme do sekcí dle dphdp3_line (+ mirror 43, + ř.47 majetek).
        $sections = [];
        foreach ($this->groupLedgerRows($supplierId, $start, $end) as $g) {
            $scope = $g['source'] === 'sale' ? 'issued' : 'received';
            $line = $g['dphdp3_line'];
            if ($line === null) {
                // Kód s vědomě prázdným řádkem (např. 42 "bez nároku na odpočet") do
                // Knihy DPH nepatří — přeskočíme. Fallback dle sazby použij jen pro
                // doklady úplně bez klasifikace (chybějící, ne vyloučená).
                if (($g['code'] ?? '') !== '') {
                    continue;
                }
                $line = $g['vat_rate'] >= 20.5
                    ? ($g['source'] === 'sale' ? '1' : '40')
                    : ($g['vat_rate'] > 0 ? ($g['source'] === 'sale' ? '2' : '41') : null);
            }
            $cls = [
                'code'                  => $g['code'] ?? '',
                'label'                 => $g['label'] !== '' ? $g['label'] : '(bez klasifikace)',
                'dphdp3_line'           => $line,
                'dphdp3_line_secondary' => $g['dphdp3_line_secondary'],
                'kh_section'            => $g['kh_section'],
                'vat_rate'              => $g['vat_rate'],
            ];
            $row = $this->toBookRow($g);
            $this->addToSection($sections, $scope, $cls, $row);

            // Secondary (ř.43 mirror odpočet u RC / dovozu služby).
            if (!empty($cls['dphdp3_line_secondary'])) {
                $this->addToSection($sections, $scope, array_merge($cls, [
                    'dphdp3_line'           => $cls['dphdp3_line_secondary'],
                    'dphdp3_line_secondary' => null,
                    'is_secondary'          => true,
                ]), $row);
            }
            // ř.47 — hodnota pořízeného majetku (primary nebo secondary v 40-45).
            if ($g['is_fixed_asset']) {
                $p = (int) ($cls['dphdp3_line'] ?? 0);
                $s = (int) ($cls['dphdp3_line_secondary'] ?? 0);
                if (($p >= 40 && $p <= 45) || ($s >= 40 && $s <= 45)) {
                    $this->addToSection($sections, $scope, array_merge($cls, [
                        'dphdp3_line'           => '47',
                        'dphdp3_line_secondary' => null,
                        'is_secondary'          => true,
                    ]), $row);
                }
            }
        }

        // Convert sections asociativní mapy → indexované pole, seřazené.
        $sectionList = array_values($sections);
        usort($sectionList, function ($a, $b) {
            // Vystavené (36) nahoru, pak přijaté (15), pak secondary (43).
            $oa = $this->sectionOrder($a['key']);
            $ob = $this->sectionOrder($b['key']);
            if ($oa !== $ob) return $oa <=> $ob;
            return strcmp($a['key'], $b['key']);
        });

        // Per-sekce subtotal + global totals.
        //
        // Totals JE NUTNÉ držet odděleně pro uskutečněná (daň na výstupu, sekce 36)
        // a přijatá plnění (odpočet na vstupu, sekce 15) — sčítat je dohromady nedává
        // účetní smysl. Výsledná bilance DPH = daň na výstupu − odpočet na vstupu
        // (kladná = vlastní daňová povinnost, záporná = nadměrný odpočet).
        $issued   = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        $received = ['base' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        foreach ($sectionList as &$s) {
            $sb = $sv = $st = 0.0;
            foreach ($s['rows'] as $row) {
                $sb += (float) $row['base'];
                $sv += (float) $row['vat'];
                $st += (float) $row['total'];
            }
            $s['subtotal_base']  = $sb;
            $s['subtotal_vat']   = $sv;
            $s['subtotal_total'] = $st;
            // Do souhrnů započítáváme jen non-secondary řádky aby se dovoz služby
            // nezdvojoval. (Sekce 43/47 jsou secondary mirror sekce 12/40-45.)
            if (empty($s['is_secondary'])) {
                $bucket = $this->sectionOrder($s['key']) === 0 ? 'issued' : 'received';
                ${$bucket}['base']  += $sb;
                ${$bucket}['vat']   += $sv;
                ${$bucket}['total'] += $st;
            }
        }
        unset($s);

        $label = (new \DateTimeImmutable($start))->format('d.m.Y') . ' - ' . (new \DateTimeImmutable($end))->format('d.m.Y');

        return [
            'period' => [
                'year'  => $year,
                'month' => $month,
                'start' => $start,
                'end'   => $end,
                'label' => $label,
            ],
            'supplier' => $supplier,
            'sections' => $sectionList,
            'totals' => [
                'issued'      => $issued,
                'received'    => $received,
                // DPH na výstupu − odpočet na vstupu.
                'vat_balance' => $issued['vat'] - $received['vat'],
            ],
        ];
    }

    /**
     * Kanonické řádky ze služby seskupené per (zdroj, doklad, kód, sazba) — jeden
     * řádek přehledu Knihy DPH. Vč. draftů.
     *
     * @return list<array<string,mixed>>
     */
    private function groupLedgerRows(int $supplierId, string $start, string $end): array
    {
        $grouped = [];
        foreach ($this->ledger->rows($supplierId, $start, $end, includeDrafts: true) as $r) {
            $key = $r['source'] . ':' . $r['invoice_id'] . ':' . ($r['code'] ?? '') . ':' . $r['vat_rate'];
            if (!isset($grouped[$key])) {
                $grouped[$key] = $r;
                $grouped[$key]['base_czk'] = 0.0;
                $grouped[$key]['vat_czk'] = 0.0;
            }
            $grouped[$key]['base_czk'] += (float) $r['base_czk'];
            $grouped[$key]['vat_czk']  += (float) $r['vat_czk'];
        }
        return array_values($grouped);
    }

    /**
     * Mapuje kanonický (seskupený) řádek na řádek přehledu Knihy DPH (PDF/UI shape).
     *
     * @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private function toBookRow(array $g): array
    {
        $base = (float) $g['base_czk'];
        $vat = (float) $g['vat_czk'];
        return [
            'invoice_id'              => (int) $g['invoice_id'],
            'direction'               => $g['source'] === 'sale' ? 'issued' : 'received',
            'doc_number'              => $g['doc_number'],
            'original_doc_number'     => $g['source'] === 'purchase' ? $g['vendor_invoice_number'] : null,
            'tax_date'                => $g['tax_date'],
            'accounting_date'         => $g['issue_date'],
            'description'             => (string) ($g['description'] ?? ''),
            'counterparty_name'       => (string) ($g['counterparty_name'] ?? ''),
            'counterparty_dic'        => (string) ($g['counterparty_dic'] ?? ''),
            'vat_classification_code' => $g['code'],
            'vat_rate'                => (float) $g['vat_rate'],
            'currency'                => (string) $g['currency'],
            'exchange_rate'           => (float) $g['exchange_rate'],
            'base'                    => $base,
            'vat'                     => $vat,
            'total'                   => $base + $vat,
            'status'                  => (string) $g['status'],
            'is_draft'                => (bool) $g['is_draft'],
            'is_fixed_asset'          => (bool) $g['is_fixed_asset'],
        ];
    }

    /**
     * Připojí row do správné sekce, vytvoří sekci pokud neexistuje.
     *
     * @param array<string,array<string,mixed>> $sections by-ref
     * @param array<string,mixed> $cls
     * @param array<string,mixed> $row
     */
    private function addToSection(array &$sections, string $directionScope, array $cls, array $row): void
    {
        $sectionPrefix = $directionScope === 'issued' ? '36' : '15';
        // Sekce s line=43 jsou secondary (dovoz služby / RC mirror — nárok na odpočet)
        if ($cls['dphdp3_line'] === '43') {
            $sectionPrefix = '43';
        }
        // Sekce ř.47 = doplňující údaj o hodnotě pořízeného majetku
        if ($cls['dphdp3_line'] === '47') {
            $sectionPrefix = '47';
        }
        $line = $cls['dphdp3_line'] ?: '000';
        $linePadded = str_pad($line, 3, '0', \STR_PAD_LEFT);
        // Sekce klíč: NN.LLL (NN=15/36/43, LLL=padded line). Sazba je v label.
        $key = $sectionPrefix . '.' . $linePadded;

        if (!isset($sections[$key])) {
            $sections[$key] = [
                'key'             => $key,
                'direction'       => $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ',
                'label'           => $this->buildSectionLabel($sectionPrefix, $line, (float) $cls['vat_rate'], $directionScope),
                'dphdp3_line'     => $line,
                'vat_rate'        => (float) $cls['vat_rate'],
                'is_secondary'    => !empty($cls['is_secondary']),
                'rows'            => [],
                'subtotal_base'   => 0.0,
                'subtotal_vat'    => 0.0,
                'subtotal_total'  => 0.0,
            ];
        }
        // Doplň KH section do row (zobrazuje se v poslední koloně PDF: A.4, B.2, …)
        $rowWithKh = array_merge($row, [
            'kh_section' => $cls['kh_section'],
        ]);
        $sections[$key]['rows'][] = $rowWithKh;
    }

    /**
     * Label sekce — paste-and-modify ze stylu reference PDF:
     *   "15 ř.040 - PŘIJATÁ: Z tuzemska - sazba 21 %"
     *   "36 ř.001 - USKUTEČNĚNÁ: Základ daně"
     *   "43 ř.012 - PŘIJATÁ: Z dovozu služby - sazba"
     *   "43 ř.043 - PŘIJATÁ: Z dovozu služby - sazba"
     */
    private function buildSectionLabel(string $prefix, string $line, float $vatRate, string $directionScope): string
    {
        $direction = $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ';
        $rateLabel = $vatRate > 0 ? sprintf(' %g %%', $vatRate) : '';
        if ($prefix === '36') {
            // Vystavené: "Základ daně" nebo sazba podle řádku
            $what = ($line === '1' || $line === '2') ? 'Základ daně' : 'Plnění';
            return sprintf('%s ř.%s - %s: %s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what . $rateLabel);
        }
        if ($prefix === '43') {
            return sprintf('%s ř.%s - %s: Z reverse charge / dovozu služby - sazba%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $rateLabel);
        }
        if ($prefix === '47') {
            return sprintf('%s ř.%s - PŘIJATÁ: Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT));
        }
        // 15.XXX = přijaté
        // Speciální popisy pro 40/41 (tuzemsko), 12 (dovoz služby), 7 (dovoz zboží), atd.
        $what = match ($line) {
            '40', '41', '42' => 'Z tuzemska - sazba',
            '12'             => 'Z dovozu služby - sazba',
            '7'              => 'Dovoz zboží ze 3. země',
            '3', '4'         => 'Pořízení z EU',
            default          => 'Plnění',
        };
        return sprintf('%s ř.%s - %s: %s%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what, $rateLabel);
    }

    private function sectionOrder(string $key): int
    {
        // 36.XXX = vystavené (0), 15.XXX = přijaté (1), 43.XXX = RC/dovoz mirror (2),
        // 47.XXX = hodnota pořízeného majetku doplňující údaj (3).
        $prefix = substr($key, 0, 2);
        return match ($prefix) {
            '36' => 0,
            '15' => 1,
            '43' => 2,
            '47' => 3,
            default => 9,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }
}
