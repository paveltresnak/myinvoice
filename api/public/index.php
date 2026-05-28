<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;

try {
    $app = Bootstrap::buildApp();
    $app->run();
} catch (\Throwable $e) {
    // Pre-bootstrap chyba (typicky chybějící cfg.php nebo nedostupná DB).
    http_response_code(503);

    $msg = $e->getMessage();
    $missingCfg = str_contains($msg, 'cfg.php');
    $isJson = isset($_SERVER['HTTP_ACCEPT']) && stripos((string) $_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

    // Detekce produkce — pokud cfg.php existuje a obsahuje 'env' => 'production',
    // skryjeme detail. Bez cfg.php (úplně čerstvá instalace) ukážeme návod, abychom pomohli adminovi.
    $isProd = false;
    $cfgFile = __DIR__ . '/../../cfg.php';
    if (is_file($cfgFile)) {
        $cfgContent = (string) @file_get_contents($cfgFile);
        $isProd = !preg_match("/'env'\s*=>\s*'development'/", $cfgContent);
    }

    // Detail loguj, pokud je dostupný adresář.
    $logDir = \MyInvoice\Infrastructure\Config\RuntimePaths::log();
    if (is_dir($logDir) && is_writable($logDir)) {
        @file_put_contents(
            $logDir . '/bootstrap-error.log',
            sprintf("[%s] %s\n%s\n\n", date('Y-m-d H:i:s'), $msg, $e->getTraceAsString()),
            FILE_APPEND
        );
    }

    $publicMsg = ($isProd && !$missingCfg)
        ? 'Aplikace nedostupná, kontaktujte administrátora.'
        : $msg;
    $publicCode = $missingCfg ? 'config_missing' : 'bootstrap_failed';

    if ($isJson) {
        header('Content-Type: application/json; charset=utf-8');
        $payload = ['error' => ['code' => $publicCode, 'message' => $publicMsg]];
        if (!$isProd && $missingCfg) {
            $payload['error']['hint'] = 'Vytvoř cfg.php z cfg.sample.php a spusť `php api/bin/setup.php`.';
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!doctype html>
    <html lang="cs">
    <head>
      <meta charset="utf-8">
      <title>MyInvoice.cz</title>
      <style>
        body { font: 14px/1.5 system-ui, sans-serif; max-width: 640px; margin: 60px auto; padding: 0 20px; color: #15131D; }
        h1 { color: #3B2D83; }
        code { background: #F4F2F8; padding: 2px 6px; border-radius: 4px; font-family: 'JetBrains Mono', Consolas, monospace; }
        pre { background: #15131D; color: #fff; padding: 12px 16px; border-radius: 6px; overflow-x: auto; }
        .err { background: #FBEDED; border-left: 3px solid #D45B5B; padding: 12px; margin: 16px 0; }
      </style>
    </head>
    <body>
      <?php if ($isProd && !$missingCfg): ?>
        <h1>Aplikace nedostupná</h1>
        <p>Došlo k dočasné chybě. Kontaktujte administrátora.</p>
      <?php else: ?>
        <h1>MyInvoice.cz — chybí konfigurace</h1>
        <div class="err"><strong>Chyba:</strong> <?= htmlspecialchars($publicMsg, ENT_QUOTES) ?></div>
        <?php if ($missingCfg): ?>
          <h2>Postup</h2>
          <ol>
            <li>Z rootu repa zkopíruj vzorovou konfiguraci:
              <pre>cp cfg.sample.php cfg.php</pre>
            </li>
            <li>Otevři <code>cfg.php</code> a vyplň hodnoty <code>CHANGE-ME</code> — minimálně:
              <ul>
                <li><code>app.url</code> — tvoje doména</li>
                <li><code>app.pepper</code> — vygeneruj: <code>openssl rand -base64 32</code></li>
                <li><code>db.host</code> / <code>db.name</code> / <code>db.user</code> / <code>db.pass</code></li>
                <li><code>smtp.*</code> pro odesílání e-mailů</li>
              </ul>
            </li>
            <li>Spusť úvodní nastavení:
              <pre>php api/bin/setup.php</pre>
            </li>
          </ol>
        <?php else: ?>
          <h2>Pravděpodobné příčiny</h2>
          <ul>
            <li>Neplatné údaje v <code>cfg.php</code> (db.host / user / pass)</li>
            <li>MariaDB neběží nebo je na jiném portu</li>
            <li>Databáze neexistuje</li>
          </ul>
        <?php endif; ?>
      <?php endif; ?>
    </body>
    </html>
    <?php
}
