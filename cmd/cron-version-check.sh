#!/usr/bin/env bash
# =============================================================================
#  cron-version-check.sh — denní kontrola dostupnosti nové verze
#  Frekvence: 1× denně (kdykoliv, nesnese více než 1× za 6h kvůli GitHub rate
#  limitu pro anonymní volání = 60 req/h/IP, ale jednou denně bohatě stačí).
#
#  Volá GitHub Releases API (github.com/radekhulan/myinvoice/releases/latest),
#  cachuje tag + release notes do tabulky `app_meta`. UI footer + Systém →
#  Aktualizace pak čtou z cache (žádný blocking call při každém page loadu).
#
#  crontab:
#    0 6 * * *  /var/www/myinvoice.cz/cmd/cron-version-check.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-version-check.php" "$@" \
    >> "$LOG_DIR/version-check-$(date +%Y-%m-%d).log" 2>&1
