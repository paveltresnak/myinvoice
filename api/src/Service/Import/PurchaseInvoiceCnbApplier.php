<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Currency\CnbExchangeRateClient;

/**
 * Auto-apply ČNB kurzu na nově vytvořenou přijatou fakturu, pokud:
 *   - faktura není v CZK (tenant base ccy),
 *   - exchange_rate ještě nebyl nastaven (NULL).
 *
 * Sdíleno mezi AI extractorem, ISDOC mapperem, iDoklad a Fakturoid importem.
 * Bez tohoto by EUR/USD/... faktury ze zahraničních importů zůstaly bez kurzu
 * → CRM costs a vendor list sumace `total_with_vat * exchange_rate` by je
 * počítaly jako CZK (multiplier 1), což je špatně.
 *
 * Silent failure on ČNB network error — extract success > kurz fix-up.
 */
final class PurchaseInvoiceCnbApplier
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly CnbExchangeRateClient $cnb,
    ) {}

    /**
     * Zavolat po createDraft() pro non-CZK faktury bez kurzu.
     *
     * @param string|null $currency  ISO kód (CZK / EUR / USD / ...)
     * @param string|null $dateStr   tax_date nebo issue_date (YYYY-MM-DD)
     * @param float|null  $existingRate  Pokud >0, nevolat ČNB (caller už má rate)
     */
    public function applyIfMissing(int $id, int $supplierId, ?string $currency, ?string $dateStr, ?float $existingRate = null): bool
    {
        if ($existingRate !== null && $existingRate > 0) {
            return false;
        }
        $ccy = strtoupper((string) $currency);
        if ($ccy === '' || $ccy === 'CZK') {
            return false;
        }
        if (!$dateStr) {
            return false;
        }
        try {
            $date = new \DateTimeImmutable($dateStr);
        } catch (\Throwable) {
            return false;
        }
        try {
            $result = $this->cnb->getRate($ccy, $date);
        } catch (\Throwable) {
            return false;
        }
        if ($result === null || !isset($result['rate'])) {
            return false;
        }
        try {
            $this->repo->setExchangeRate(
                $id,
                (float) $result['rate'],
                (string) ($result['rate_date'] ?? $dateStr),
                'cnb',
                $supplierId,
            );
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
