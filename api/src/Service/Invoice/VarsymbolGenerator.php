<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Generuje var. symbol (číslo faktury).
 *
 * Resolver template per (client, supplier, type) — clients.{type}_number_format má
 * nejvyšší prioritu, dál supplier.{type}_number_format, fallback na
 * cfg.varsymbol.templates.{type}. Period scope (year/month/none) řídí, kdy se
 * counter resetuje; per-client clients.invoice_number_period má prioritu, dál
 * supplier.invoice_number_period (legacy default 'month').
 *
 * Counter se atomicky inkrementuje v `invoice_counters` per
 * (supplier_id, client_id, invoice_type, period). `client_id = 0` značí
 * supplier-wide counter (žádný per-client template) — tak existující řady
 * pokračují beze změny.
 *
 * Placeholdery v template:
 *   {YYYY} = 4-digit year      ("2026")
 *   {YY}   = 2-digit year      ("26")
 *   {MM}   = 2-digit month     ("04")
 *   {C+}   = counter, padding podle počtu C ({CCC} → 3 znaky 001..999)
 *
 * Příklady:
 *   "JD{YYYY}-{CC}"      → "JD2026-02"      (period=year)
 *   "{YYYY}{MM}{CCC}"    → "202604001"      (period=month, default)
 *   "9{YY}{MM}{CCC}"     → "92604001"       (proforma, prefix 9)
 *   "F-{YYYY}/{CCCCCC}"  → "F-2026/000042"
 *   "{YY}{CCCC}"         → "260042"         (per-client, period=year)
 *
 * Prefix se píše rovnou do template stringu (žádný separátní `prefix` field).
 */
final class VarsymbolGenerator
{
    private const SUPPORTED_TYPES = ['invoice', 'proforma', 'credit_note'];
    private const VALID_PERIODS   = ['year', 'month', 'none'];
    private const DEFAULT_PERIOD  = 'month';

    public function __construct(
        private readonly Config $config,
        private readonly Connection $db,
    ) {}

    /**
     * Atomicky vygeneruje další var. symbol pro daný typ a datum.
     *
     * Pokud má faktura už ručně zadaný varsymbol (override), volající ho použije přímo
     * a tuto metodu nezavolá — viz IssueInvoiceAction.
     *
     * `$clientId` = 0 znamená "supplier-wide counter" (per-client template není
     * nastavený, použije se supplier-level template + jeho counter).
     *
     * @throws \InvalidArgumentException pokud typ nemá template ani v clients, ani v supplier, ani v cfg
     */
    public function next(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null, int $clientId = 0): string
    {
        if ($supplierId <= 0) {
            throw new \InvalidArgumentException("Neplatný supplier_id: {$supplierId}");
        }
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            throw new \InvalidArgumentException("Nepodporovaný typ pro varsymbol: {$invoiceType}");
        }

        [$template, $period, $counterClientId] = $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId);
        if ($template === '') {
            throw new \InvalidArgumentException(
                "Chybí template pro {$invoiceType}: nastav v Systém → Dodavatelé → Číslování faktur,"
                . " nebo doplň cfg.varsymbol.templates.{$invoiceType}."
            );
        }

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);
        $next      = $this->incrementCounter($supplierId, $counterClientId, $invoiceType, $periodKey);

        return $this->render($template, $for, $next);
    }

    /**
     * Vrátí, jaký bude další varsymbol BEZ inkrementu (pro náhled v UI).
     */
    public function preview(int $supplierId, string $invoiceType, ?\DateTimeInterface $for = null, int $clientId = 0): string
    {
        if ($supplierId <= 0) return '';
        if (!in_array($invoiceType, self::SUPPORTED_TYPES, true)) return '';

        [$template, $period, $counterClientId] = $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId);
        if ($template === '') return '';

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $stmt = $this->db->pdo()->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $counterClientId, $invoiceType, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);

        return $this->render($template, $for, $current + 1);
    }

    /**
     * Pokud je daná faktura "poslední" ve své counter scope (její varsymbol odpovídá
     * aktuální hodnotě counteru), dekrementuj counter — to umožní, aby další vystavená
     * faktura ve stejné scope dostala stejné číslo.
     *
     * Volej PŘED vlastním DELETE z DB (potřebujeme issue_date a varsymbol). Idempotentní:
     * pokud counter neodpovídá (nepasuje render, byla manuálně přečíslovaná, mezitím
     * inkrementoval konkurenční zápis), nic neudělá.
     *
     * @return bool true pokud byl counter dekrementován
     */
    public function releaseIfLatest(int $supplierId, string $invoiceType, string $varsymbol, ?\DateTimeInterface $for = null, int $clientId = 0): bool
    {
        if ($supplierId <= 0 || $varsymbol === '' || !in_array($invoiceType, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        [$template, $period, $counterClientId] = $this->resolveTemplateAndPeriod($supplierId, $invoiceType, $clientId);
        if ($template === '') return false;

        $for       = $for ?? new \DateTimeImmutable('today');
        $periodKey = $this->makePeriodKey($period, $for);

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $counterClientId, $invoiceType, $periodKey]);
        $current = (int) ($stmt->fetchColumn() ?: 0);
        if ($current <= 0) return false;

        if ($this->render($template, $for, $current) !== $varsymbol) {
            return false;
        }

        $upd = $pdo->prepare(
            'UPDATE invoice_counters SET last_number = last_number - 1
              WHERE supplier_id = ? AND client_id = ? AND invoice_type = ? AND period = ? AND last_number = ?'
        );
        $upd->execute([$supplierId, $counterClientId, $invoiceType, $periodKey, $current]);

        return $upd->rowCount() > 0;
    }

    public function render(string $template, \DateTimeInterface $date, int $counter): string
    {
        $vars = [
            '{YYYY}' => $date->format('Y'),
            '{YY}'   => $date->format('y'),
            '{MM}'   => $date->format('m'),
        ];
        $rendered = strtr($template, $vars);

        // Counter: matchuj sekvenci {CC...} pro variabilní padding ({C}, {CC}, {CCCCCC}, ...)
        $rendered = preg_replace_callback('/\{(C+)\}/', function ($m) use ($counter) {
            $len = strlen($m[1]);
            return str_pad((string) $counter, $len, '0', STR_PAD_LEFT);
        }, $rendered) ?? $rendered;

        return $rendered;
    }

    /**
     * Vrátí [template, period, counterClientId].
     *
     * counterClientId určuje scope counteru:
     *   - když má klient vlastní template, vrátí se $clientId (per-client counter)
     *   - když dědí ze supplieru, vrátí se 0 (supplier-wide counter)
     *
     * Tím se zajistí, že supplier-wide řada zůstane konzistentní napříč klienty, kteří
     * žádný vlastní formát nemají, a per-client klienti mají svůj nezávislý counter.
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function resolveTemplateAndPeriod(int $supplierId, string $invoiceType, int $clientId): array
    {
        $supStmt = $this->db->pdo()->prepare(
            'SELECT invoice_number_format, proforma_number_format, credit_note_number_format,
                    invoice_number_period
               FROM supplier WHERE id = ? LIMIT 1'
        );
        $supStmt->execute([$supplierId]);
        $supRow = $supStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $col = match ($invoiceType) {
            'invoice'     => 'invoice_number_format',
            'proforma'    => 'proforma_number_format',
            'credit_note' => 'credit_note_number_format',
        };

        $clientTemplate = '';
        $clientPeriod = null;
        if ($clientId > 0) {
            $cliStmt = $this->db->pdo()->prepare(
                "SELECT {$col} AS tpl, invoice_number_period AS period
                   FROM clients WHERE id = ? AND supplier_id = ? LIMIT 1"
            );
            $cliStmt->execute([$clientId, $supplierId]);
            $cliRow = $cliStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $clientTemplate = trim((string) ($cliRow['tpl'] ?? ''));
            $clientPeriod = $cliRow['period'] ?? null;
        }

        if ($clientTemplate !== '') {
            $period = $clientPeriod !== null ? (string) $clientPeriod : (string) ($supRow['invoice_number_period'] ?? self::DEFAULT_PERIOD);
            if (!in_array($period, self::VALID_PERIODS, true)) $period = self::DEFAULT_PERIOD;
            return [$clientTemplate, $period, $clientId];
        }

        $supplierTemplate = trim((string) ($supRow[$col] ?? ''));
        $template = $supplierTemplate !== ''
            ? $supplierTemplate
            : (string) $this->config->get("varsymbol.templates.{$invoiceType}", '');

        $period = (string) ($supRow['invoice_number_period'] ?? self::DEFAULT_PERIOD);
        if (!in_array($period, self::VALID_PERIODS, true)) $period = self::DEFAULT_PERIOD;

        return [$template, $period, 0];
    }

    /**
     * Klíč scope pro invoice_counters.period:
     *   year  → "2026"
     *   month → "202604"   (zpětně kompatibilní s legacy CHAR(6))
     *   none  → "ALL"      (jediný globální counter pro daný supplier+type)
     */
    private function makePeriodKey(string $period, \DateTimeInterface $for): string
    {
        return match ($period) {
            'year'  => $for->format('Y'),
            'none'  => 'ALL',
            default => $for->format('Ym'),
        };
    }

    private function incrementCounter(int $supplierId, int $clientId, string $invoiceType, string $periodKey): int
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_counters (supplier_id, client_id, invoice_type, period, last_number)
             VALUES (?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1'
        );
        $stmt->execute([$supplierId, $clientId, $invoiceType, $periodKey]);

        $stmt = $pdo->prepare(
            'SELECT last_number FROM invoice_counters
              WHERE supplier_id = ? AND client_id = ? AND invoice_type = ? AND period = ?'
        );
        $stmt->execute([$supplierId, $clientId, $invoiceType, $periodKey]);
        return (int) $stmt->fetchColumn();
    }
}
