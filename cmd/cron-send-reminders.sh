#!/usr/bin/env bash
# =============================================================================
#  cron-send-reminders.sh — automatické upomínky na faktury po splatnosti
#  Frekvence: 1× denně, doporučeno 09:00 v pracovní dny (Po–Pá)
#
#  Posílá upomínku klientům, jejichž faktura je více než --days=N dní
#  po splatnosti A od poslední upomínky uplynulo aspoň --cooldown=N dní.
#  Default: --days=3 --cooldown=7
#
#  Volitelné argumenty (předej jako parametry .sh):
#    --days=N        práh dní po splatnosti (default 3)
#    --cooldown=N    minimum dní od poslední upomínky (default 7)
#    --dry-run       jen vypíše, co by se odeslalo
#
#  crontab (každý pracovní den 09:00):
#    0 9 * * 1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-send-reminders.php" "$@" \
    >> "$LOG_DIR/send-reminders-$(date +%Y-%m-%d).log" 2>&1
