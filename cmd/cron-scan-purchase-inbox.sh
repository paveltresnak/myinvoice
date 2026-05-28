#!/usr/bin/env bash
# =============================================================================
#  cron-scan-purchase-inbox.sh — auto-import přijatých faktur (PDF / ISDOC)
#  Frekvence: každých 5–15 minut (dodavatelé posílají PDF průběžně)
#  Skenuje cfg.purchase_invoice.inbox_dir, podporuje PDF, ISDOC, XML.
#
#  Workflow per soubor:
#    1. SHA-256 dedup vůči purchase_invoices.pdf_hash
#    2. Embedded ISDOC v PDF → ISDOC parser (priorita, zdarma)
#    3. PDF bez ISDOC + tenant má AI nakonfigurovanou → AI extract
#    4. Jinak skip
#
#  crontab:
#    */10 * * * *  /var/www/myinvoice.cz/cmd/cron-scan-purchase-inbox.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-scan-purchase-inbox.php" "$@" \
    >> "$LOG_DIR/scan-purchase-inbox-$(date +%Y-%m-%d).log" 2>&1
