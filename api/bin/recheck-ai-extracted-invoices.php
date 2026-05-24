<?php

declare(strict_types=1);

/**
 * Recheck existujících přijatých faktur proti PDF "K úhradě".
 *
 * Projde přijaté faktury, které mají PDF přílohu a zatím nemají `extraction_warning`,
 * extrahuje z PDF autoritativní total a porovná s aktuálním DB `total_with_vat`.
 * Pokud se liší o víc než threshold (default 2 %), zapíše varování do
 * `extraction_warning` — UI ho pak vyflagne jako "vyžaduje kontrolu".
 *
 * Zdroj PDF totalu — hierarchie (od nejlevnější):
 *   1. **Embedded ISDOC** v PDF/A-3 (iDoklad / Fakturoid / Pohoda / MyInvoice
 *      vlastní faktury) → `LegalMonetaryTotal/PayableRoundedAmount`. Zdarma,
 *      deterministické, ~10 ms.
 *   2. **PDF text regex** — extract text přes smalot/pdfparser, max peněžní
 *      hodnota s currency suffixem (Kč/CZK/EUR/USD/€). Zdarma, ~50-200 ms,
 *      pokrývá většinu strukturovaných CZ faktur.
 *   3. **AI fallback** (Anthropic) — jen pro fragile PDFs kde 1 i 2 selhaly.
 *      ~0.001-0.03 USD per call.
 *
 * Statistika na konci ukáže, kolik faktur šlo přes který zdroj — uvidíš
 * reálnou úsporu nákladů.
 *
 * Použití:
 *   php api/bin/recheck-ai-extracted-invoices.php                   # dry-run, všichni
 *   php api/bin/recheck-ai-extracted-invoices.php --apply           # zápis warningu
 *   php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1
 *   php api/bin/recheck-ai-extracted-invoices.php --limit=10
 *   php api/bin/recheck-ai-extracted-invoices.php --include-flagged
 *   php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05  # custom % práh
 *   php api/bin/recheck-ai-extracted-invoices.php --ai-only         # vždy AI (jako dřív)
 *   php api/bin/recheck-ai-extracted-invoices.php --no-ai           # nikdy AI, jen ISDOC + regex
 *
 * Note: faktury BEZ pdf_path se ignorují. Faktury, kde všechny tři zdroje
 * vrátí null, se přeskočí (do statistiky `skipped_no_total`).
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Service\Import\PdfIsdocExtractor;
use MyInvoice\Service\Import\PdfTotalExtractor;
use MyInvoice\Repository\PurchaseInvoiceRepository;

// ── Argumenty ─────────────────────────────────────────────────────────
$dryRun         = !in_array('--apply', $argv, true);
$includeFlagged = in_array('--include-flagged', $argv, true);
$aiOnly         = in_array('--ai-only', $argv, true);  // legacy mode — vždy AI
$noAi           = in_array('--no-ai', $argv, true);    // nikdy AI fallback
$supplierId     = null;
$limit          = 0;
$threshold      = 0.02;
foreach ($argv as $a) {
    if (preg_match('/^--supplier-id=(\d+)$/', $a, $m))  $supplierId = (int) $m[1];
    if (preg_match('/^--limit=(\d+)$/', $a, $m))        $limit      = (int) $m[1];
    if (preg_match('/^--threshold=([\d.]+)$/', $a, $m)) $threshold  = (float) $m[1];
}
if ($aiOnly && $noAi) {
    fwrite(STDERR, "ERROR: --ai-only a --no-ai jsou navzájem výlučné.\n");
    exit(1);
}

// ── Bootstrap ─────────────────────────────────────────────────────────
$app       = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo       = $container->get(Connection::class)->pdo();
$anthropic = $container->get(AnthropicClient::class);
$repo      = $container->get(PurchaseInvoiceRepository::class);
$pdfTotal  = new PdfTotalExtractor(new PdfIsdocExtractor());
$rootDir   = Bootstrap::rootDir();

// ── Konfigurace archivu PDF ────────────────────────────────────────────
$archiveRoot = (string) $container->get(\MyInvoice\Infrastructure\Config\Config::class)
    ->get('purchase_invoice.archive_storage', '');
if ($archiveRoot === '') {
    $archiveRoot = $rootDir . '/storage/purchase-invoices';
}

// ── Najít kandidáty ───────────────────────────────────────────────────
$where = ['pdf_path IS NOT NULL', "pdf_path != ''"];
$params = [];
if (!$includeFlagged) $where[] = 'extraction_warning IS NULL';
if ($supplierId !== null) {
    $where[] = 'supplier_id = ?';
    $params[] = $supplierId;
}
$sql = 'SELECT id, supplier_id, vendor_invoice_number, total_without_vat, total_with_vat,
               pdf_path, status
          FROM purchase_invoices
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY id DESC';
if ($limit > 0) $sql .= " LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count = count($candidates);
$mode = $dryRun ? 'DRY-RUN' : 'APPLY';
$srcMode = $aiOnly ? 'AI-only (legacy)' : ($noAi ? 'no AI (ISDOC + regex)' : 'hybrid (ISDOC → regex → AI)');
printf("Nalezeno %d kandidátů (PDF + bez warning%s%s).\n",
    $count,
    $supplierId !== null ? ", supplier=$supplierId" : '',
    $includeFlagged ? ', včetně už flagovaných' : '',
);
printf("Mode: %s, threshold: %.1f %%, zdroj: %s\n\n", $mode, $threshold * 100, $srcMode);

if ($count === 0) {
    echo "Nic k recheck. Hotovo.\n";
    exit(0);
}

// ── Iterace ───────────────────────────────────────────────────────────
$stats = [
    'ok' => 0, 'flagged' => 0, 'skipped_no_pdf' => 0, 'skipped_no_total' => 0, 'errors' => 0,
    'source_isdoc' => 0, 'source_pdf_text' => 0, 'source_ai' => 0,
];

foreach ($candidates as $i => $row) {
    $id       = (int) $row['id'];
    $supId    = (int) $row['supplier_id'];
    $vNum     = (string) $row['vendor_invoice_number'];
    // abs() — dobropisy mají v DB záporné totals
    $dbTotal  = abs((float) $row['total_with_vat']);
    $pdfRel   = (string) $row['pdf_path'];

    printf("[%d/%d] #%d (%s) ... ", $i + 1, $count, $id, $vNum);

    $pdfPath = $archiveRoot . '/' . $pdfRel;
    if (!is_file($pdfPath)) {
        printf("PDF chybí na disku (%s), skip\n", $pdfPath);
        $stats['skipped_no_pdf']++;
        continue;
    }

    $pdfBytes = @file_get_contents($pdfPath);
    if ($pdfBytes === false || $pdfBytes === '') {
        printf("PDF nelze přečíst, skip\n");
        $stats['skipped_no_pdf']++;
        continue;
    }

    // Extrakce PDF totalu — hierarchie nebo legacy AI-only mode.
    // Pro AI fallback volíme **lightweight** extractPdfTotal (max_tokens=100,
    // minimalistický prompt) místo extractInvoice — ~5-10× levnější protože
    // nepotřebujeme items / klient / datumy, jen jediné číslo.
    if ($aiOnly) {
        $ai = $anthropic->extractPdfTotal($supId, $pdfBytes);
        if (!$ai['ok']) {
            printf("AI volání selhalo: %s\n", $ai['error'] ?? 'neznámá chyba');
            $stats['errors']++;
            continue;
        }
        $extractedTotal = isset($ai['total']) ? abs((float) $ai['total']) : null;
        $source = 'ai';
    } else {
        $aiCallback = $noAi ? null : function () use ($anthropic, $supId, $pdfBytes) {
            $ai = $anthropic->extractPdfTotal($supId, $pdfBytes);
            return $ai['ok'] && isset($ai['total']) ? abs((float) $ai['total']) : null;
        };
        $r = $pdfTotal->extract($pdfBytes, $aiCallback);
        $extractedTotal = $r['total'];
        $source = $r['source'] ?? 'unknown';
    }

    if ($extractedTotal === null || $extractedTotal <= 0.0) {
        printf("Nelze extrahovat total (vyzkoušeno: %s), skip\n",
            $aiOnly ? 'AI' : ($noAi ? 'ISDOC+regex' : 'ISDOC+regex+AI'));
        $stats['skipped_no_total']++;
        continue;
    }

    // Source counter
    if (isset($stats["source_$source"])) $stats["source_$source"]++;

    $diff = abs($dbTotal - $extractedTotal);
    $relativeDiff = $dbTotal > 0.0 ? $diff / $dbTotal : ($extractedTotal > 0.0 ? 1.0 : 0.0);

    if ($relativeDiff <= $threshold) {
        printf("OK [%s] (DB=%.2f, PDF=%.2f, rozdíl %.2f %%)\n",
            $source, $dbTotal, $extractedTotal, $relativeDiff * 100);
        $stats['ok']++;
        continue;
    }

    $direction = $dbTotal > $extractedTotal ? 'nafouknutý' : 'podhodnocený';
    $warning = sprintf(
        'Při zpětné kontrole AI extrakce: aktuální total faktury v DB (%s Kč) se liší od hodnoty z PDF "K úhradě" (%s Kč, zdroj: %s) o %.1f %% (%s součet). '
            . 'Typická příčina: AI při původní extrakci započítala mezisoučtové řádky ("Celkem", "Subtotal") jako další položky. '
            . 'Zkontroluj prosím řádky proti PDF před zaúčtováním.',
        number_format($dbTotal, 2, ',', ' '),
        number_format($extractedTotal, 2, ',', ' '),
        $source,
        $relativeDiff * 100.0,
        $direction,
    );

    printf("FLAG [%s] (DB=%.2f, PDF=%.2f, %.1f %% %s)%s\n",
        $source, $dbTotal, $extractedTotal, $relativeDiff * 100, $direction,
        $dryRun ? ' [dry-run]' : '');
    $stats['flagged']++;

    if (!$dryRun) {
        try {
            $repo->setExtractionWarning($id, $supId, $warning);
        } catch (\Throwable $e) {
            printf("    ! Zápis warningu selhal: %s\n", $e->getMessage());
            $stats['errors']++;
        }
    }
}

// ── Shrnutí ───────────────────────────────────────────────────────────
echo "\n";
echo "Hotovo. Statistika:\n";
printf("  OK (rozdíl pod %.1f %%)     : %d\n", $threshold * 100, $stats['ok']);
printf("  FLAG (nad práh)            : %d%s\n", $stats['flagged'], $dryRun ? ' (DRY-RUN, nezapsáno)' : '');
printf("  Skip — chybí PDF           : %d\n", $stats['skipped_no_pdf']);
printf("  Skip — žádný total z PDF   : %d\n", $stats['skipped_no_total']);
printf("  Chyby                      : %d\n", $stats['errors']);
echo "\n";
echo "Zdroj PDF totalu (úspora AI calls):\n";
printf("  ISDOC (PDF/A-3 embedded)   : %d  (zdarma)\n", $stats['source_isdoc']);
printf("  PDF text regex             : %d  (zdarma)\n", $stats['source_pdf_text']);
printf("  AI fallback                : %d  (placeno)\n", $stats['source_ai']);
$totalProcessed = $stats['source_isdoc'] + $stats['source_pdf_text'] + $stats['source_ai'];
if ($totalProcessed > 0 && !$aiOnly) {
    $savedPct = 100 * ($stats['source_isdoc'] + $stats['source_pdf_text']) / $totalProcessed;
    printf("  Ušetřeno AI volání         : %.0f %% z %d zpracovaných\n", $savedPct, $totalProcessed);
}

if ($dryRun && $stats['flagged'] > 0) {
    echo "\nPro skutečný zápis spusť znovu s --apply.\n";
}
