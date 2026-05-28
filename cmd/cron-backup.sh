#!/usr/bin/env bash
# =============================================================================
#  cron-backup.sh — denní DB backup (mariadb-dump → ZIP)
#  Frekvence: 1× denně, doporučeno 02:00 (PŘED cron-cleanup)
#  Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle)
#
#  Vyžaduje v PATH: mariadb-dump (nebo mysqldump).
#
#  crontab:
#    0 2 * * *  /var/www/myinvoice.cz/cmd/cron-backup.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-backup.php" "$@" \
    >> "$LOG_DIR/backup-$(date +%Y-%m-%d).log" 2>&1
