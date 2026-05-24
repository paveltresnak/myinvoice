#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
KONTROLA ČÍSEL DOKLADŮ A DIČ velkých faktur v kontrolním hlášení (KH) — MyInvoice vs reálně podaná KH.
Porovná `c_evid_dd` (čísla dokladů) v sekcích:
  - A.4 = vydané faktury > 10 000 Kč (číslo tvého dokladu),
  - B.2 = přijaté faktury > 10 000 Kč (číslo dokladu DODAVATELE).
Navíc u shodných čísel dokladů porovná **DIČ protistrany** (A.4 `dic_odb`, B.2 `dic_dod`, A.2 `vatid_dod`).
Tím se ověří, že migrace dala do `vendor_invoice_number` správné číslo dodavatele (ne interní číslo),
správné DIČ, a že doklady spadly do správných období DPH.

VSTUP (env):
  RETURNS_DIR = složka s podanými KH (podsložky <YYYY_MM>/ s DPHKH_MFCR-*.xml; opravné v <YYYY_MM>_opravne/)
  MI_KH_DIR   = složka s KH vygenerovanými z MyInvoice, soubory pojmenované <YYYY-MM>.xml
                (vygeneruj např.:  GET /api/v1/reports/dphkh1?year=Y&month=M  →  ulož jako 2024-03.xml)
Spuštění:  RETURNS_DIR=... MI_KH_DIR=... python validate-kh-doklady.py

Pozn.: EU pořízení zboží (reverse charge) patří do A.2. MyInvoice ho navíc listuje i v B.2 (s daní 0) —
skript to rozpozná (B.2 navíc, které je zároveň v A.2) a hlásí zvlášť jako "RC duplicita v B.2",
ne jako reálnou neshodu.
"""
import os, glob, re

RETURNS_DIR = os.environ.get("RETURNS_DIR", r".\priznani-dph")
MI_KH_DIR   = os.environ.get("MI_KH_DIR", r".\myinvoice-kh")

def read(f): return open(f, encoding="utf-8", errors="replace").read()
def evset(xml, tag):
    out = set()
    for m in re.findall("<" + tag + r"\b[^>]*>", xml):
        e = re.search(r'c_evid_dd="([^"]*)"', m)
        if e: out.add(e.group(1).strip())
    return out

def dicmap(xml, tag, dic_attr):
    """c_evid_dd -> DIČ protistrany (A.4 dic_odb, B.2 dic_dod, A.2 vatid_dod)."""
    out = {}
    for m in re.findall("<" + tag + r"\b[^>]*>", xml):
        e = re.search(r'c_evid_dd="([^"]*)"', m)
        d = re.search(dic_attr + r'="([^"]*)"', m)
        if e: out[e.group(1).strip()] = (d.group(1).strip() if d else "")
    return out

# podaná KH: období -> efektivní složka (opravné > řádné; dodatečné ignoruj)
filed = {}
for p in sorted(glob.glob(os.path.join(RETURNS_DIR, "*"))):
    if not os.path.isdir(p): continue
    b = os.path.basename(p); m = re.match(r"(\d{4})_(\d{2})", b)
    if not m: continue
    per = "%s-%s" % (m.group(1), m.group(2)); low = b.lower()
    rank = 2 if "oprav" in low else (0 if ("dodate" in low or "dodateč" in low) else 1)
    if per not in filed or rank > filed[per][0]: filed[per] = (rank, p)

a4bad = b2bad = rcdup = miss = ok = dicbad = 0
for per in sorted(filed):
    folder = filed[per][1]
    fk = glob.glob(os.path.join(folder, "DPHKH_MFCR-*.xml")) or glob.glob(os.path.join(folder, "DPHKH1-*.xml"))
    if not fk: continue
    mk = glob.glob(os.path.join(MI_KH_DIR, per + ".xml"))
    if not mk:
        miss += 1; continue
    fx, mx = read(fk[0]), read(mk[0])
    fA, fB = evset(fx, "VetaA4"), evset(fx, "VetaB2")
    mA, mB, mA2 = evset(mx, "VetaA4"), evset(mx, "VetaB2"), evset(mx, "VetaA2")
    bad = False
    if mA != fA:
        a4bad += 1; bad = True
        print("  %s  A.4  chybí:%s  navíc:%s" % (per, sorted(fA - mA), sorted(mA - fA)))
    extra, missB = mB - fB, fB - mB
    rc, real = extra & mA2, extra - mA2     # B.2 navíc & zároveň v A.2 = EU pořízení listované i v B.2
    if rc: rcdup += 1; print("  %s  B.2  RC duplicita (EU pořízení i v B.2): %s" % (per, sorted(rc)))
    if real or missB:
        b2bad += 1; bad = True
        print("  %s  B.2  chybí:%s  navíc(mimo RC):%s" % (per, sorted(missB), sorted(real)))
    # DIČ protistran u shodných čísel dokladů (A.4 dic_odb, B.2 dic_dod, A.2 vatid_dod)
    dbad = False
    for tag, attr, sec in [("VetaA4","dic_odb","A.4"), ("VetaB2","dic_dod","B.2"), ("VetaA2","vatid_dod","A.2")]:
        fdm, mdm = dicmap(fx, tag, attr), dicmap(mx, tag, attr)
        for ev, fdic in fdm.items():
            if ev in mdm and mdm[ev] != fdic:
                dbad = True
                print("  %s  %s  DIČ neshoda doklad=%s  filed=%s  MI=%s" % (per, sec, ev, fdic, mdm[ev]))
    if dbad: dicbad += 1; bad = True
    if not bad and not rc: ok += 1

print("\n=== SOUHRN ===")
print("období OK:%d  A.4 čísla neshod:%d  B.2 čísla reálných neshod:%d  B.2 RC duplicit:%d  DIČ neshod (období):%d  bez MyInvoice KH:%d"
      % (ok, a4bad, b2bad, rcdup, dicbad, miss))
