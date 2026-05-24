<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder XML pro DPH přiznání (DPHDP3) — EPO portál MFČR.
 *
 * Verze EPO: 03.01 (platná 2025-2026).
 *
 * ⚠️ Vygenerované XML je POUZE POMŮCKA. Před odesláním vždy ověřit s účetní
 *    nebo daňovým poradcem. Aplikace nezaručuje regulatorní správnost.
 *
 * Schema: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/dpr_info/schemas.faces
 */
final class DphPriznaniBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly VatClassificationMapper $mapper,
    ) {}

    /**
     * Sestaví XML pro DPH přiznání za daný měsíc/kvartál.
     *
     * @param string $period 'monthly' (default) nebo 'quarterly' (sumuje celý kvartál)
     * @return array{xml: string, summary: array<string, mixed>, warnings: list<string>}
     */
    public function build(int $supplierId, int $year, int $month, ?string $period = null): array
    {
        $supplier = $this->loadSupplier($supplierId);
        // Default period z supplier.vat_period, fallback 'monthly'
        if ($period === null) {
            $period = (string) ($supplier['vat_period'] ?? 'monthly');
        }
        if (!in_array($period, ['monthly', 'quarterly'], true)) {
            $period = 'monthly';
        }
        $warnings = [];
        if (!$supplier['is_vat_payer']) {
            $warnings[] = 'Tenant není evidovaný jako plátce DPH — výkaz nemusí být relevantní.';
        }
        if (empty($supplier['financial_office_code'])) {
            $warnings[] = 'Chybí kód finančního úřadu — XML nemusí projít validací EPO.';
        }
        if (empty($supplier['ic'])) {
            $warnings[] = 'Chybí IČO tenanta.';
        }
        if (empty($supplier['dic'])) {
            $warnings[] = 'Chybí DIČ tenanta.';
        }

        $lines = $this->mapper->aggregateForDphPriznani($supplierId, $year, $month, $period);
        $quarter = $period === 'quarterly' ? (int) ceil($month / 3) : null;

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;

        // Root: <Pisemnost nazevSW="MyInvoice.cz" verzeSW="X.Y.Z">
        $pisemnost = $dom->createElement('Pisemnost');
        $pisemnost->setAttribute('nazevSW', 'MyInvoice.cz');
        $pisemnost->setAttribute('verzeSW', (string) ($this->loadAppVersion() ?? '0'));
        $dom->appendChild($pisemnost);

        // <DPHDP3 verzePis="03.01">
        $dphdp3 = $dom->createElement('DPHDP3');
        $dphdp3->setAttribute('verzePis', '03.01');
        $pisemnost->appendChild($dphdp3);

        // ── VetaD: identifikační údaje (typ podání + perioda) ─────────
        // Per EPO XSD: typ_platce je v VetaD, typ_ds v VetaP.
        $vetaD = $dom->createElement('VetaD');
        $vetaD->setAttribute('k_uladis', 'DPH');
        $vetaD->setAttribute('rok', (string) $year);
        if ($quarter !== null) {
            $vetaD->setAttribute('ctvrt', (string) $quarter);
        } else {
            $vetaD->setAttribute('mesic', (string) $month);
        }
        $vetaD->setAttribute('dapdph_forma', 'B'); // B = řádné (default), O/D/E = opravné/dodatečné
        $vetaD->setAttribute('dokument', 'DP3');   // identifikace typu výkazu
        $vetaD->setAttribute('typ_platce', 'P');   // P = plátce DPH (default; I = identifikovaná, S = skupina, N = neplátce)
        // CZ-NACE klasifikace (hlavní ekonomická činnost, 6-digit) — vyplňuje se
        // z `supplier.cz_nace_code`. Hodnotu očekávanou EPO ověřuje uživatel
        // proti číselníku https://mojedane.gov.cz/pmd/dokumentace/ciselniky/ukazka/okec.
        $okec = EpoSupplierBlockBuilder::normalizeOkec((string) ($supplier['cz_nace_code'] ?? ''));
        if ($okec !== null) {
            $vetaD->setAttribute('c_okec', $okec);
        }
        $vetaD->setAttribute('d_poddp', date('d.m.Y')); // datum podání (dnes)
        // trans: A = vznikla daňová povinnost (vlastní daň > 0), N = nevznikla.
        // Spočteme níže po sestavení Veta6 a setneme přes setAttribute.
        $dphdp3->appendChild($vetaD);

        // ── VetaP: identifikace daňového subjektu ─────────────────────
        $vetaP = $dom->createElement('VetaP');
        EpoSupplierBlockBuilder::fillVetaP($vetaP, $supplier);
        $dphdp3->appendChild($vetaP);

        // ── Veta1 / Veta4: namapování řádků 1-13 (Veta1) a 40-47 (Veta4) ──
        //
        // Mapping odpovídá EPO XSD (api/xsd/dphdp3.xsd), reálně podanému
        // přiznání a oficiálnímu MFČR DPHDP3 formuláři (verze 03.01):
        //
        // Veta1 — DPH na výstupu (vč. samovyměřené u RC):
        //   ř.1  obrat23/dan23           = sale 21 %
        //   ř.2  obrat5/dan5             = sale 12 %
        //   ř.3  p_zb23/dan_pzb23        = pořízení zboží z JČS 21 % (EU)
        //   ř.4  p_zb5/dan_pzb5          = pořízení zboží z JČS 12 % (EU)
        //   ř.5  p_sl23_e/dan_psl23_e    = přijetí služby z EU 21 %
        //   ř.6  p_sl5_e/dan_psl5_e      = přijetí služby z EU 12 %
        //   ř.7  dov_zb23/dan_dzb23      = dovoz zboží 21 %
        //   ř.8  dov_zb5/dan_dzb5        = dovoz zboží 12 %
        //   ř.10 rez_pren23/dan_rpren23  = tuzemský reverse charge 21 %
        //   ř.11 rez_pren5/dan_rpren5    = tuzemský reverse charge 12 %
        //   ř.12 p_sl23_z/dan_psl23_z    = přijetí služby ze 3. země 21 %
        //   ř.13 p_sl5_z/dan_psl5_z      = přijetí služby ze 3. země 12 %
        //
        // Veta4 — Nárok na odpočet daně:
        //   ř.40 pln23/odp_tuz23_nar     = tuzemsko 21 %
        //   ř.41 pln5/odp_tuz5_nar       = tuzemsko 12 %
        //   ř.42 dov_cu/odp_cu_nar       = dovoz CÚ
        //   ř.43 odp_rezim/odp_rez_nar   = RC mirror odpočet (z ř. 3-13)
        //   ř.47 nar_maj/—               = hodnota pořízeného majetku
        //                                  (doplňující údaj, jen základ; XSD má
        //                                  jediný atribut, daň se neuvádí)
        $lineMap = [
            // Veta1 (výstup)
            '1'  => ['veta' => 1, 'base' => 'obrat23',    'vat' => 'dan23'],
            '2'  => ['veta' => 1, 'base' => 'obrat5',     'vat' => 'dan5'],
            '3'  => ['veta' => 1, 'base' => 'p_zb23',     'vat' => 'dan_pzb23'],
            '4'  => ['veta' => 1, 'base' => 'p_zb5',      'vat' => 'dan_pzb5'],
            '5'  => ['veta' => 1, 'base' => 'p_sl23_e',   'vat' => 'dan_psl23_e'],
            '6'  => ['veta' => 1, 'base' => 'p_sl5_e',    'vat' => 'dan_psl5_e'],
            '7'  => ['veta' => 1, 'base' => 'dov_zb23',   'vat' => 'dan_dzb23'],
            '8'  => ['veta' => 1, 'base' => 'dov_zb5',    'vat' => 'dan_dzb5'],
            '10' => ['veta' => 1, 'base' => 'rez_pren23', 'vat' => 'dan_rpren23'],
            '11' => ['veta' => 1, 'base' => 'rez_pren5',  'vat' => 'dan_rpren5'],
            '12' => ['veta' => 1, 'base' => 'p_sl23_z',   'vat' => 'dan_psl23_z'],
            '13' => ['veta' => 1, 'base' => 'p_sl5_z',    'vat' => 'dan_psl5_z'],
            // Veta2 (oddíl C — ostatní plnění s nárokem na odpočet; jen základ, bez daně):
            //   ř.20 dodání zboží do JČS · ř.21 služby do JČS (§9/1) · ř.22 vývoz (§66)
            //   ř.23 dodání nového dopr. prostředku neregistrované osobě · ř.24 zasílání zboží
            //   ř.25 RC dodavatel (§92a) · ř.26 ostatní plnění s nárokem na odpočet
            '20' => ['veta' => 2, 'base' => 'dod_zb',      'vat' => null],
            '21' => ['veta' => 2, 'base' => 'pln_sluzby',  'vat' => null],
            '22' => ['veta' => 2, 'base' => 'pln_vyvoz',   'vat' => null],
            '23' => ['veta' => 2, 'base' => 'dod_dop_nrg', 'vat' => null],
            '24' => ['veta' => 2, 'base' => 'pln_zaslani', 'vat' => null],
            '25' => ['veta' => 2, 'base' => 'pln_rez_pren','vat' => null],
            '26' => ['veta' => 2, 'base' => 'pln_ost',     'vat' => null],
            // Veta4 (odpočet)
            '40' => ['veta' => 4, 'base' => 'pln23',      'vat' => 'odp_tuz23_nar'],
            '41' => ['veta' => 4, 'base' => 'pln5',       'vat' => 'odp_tuz5_nar'],
            '42' => ['veta' => 4, 'base' => 'dov_cu',     'vat' => 'odp_cu_nar'],
            '43' => ['veta' => 4, 'base' => 'odp_rezim',  'vat' => 'odp_rez_nar'],
            '47' => ['veta' => 4, 'base' => 'nar_maj',    'vat' => null],
        ];

        $totalDanZdanitelne = 0.0;
        $totalDanOdpocitatelne = 0.0;
        $veta1Attrs = [];
        $veta2Attrs = [];
        $veta4Attrs = [];

        foreach ($lines as $lineNum => $data) {
            $lineKey = (string) $lineNum;
            if (isset($lineMap[$lineKey])) {
                $m = $lineMap[$lineKey];
                $target = &${'veta' . $m['veta'] . 'Attrs'};
                $target[$m['base']] = $this->formatAmount($data['base']);
                if ($m['vat'] !== null) {
                    $target[$m['vat']] = $this->formatAmount($data['vat']);
                }
                unset($target);
            }
            if ($this->isOutputLine($lineKey)) {
                $totalDanZdanitelne += $data['vat'];
            } elseif ((int) $lineKey !== 47) {
                // ř.47 je doplňující údaj k ř.40-45, jeho daň se NEzapočítává
                // (jinak by se daň majetku duplikovala s odpočtem z ř.40).
                $totalDanOdpocitatelne += $data['vat'];
            }
        }
        if (!empty($veta1Attrs)) {
            $veta1 = $dom->createElement('Veta1');
            foreach ($veta1Attrs as $k => $v) $veta1->setAttribute($k, $v);
            $dphdp3->appendChild($veta1);
        }
        // Veta2 — oddíl C (ř.20-26). XSD vyžaduje pořadí Veta1 → Veta2 → … → Veta4.
        if (!empty($veta2Attrs)) {
            $veta2 = $dom->createElement('Veta2');
            foreach ($veta2Attrs as $k => $v) $veta2->setAttribute($k, $v);
            $dphdp3->appendChild($veta2);
        }
        if (!empty($veta4Attrs)) {
            $veta4 = $dom->createElement('Veta4');
            foreach ($veta4Attrs as $k => $v) $veta4->setAttribute($k, $v);
            $dphdp3->appendChild($veta4);
        }

        // ── VetaR: poradi (wrapper element, summary attrs jdou jinam) ────
        $vetaR = $dom->createElement('VetaR');
        $vetaR->setAttribute('poradi', '1');
        $dphdp3->appendChild($vetaR);

        $vlastniDan = $totalDanZdanitelne - $totalDanOdpocitatelne;

        // trans: A = vznikla daňová povinnost (kladná vlastní daň), N = nevznikla
        // (nadměrný odpočet / nulový rozdíl). Setneme až teď, kdy máme spočítáno.
        $vetaD->setAttribute('trans', $vlastniDan > 0 ? 'A' : 'N');

        // Termín podání: 25. den následujícího měsíce po skončení období
        $deadlineMonth = $quarter !== null ? ($quarter * 3 + 1) : ($month + 1);
        $deadlineYear  = $year;
        if ($deadlineMonth > 12) {
            $deadlineMonth -= 12;
            $deadlineYear += 1;
        }
        $deadline = sprintf('%04d-%02d-25', $deadlineYear, $deadlineMonth);

        $summary = [
            'period'                  => sprintf('%04d-%02d', $year, $month),
            'period_type'             => $period,
            'quarter'                 => $quarter,
            'lines'                   => $lines,
            'total_vat_output'        => round($totalDanZdanitelne, 2),
            'total_vat_input'         => round($totalDanOdpocitatelne, 2),
            'tax_due'                 => round($vlastniDan, 2),
            'is_excess_deduction'     => $vlastniDan < 0,
            'submission_deadline'     => $deadline,
            'supplier_vat_period'     => (string) ($supplier['vat_period'] ?? ''),
        ];

        return [
            'xml'      => $dom->saveXML() ?: '',
            'summary'  => $summary,
            'warnings' => $warnings,
        ];
    }

    /**
     * Načti tax-relevantní info o tenantovi.
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer,
                    s.taxpayer_type, s.vat_period, s.financial_office_code,
                    s.workplace_code, s.cz_nace_code, s.data_box_type, s.data_box_id,
                    s.email, s.phone,
                    s.street_number_pop, s.street_number_orient,
                    s.opr_jmeno, s.opr_prijmeni, s.opr_postaveni,
                    s.sest_jmeno, s.sest_telefon, s.sest_email, s.sest_funkce
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

    private function loadAppVersion(): ?string
    {
        $verFile = __DIR__ . '/../../../../VERSION';
        if (is_file($verFile)) {
            return trim((string) file_get_contents($verFile)) ?: null;
        }
        return null;
    }

    // VetaP a normalizeOkec přesunuto do EpoSupplierBlockBuilder (sdíleno s KH/SHV).

    /**
     * Output lines (DPH na výstupu): 1-29 dle DPHDP3.
     * Input lines (DPH na vstupu, odpočet): 40+ dle DPHDP3.
     */
    private function isOutputLine(string $line): bool
    {
        return (int) $line < 40;
    }

    /**
     * Formátování částky pro EPO XML — celé číslo Kč (zaokrouhleno).
     */
    private function formatAmount(float $amount): string
    {
        return (string) (int) round($amount);
    }
}
