<?php

declare(strict_types=1);

namespace MyInvoice\Service\Pdf;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;

/**
 * Archivace PDF faktur — místo `unlink()` přesune soubor do
 * storage/invoices/sup-{ID}/_archive/ a vytvoří záznam v invoice_pdfs.
 *
 * Hlavní use-case: zachovat verzi PDF, kterou klient skutečně dostal,
 * i poté co se faktura případně přerenderuje (sent kopie = důkaz fakturace).
 */
final class PdfArchiveService
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * Archivuje existující PDF: přesune do _archive/ a zaznamená do DB.
     * Pokud `sourcePath` neexistuje, jen no-op (nic není co archivovat).
     *
     * Dedup: pokud poslední archive entry pro fakturu má stejný sha256,
     * neukládá se nová kopie — jen se případně promítne was_sent / sent_to,
     * pokud nově dorazila tato informace (ručníkem na duplicitní 'sent' archive
     * při opětovném resendu se stejným PDF).
     *
     * @param  int                $invoiceId
     * @param  string             $sourcePath  absolutní cesta k aktuálnímu PDF
     * @param  string             $reason      'sent' | 'invalidate_update' | 'invalidate_issue' | ...
     * @param  bool               $wasSent     true = tato verze byla odeslána klientovi
     * @param  array<int,string>|null $sentTo  emaily příjemců (jen pokud wasSent)
     * @return int|null  archive ID, nebo null pokud zdroj neexistuje
     */
    public function archive(
        int $invoiceId,
        string $sourcePath,
        string $reason,
        bool $wasSent = false,
        ?array $sentTo = null,
    ): ?int {
        if (!is_file($sourcePath)) {
            return null;
        }

        $sha256 = hash_file('sha256', $sourcePath);
        if ($sha256 === false) {
            return null;
        }
        $size = (int) filesize($sourcePath);

        // Dedup: pokud poslední záznam pro fakturu má stejný hash, jen update was_sent
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, was_sent, sent_to FROM invoice_pdfs
             WHERE invoice_id = ? AND sha256 = ?
             ORDER BY archived_at DESC LIMIT 1'
        );
        $stmt->execute([$invoiceId, $sha256]);
        $existing = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($existing) {
            if ($wasSent) {
                $mergedTo = $this->mergeSentTo($existing['sent_to'] ?? null, $sentTo);
                $this->db->pdo()->prepare(
                    'UPDATE invoice_pdfs SET was_sent = 1, sent_to = ? WHERE id = ?'
                )->execute([
                    $mergedTo !== null ? json_encode($mergedTo, JSON_UNESCAPED_UNICODE) : null,
                    (int) $existing['id'],
                ]);
            }
            return (int) $existing['id'];
        }

        // Cíl: storage/invoices/sup-{supplierId}/_archive/{YmdHis}-{sha8}-{originalname}
        $supplierId = $this->resolveSupplierId($invoiceId);
        $archiveDir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/_archive';
        if (!is_dir($archiveDir)) {
            @mkdir($archiveDir, 0755, true);
        }

        $origName = basename($sourcePath);
        // Strip případný .new suffix z ne-přejmenovaných tmp souborů
        if (str_ends_with($origName, '.new')) {
            $origName = substr($origName, 0, -4);
        }
        // Strip .archcopy-XXXXXXXX suffix z archiveCopy() tmp kopií,
        // aby výsledný název končil na .pdf (jinak prohlížeč nepozná typ).
        $origName = preg_replace('/\.archcopy-[0-9a-f]+$/', '', $origName);
        $archiveName = date('Ymd-His') . '-' . substr($sha256, 0, 8) . '-' . $origName;
        $archivePath = $archiveDir . '/' . $archiveName;

        // Preferuj rename (atomic), ale pokud je zdroj na jiném FS / locked,
        // fallback na copy + unlink. PDF je idempotentní data, tak je to OK.
        $moved = @rename($sourcePath, $archivePath);
        if (!$moved) {
            if (!@copy($sourcePath, $archivePath)) {
                return null;
            }
            @unlink($sourcePath);
        }

        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_pdfs (invoice_id, filename, size_bytes, sha256, was_sent, sent_to, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $invoiceId,
            $archiveName,
            $size,
            $sha256,
            $wasSent ? 1 : 0,
            $wasSent && $sentTo ? json_encode(array_values($sentTo), JSON_UNESCAPED_UNICODE) : null,
            $reason,
        ]);

        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Archivuje PDF jako kopii (zdroj NESMAZÁVAT) — pro 'sent' případ,
     * kdy chceme ponechat aktivní cache, ale uložit historickou kopii.
     */
    public function archiveCopy(
        int $invoiceId,
        string $sourcePath,
        string $reason,
        bool $wasSent = false,
        ?array $sentTo = null,
    ): ?int {
        if (!is_file($sourcePath)) {
            return null;
        }
        // Trick: zkopíruj do tmp a zavolej archive(), který přesune.
        // Tím se zachová atomic FS pohyb a dedup logika.
        $tmp = $sourcePath . '.archcopy-' . bin2hex(random_bytes(4));
        if (!@copy($sourcePath, $tmp)) {
            return null;
        }
        $id = $this->archive($invoiceId, $tmp, $reason, $wasSent, $sentTo);
        if (is_file($tmp)) @unlink($tmp); // pokud archive() neudělalo move (dedup)
        return $id;
    }

    /**
     * @return list<array{id:int,filename:string,size_bytes:int,sha256:string,
     *                    was_sent:bool,sent_to:?array,reason:string,archived_at:string}>
     */
    public function listForInvoice(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, filename, size_bytes, sha256, was_sent, sent_to, reason, archived_at
             FROM invoice_pdfs WHERE invoice_id = ? ORDER BY archived_at DESC, id DESC'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $r): array {
            return [
                'id'          => (int) $r['id'],
                'filename'    => (string) $r['filename'],
                'size_bytes'  => (int) $r['size_bytes'],
                'sha256'      => (string) $r['sha256'],
                'was_sent'    => (int) $r['was_sent'] === 1,
                'sent_to'     => $r['sent_to'] ? json_decode((string) $r['sent_to'], true) : null,
                'reason'      => (string) $r['reason'],
                'archived_at' => (string) $r['archived_at'],
            ];
        }, $rows);
    }

    /**
     * Vrátí absolutní cestu k archivovanému souboru (pro download).
     * Vrací null, pokud archive ID neexistuje, nepatří k danému invoice,
     * nebo soubor zmizel z disku.
     */
    public function pathFor(int $archiveId, int $invoiceId): ?string
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT filename FROM invoice_pdfs WHERE id = ? AND invoice_id = ?'
        );
        $stmt->execute([$archiveId, $invoiceId]);
        $filename = $stmt->fetchColumn();
        if (!$filename) return null;

        $supplierId = $this->resolveSupplierId($invoiceId);
        $path = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId
              . '/_archive/' . (string) $filename;
        return is_file($path) ? $path : null;
    }

    /**
     * Smaže všechny archivované PDF soubory pro danou fakturu.
     *
     * Volá se PŘED `DELETE FROM invoices` — DB cascade vyčistí řádky v invoice_pdfs,
     * ale fyzické soubory v _archive/ by jinak zůstaly orphan na disku.
     *
     * @return int  počet smazaných souborů
     */
    public function purgeFilesForInvoice(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT filename FROM invoice_pdfs WHERE invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        $filenames = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        if (empty($filenames)) return 0;

        $supplierId = $this->resolveSupplierId($invoiceId);
        $dir = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('invoices') . '/sup-' . $supplierId . '/_archive';
        $deleted = 0;
        foreach ($filenames as $name) {
            $path = $dir . '/' . (string) $name;
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
        }
        return $deleted;
    }

    private function resolveSupplierId(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT supplier_id FROM invoices WHERE id = ?');
        $stmt->execute([$invoiceId]);
        return (int) ($stmt->fetchColumn() ?: 1);
    }

    /**
     * Sloučí dva seznamy emailů bez duplicit (pro update existujícího sent záznamu
     * při dedup případech — když se stejné PDF posílá více příjemcům).
     *
     * @param  string|null            $existingJson
     * @param  array<int,string>|null $newList
     * @return array<int,string>|null
     */
    private function mergeSentTo(?string $existingJson, ?array $newList): ?array
    {
        $existing = $existingJson ? (array) json_decode($existingJson, true) : [];
        $combined = array_values(array_unique(array_merge(
            array_filter(array_map('strval', $existing)),
            $newList ? array_filter(array_map('strval', $newList)) : [],
        )));
        return empty($combined) ? null : $combined;
    }
}
