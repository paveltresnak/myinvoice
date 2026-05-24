#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
FÁZE 3 (volitelná, doporučená pro plátce DPH):
Sladí datum zdanitelného plnění (DUZP) migrovaných faktur s tvými REÁLNĚ PODANÝMI
přiznáními tak, že vytáhne autoritativní DUZP z tvých podaných kontrolních hlášení (KH).

PROČ: Money S3 nedrží spolehlivě skutečné DUZP, které jsi podal na finanční úřad.
- U vydaných faktur se konvence datových polí (DatUcPr vs DatVyst) v čase mění.
- U přijatých faktur Money zná jen datum zaúčtování, ne vendorův daňový bod.
Jediný autoritativní zdroj DUZP = tvé podané KH:
  - sekce A.4 (vydané > 10 000 Kč): položka `c_evid_dd` (číslo dokladu) + `dppd` (DUZP)
  - sekce B.2 (přijaté > 10 000 Kč): `dic_dod` (DIČ dodavatele) + `zakl_dane1` + `dppd`
Malé doklady (A.5/B.3 < 10 000) jsou v KH jen agregát bez per-dokladu → zůstanou na
datu z Money (uvnitř měsíce přesné, DPH-neutrální).

VÝSTUP: SQL soubor s UPDATE příkazy. Zkontroluj a spusť proti databázi MyInvoice.

KONFIGURACE (env nebo uprav níže):
  KH_DIR   = složka s podanými KH (podsložky <rok>_<měsíc>/, uvnitř DPHKH1-*.zip nebo .pdf)
  EXPORT   = money-export.json (z export-money.ps1)
  OUT_SQL  = výstupní SQL
Spuštění:  KH_DIR=... EXPORT=money-export.json python correct-duzp-from-kh.py
"""
import os, glob, zipfile, re, json, sys
try:
    import pypdf
except ImportError:
    pypdf = None

KH_DIR  = os.environ.get("KH_DIR", r".\kontrolni-hlaseni")
EXPORT  = os.environ.get("EXPORT", "money-export.json")
OUT_SQL = os.environ.get("OUT_SQL", "fix-duzp.sql")
# Regex čísla dokladu v PDF (fallback když chybí strojový XML). Uprav dle svého číslování.
DOCNO_RE = os.environ.get("DOCNO_RE", r"\d{4,}")

def conv(d):  # "DD.MM.YYYY" -> "YYYY-MM-DD"
    p = d.replace(" ", "").split(".")
    return "%04d-%02d-%02d" % (int(p[2]), int(p[1]), int(p[0])) if len(p) == 3 else None

def kh_texts(folder):
    """Vrátí (xml_texts, pdf_texts) pro období; preferuje strojový XML ze zipu."""
    xmls, pdfs = [], []
    for z in glob.glob(os.path.join(folder, "**", "DPHKH1-*.zip"), recursive=True):
        try:
            with zipfile.ZipFile(z) as zf:
                for n in zf.namelist():
                    if n.lower().endswith(".xml"):
                        xmls.append(zf.read(n).decode("utf-8", "replace"))
        except Exception:
            pass
    if not xmls and pypdf:
        for f in glob.glob(os.path.join(folder, "DPHKH1-*.pdf")):
            if "potvrzeni" in os.path.basename(f):
                continue
            try:
                pdfs.append("\n".join(p.extract_text() for p in pypdf.PdfReader(f).pages))
            except Exception:
                pass
    return xmls, pdfs

def main():
    if not os.path.isdir(KH_DIR):
        print("CHYBA: KH_DIR neexistuje:", KH_DIR); sys.exit(1)
    a4 = {}                # c_evid_dd (varsymbol) -> dppd     (vydané)
    b2 = []               # (dic_bez_CZ, zaklad_round, dppd)   (přijaté)
    for folder in sorted(glob.glob(os.path.join(KH_DIR, "[0-9]" * 4 + "_" + "[0-9]" * 2))):
        xmls, pdfs = kh_texts(folder)
        for x in xmls:
            for m in re.finditer(r'<VetaA4\b[^>]*?c_evid_dd="([^"]*)"[^>]*?dppd="([^"]*)"', x):
                a4[m.group(1).strip()] = conv(m.group(2))
            for m in re.finditer(r'<VetaA4\b[^>]*?dppd="([^"]*)"[^>]*?c_evid_dd="([^"]*)"', x):
                a4[m.group(2).strip()] = conv(m.group(1))
            for m in re.finditer(r'<VetaB2\b[^>]*>', x):
                tag = m.group(0)
                dic = re.search(r'dic_dod="([^"]*)"', tag)
                dp  = re.search(r'dppd="([^"]*)"', tag)
                zb  = re.search(r'zakl_dane1="([^"]*)"', tag)
                if dic and dp and zb:
                    b2.append((dic.group(1).strip().upper().replace("CZ", ""),
                               round(float(zb.group(1))), conv(dp.group(1))))
        for t in pdfs:        # PDF fallback jen pro A.4 (číslo+datum jsou vedle sebe)
            for m in re.finditer(r"\b(%s)\s+(\d{2}\.\d{2}\.\d{4})" % DOCNO_RE, t):
                a4.setdefault(m.group(1), conv(m.group(2)))
    print("KH: A.4 (vydané) %d | B.2 (přijaté) %d" % (len(a4), len(b2)))

    d = json.load(open(EXPORT, encoding="utf-8"))
    dic_by_idx = {a["index"]: (a.get("dic") or "").strip().upper().replace("CZ", "") for a in d["adresar"]}
    from collections import defaultdict
    b2idx = defaultdict(list)
    for dic, base, dp in b2:
        b2idx[(dic, base)].append(dp)

    L = ["-- FÁZE 3: oprava DUZP z podaných KH (vydané A.4 + přijaté B.2)"]
    n_v = n_p = 0
    for inv in d["issued"]:
        dp = a4.get(inv["doklad"])
        if dp and dp != inv["tax_date"]:
            L.append("UPDATE invoices SET tax_date='%s', issue_date='%s' WHERE varsymbol='%s' AND invoice_type='invoice';" % (dp, dp, inv["doklad"]))
            n_v += 1
    for p in d["received"]:
        dic = dic_by_idx.get(p["dodavatel"], "")
        base = round(p["total_with_vat"] / 1.21)
        cand = b2idx.get((dic, base)) or b2idx.get((dic, base + 1)) or b2idx.get((dic, base - 1))
        if cand and cand[0] and cand[0] != p["tax_date"]:
            L.append("UPDATE purchase_invoices SET tax_date='%s' WHERE vendor_invoice_number='%s';" % (cand[0], p["doklad"]))
            n_p += 1
    open(OUT_SQL, "w", encoding="utf-8").write("\n".join(L) + "\n")
    print("%s: vydané %d + přijaté %d UPDATE. Zkontroluj a spusť proti DB MyInvoice." % (OUT_SQL, n_v, n_p))

if __name__ == "__main__":
    main()
