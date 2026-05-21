<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

/**
 * Auto-default VAT klasifikační kódy podle (direction, vat_rate, is_reverse_charge).
 *
 * Pravidla per MF ČR (DPHDP3):
 *   - Vystavená (sale, tuzemsko):    21% → 1,  12% → 2,  0% → 3
 *   - Vystavená (sale, reverse):     20 (EU dodání zboží — řádek 20)
 *   - Přijatá (purchase, tuzemsko):  21% → 40, 12% → 41, 0% → 42 (bez nároku)
 *   - Přijatá (purchase, reverse):   5  (tuzemský reverse charge — řádek 10)
 *
 * Auto-default se aplikuje JEN POKUD uživatel ručně nezadal vat_classification_code.
 * Override per řádek (invoice_items / purchase_invoice_items) respektován.
 *
 * Pro non-CZK měnu / EU klienta může uživatel manuálně přepsat na 22/26/23/24.
 */
final class VatClassificationDefaulter
{
    /**
     * Default pro vystavenou fakturu (revenue side).
     */
    public function defaultForSale(float $vatRate, bool $reverseCharge = false): string
    {
        if ($reverseCharge) {
            return '20'; // EU dodání zboží (default reverse charge mapping)
        }
        return $this->byRate($vatRate, ['21.0' => '1', '12.0' => '2', '0.0' => '3']);
    }

    /**
     * Default pro přijatou fakturu (cost side).
     */
    public function defaultForPurchase(float $vatRate, bool $reverseCharge = false): string
    {
        if ($reverseCharge) {
            return '5'; // Tuzemský reverse charge
        }
        return $this->byRate($vatRate, ['21.0' => '40', '12.0' => '41', '0.0' => '42']);
    }

    /**
     * Default pro single item podle vat_rate (matchne s tolerance 0.5%).
     *
     * @param array<string,string> $map  rate (jako string klíč) → code
     */
    private function byRate(float $vatRate, array $map): string
    {
        foreach ($map as $rateStr => $code) {
            if (abs($vatRate - (float) $rateStr) < 0.5) {
                return $code;
            }
        }
        // Unknown rate — fallback na 0% sazbu kód
        return $map['0.0'] ?? '3';
    }

    /**
     * Aplikuje default na header faktury (pokud chybí).
     * Většinou se aplikuje při uložení (CreateAction / UpdateAction).
     *
     * Pro header zvolíme dominantní sazbu z items (max(total) za sazbu).
     *
     * @param list<array{vat_rate?:float, total_with_vat?:float}> $items
     */
    public function suggestHeaderForInvoice(array $items, bool $reverseCharge, string $direction): string
    {
        // Najdi dominantní sazbu (s největší totální částkou)
        $byRate = [];
        foreach ($items as $it) {
            $rate = (float) ($it['vat_rate'] ?? 0);
            $total = abs((float) ($it['total_with_vat'] ?? 0));
            if (!isset($byRate[(string) $rate])) $byRate[(string) $rate] = 0.0;
            $byRate[(string) $rate] += $total;
        }
        $dominantRate = 21.0;
        if (!empty($byRate)) {
            arsort($byRate);
            $dominantRate = (float) array_key_first($byRate);
        }
        return $direction === 'sale'
            ? $this->defaultForSale($dominantRate, $reverseCharge)
            : $this->defaultForPurchase($dominantRate, $reverseCharge);
    }
}
