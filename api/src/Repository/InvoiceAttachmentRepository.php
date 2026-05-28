<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Volitelné přílohy k dokladu (PDF, Office, obrázky), které se přibalí do
 * emailu při odeslání faktury / proformy / dobropisu.
 *
 * Soubory leží v storage/invoices/sup-{supplierId}/attachments/{invoiceId}/{sha8}-{safeName}.
 */
final class InvoiceAttachmentRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function listForInvoice(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, invoice_id, filename, original_name, size_bytes, sha256,
                    mime_type, uploaded_by, uploaded_at
               FROM invoice_attachments
              WHERE invoice_id = ?
              ORDER BY uploaded_at, id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => [
            'id'            => (int) $r['id'],
            'invoice_id'    => (int) $r['invoice_id'],
            'filename'      => (string) $r['filename'],
            'original_name' => (string) $r['original_name'],
            'size_bytes'    => (int) $r['size_bytes'],
            'sha256'        => (string) $r['sha256'],
            'mime_type'     => (string) $r['mime_type'],
            'uploaded_by'   => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'uploaded_at'   => (string) $r['uploaded_at'],
        ], $rows);
    }

    public function find(int $id, int $invoiceId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM invoice_attachments WHERE id = ? AND invoice_id = ?'
        );
        $stmt->execute([$id, $invoiceId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    public function totalSize(int $invoiceId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM invoice_attachments WHERE invoice_id = ?'
        );
        $stmt->execute([$invoiceId]);
        return (int) $stmt->fetchColumn();
    }

    public function insert(
        int $invoiceId,
        string $filename,
        string $originalName,
        int $sizeBytes,
        string $sha256,
        string $mimeType,
        ?int $uploadedBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO invoice_attachments
                (invoice_id, filename, original_name, size_bytes, sha256, mime_type, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $invoiceId, $filename, $originalName, $sizeBytes, $sha256, $mimeType, $uploadedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function delete(int $id, int $invoiceId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM invoice_attachments WHERE id = ? AND invoice_id = ?'
        );
        $stmt->execute([$id, $invoiceId]);
        return $stmt->rowCount() > 0;
    }

    /** Absolutní cesta k uloženému souboru. */
    public function pathFor(int $supplierId, int $invoiceId, string $filename): string
    {
        return self::dirFor($supplierId, $invoiceId) . '/' . $filename;
    }

    public static function dirFor(int $supplierId, int $invoiceId): string
    {
        return RuntimePaths::storage('invoices')
            . '/sup-' . $supplierId
            . '/attachments/' . $invoiceId;
    }

    /**
     * Smaže všechny soubory příloh + adresář pro danou fakturu.
     * Volá se PŘED `DELETE FROM invoices` — DB řádky cascade smaže, ale fyzické soubory ne.
     *
     * @return int  počet smazaných souborů
     */
    public function purgeFilesForInvoice(int $supplierId, int $invoiceId): int
    {
        $dir = self::dirFor($supplierId, $invoiceId);
        if (!is_dir($dir)) return 0;
        $deleted = 0;
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f) && @unlink($f)) $deleted++;
        }
        @rmdir($dir);
        return $deleted;
    }
}
