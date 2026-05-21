#!/usr/bin/env bash
# Stáhne XSD schémata EPO MFČR do storage/xsd/.
#
# Použití:
#   bash cmd/download-xsd.sh           — stáhne všech 5 schémat
#   bash cmd/download-xsd.sh dphkh1    — stáhne jen jedno
#
# Zdroj: https://adisspr.mfcr.cz/dpr/adis/idpr_pub/epo2_info/popis_struktury_seznam.faces

set -euo pipefail

DIR="$(cd "$(dirname "$0")/.." && pwd)/storage/xsd"
BASE="https://adisspr.mfcr.cz/adis/jepo/schema"
FORMS=("dphdp3" "dphkh1" "dphshv" "dpfdp5" "dppdp9")

mkdir -p "$DIR"

if [[ $# -gt 0 ]]; then
    FORMS=("$@")
fi

for form in "${FORMS[@]}"; do
    url="${BASE}/${form}_epo2.xsd"
    target="${DIR}/${form}.xsd"
    echo "→ ${form}: ${url}"
    if curl -sSfL "$url" -o "$target.tmp"; then
        # Sanity check: musí začínat XML deklarací
        if head -c 20 "$target.tmp" | grep -q '<?xml'; then
            mv "$target.tmp" "$target"
            size=$(wc -c < "$target")
            echo "  ✓ ${target} (${size} bytes)"
        else
            rm -f "$target.tmp"
            echo "  ✗ ${form}: stažený soubor není XML (možná 404 HTML)"
        fi
    else
        rm -f "$target.tmp" 2>/dev/null || true
        echo "  ✗ ${form}: stažení selhalo"
    fi
done

echo
echo "Hotovo. Schémata v: ${DIR}"
echo "Aplikace je při generování XML automaticky validuje a archivuje výsledek v tax_submissions."
