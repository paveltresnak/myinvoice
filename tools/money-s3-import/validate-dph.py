#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FÁZE 4 (volitelná): porovná DPH vypočtenou MyInvoice proti tvým REÁLNĚ PODANÝM přiznáním.
Kontroluje VÝSTUPNÍ DPH (ř.1) i CELOU DAŇ (dano_da) po měsících.

VSTUP (stdin): měsíční součty z databáze MyInvoice ve formátu řádků:
    YYYY-MM O <vystupni_dph>      (output = SUM(invoices.total_vat))
    YYYY-MM I <vstupni_dph>       (input  = SUM(purchase_invoices.total_vat))
Příklad získání z MariaDB (uprav dle svého DB jména):
    SELECT CONCAT(YEAR(tax_date),'-',LPAD(MONTH(tax_date),2,'0')),'O',ROUND(SUM(total_vat),2)
      FROM invoices WHERE invoice_type='invoice' AND status<>'cancelled' GROUP BY 1;
    SELECT CONCAT(YEAR(tax_date),'-',LPAD(MONTH(tax_date),2,'0')),'I',ROUND(SUM(total_vat),2)
      FROM purchase_invoices WHERE status<>'cancelled' GROUP BY 1;
  ... a oba výstupy (tab/mezera oddělené) pošli na stdin tohoto skriptu.

KONFIGURACE:
  RETURNS_DIR = složka s podanými přiznáními (podsložky <rok>_<měsíc>/ s DPHDP3-*.xml / DPH_MFCR-*.xml)
Spuštění:  <db-dump> | RETURNS_DIR=... python validate-dph.py
"""
import os, glob, re, sys
RETURNS_DIR = os.environ.get("RETURNS_DIR", r".\priznani-dph")
TOL = int(os.environ.get("TOL", "2"))   # tolerance v Kč (zaokrouhlení)

OUT, IN = {}, {}
for line in sys.stdin.read().splitlines():
    m = re.match(r"^\s*(\d{4}-\d{2})\s+(O|I)\s+(-?[\d.]+)\s*$", line)
    if m:
        (OUT if m.group(2) == "O" else IN)[m.group(1)] = float(m.group(3))

def filed_return(folder):
    """Efektivní podané přiznání období: preferuje opravné, ignoruje dodatečné (rozdíly) a potvrzení."""
    cands = []
    for pat in ("DPHDP3-*.xml", "DPH_MFCR-*.xml"):
        cands += glob.glob(os.path.join(folder, "**", pat), recursive=True)
    cands = [f for f in cands if not any(s in os.path.basename(f) for s in ("potvrzeni", "pracovni", "platba"))]
    if not cands:
        return None
    op = [f for f in cands if "oprav" in f.lower()]
    if op:
        return sorted(op)[-1]
    main = [f for f in cands if "dodate" not in f.lower() and "dodateč" not in f.lower()]
    return sorted(main)[-1] if main else None

def attr(xml, tag, a):
    m = re.search(r'<%s\b[^>]*\b%s="(-?\d+)"' % (tag, a), xml)
    return int(m.group(1)) if m else None

out_ok = due_ok = 0; out_mis = []; due_mis = []
for folder in sorted(glob.glob(os.path.join(RETURNS_DIR, "[0-9]" * 4 + "_" + "[0-9]" * 2))):
    base = os.path.basename(folder); ym = base.replace("_", "-")
    f = filed_return(folder)
    if not f:
        continue
    xml = open(f, encoding="utf-8", errors="replace").read()
    f_out = attr(xml, "Veta1", "dan23")
    f_due = attr(xml, "Veta6", "dano_da")
    if f_due is None:
        no = attr(xml, "Veta6", "dano_no")        # nadměrný odpočet = záporná daň
        f_due = -no if no is not None else None
    mi_out = OUT.get(ym); mi_in = IN.get(ym, 0.0)
    if f_out is not None and mi_out is not None:
        if abs(round(mi_out) - f_out) <= TOL: out_ok += 1
        else: out_mis.append((ym, f_out, round(mi_out)))
    if f_due is not None and mi_out is not None:
        mi_due = round(mi_out - mi_in)
        if abs(mi_due - f_due) <= TOL: due_ok += 1
        else: due_mis.append((ym, f_due, mi_due))

print("VÝSTUPNÍ DPH (ř.1): shoda %d | neshoda %d" % (out_ok, len(out_mis)))
for m in out_mis: print("   %-9s podáno=%-9s MyInvoice=%-9s" % m)
print("CELÁ DAŇ (dano_da):  shoda %d | neshoda %d" % (due_ok, len(due_mis)))
for m in due_mis: print("   %-9s podáno=%-9s MyInvoice=%-9s diff=%+d" % (m[0], m[1], m[2], m[2]-m[1]))
