#!/usr/bin/env bash
# =============================================================================
#  cron-send-approval-reminders.sh — upomínky zákazníkům, kteří neschválili
#  výkaz víceprací (faktura visí ve stavu approval_status='requested').
#  Frekvence: 1× denně, doporučeno 09:00 v pracovní dny (Po–Pá)
#
#  Posílá stejnou šablonu invoice_approval s flagem reminder zákazníkům,
#  jejichž schvalovací e-mail je více než --days=N dní starý (default z
#  cfg.approval.reminder_after_days = 5) a kteří ještě nepřekročili
#  cfg.approval.max_reminders (default 3).
#
#  Volitelné argumenty (předej jako parametry .sh):
#    --days=N    override reminder_after_days
#    --dry-run   jen vypíše, co by se odeslalo
#
#  crontab (každý pracovní den 09:00):
#    0 9 * * 1-5  /var/www/myinvoice.cz/cmd/cron-send-approval-reminders.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-send-approval-reminders.php" "$@" \
    >> "$LOG_DIR/send-approval-reminders-$(date +%Y-%m-%d).log" 2>&1
