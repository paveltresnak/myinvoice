#!/usr/bin/env bash
# =============================================================================
#  cron-cleanup.sh — denní úklid DB a souborů
#  Frekvence: 1× denně, doporučeno 03:00
#
#  Smaže: login_attempts >24h, expirované sessions, použité password_resets,
#         ARES/VIES cache >30 dní, PDF cache >90 dní, log files nad max_files.
#
#  crontab:
#    0 3 * * *  /var/www/myinvoice.cz/cmd/cron-cleanup.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-cleanup.php" "$@" \
    >> "$LOG_DIR/cleanup-$(date +%Y-%m-%d).log" 2>&1
