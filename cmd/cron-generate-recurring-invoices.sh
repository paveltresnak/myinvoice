#!/usr/bin/env bash
# =============================================================================
#  cron-generate-recurring-invoices.sh — automatické generování pravidelných faktur
#  Frekvence: 1× denně, doporučeno 06:30 (po cron-version-check)
#
#  Prochází šablony pravidelných faktur (recurring_invoice_templates) kde
#  status='active' a next_run_date <= dnes a vygeneruje fakturu. Podle
#  per-šablona flagů auto_issue / auto_send_email rovnou vystaví a/nebo
#  odešle klientovi e-mailem.
#
#  Per-supplier kill-switch: Nastavení → Můj dodavatel → "Generovat
#  pravidelné fakturace cronem".
#
#  Volitelné argumenty:
#    --dry-run       jen vypíše, co by se vygenerovalo
#
#  crontab (každý den 06:30):
#    30 6 * * *  /var/www/myinvoice.cz/cmd/cron-generate-recurring-invoices.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-generate-recurring-invoices.php" "$@" \
    >> "$LOG_DIR/generate-recurring-$(date +%Y-%m-%d).log" 2>&1
