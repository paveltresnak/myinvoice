#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Vygeneruje kontrolní hlášení (DPHKH1 XML) z MyInvoice pro všechna podaná období
a uloží do MI_KH_DIR jako <YYYY-MM>.xml. Vstup pro `validate-kh-doklady.py`.

ENV:
  MI_URL      = adresa MyInvoice (HTTPS)
  MI_TOKEN    = Personal Access Token
  RETURNS_DIR = složka s podanými KH (podsložky YYYY_MM) — určuje, která období generovat
  MI_KH_DIR   = výstupní složka (default .\\mi-kh)
Spuštění:  MI_URL=... MI_TOKEN=... RETURNS_DIR=... python gen-kh.py
"""
import os, re, glob, time, urllib.request

BASE = os.environ.get("MI_URL", "https://localhost:9443").rstrip("/")
TOK  = os.environ.get("MI_TOKEN", "").strip()
RET  = os.environ.get("RETURNS_DIR", r".\priznani-dph")
OUT  = os.environ.get("MI_KH_DIR", r".\mi-kh")
if not TOK:
    raise SystemExit("CHYBA: MI_TOKEN prázdný")
os.makedirs(OUT, exist_ok=True)

def api_xml(y, m):
    req = urllib.request.Request("%s/api/v1/reports/dphkh1?year=%d&month=%d" % (BASE, y, m))
    req.add_header("Authorization", "Bearer " + TOK)
    for _ in range(4):
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return r.read().decode("utf-8", "replace")
        except Exception:
            time.sleep(2)
    return None

n = skip = 0
for folder in sorted(glob.glob(os.path.join(RET, "*"))):
    b = os.path.basename(folder)
    if not re.fullmatch(r"\d{4}_\d{2}", b):
        continue
    y, m = map(int, b.split("_"))
    xml = api_xml(y, m)
    if xml and xml.lstrip().startswith("<?xml"):
        open(os.path.join(OUT, "%04d-%02d.xml" % (y, m)), "w", encoding="utf-8").write(xml)
        n += 1
    else:
        skip += 1
print("vygenerováno MI KH: %d, přeskočeno: %d -> %s" % (n, skip, OUT))
