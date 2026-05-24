#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Money S3 -> MyInvoice import (faze 2). Cte money-export.json (vystup export-money.ps1)
a tlaci data pres MyInvoice REST API v1. Community verze, idempotentni.

CO DELA:
  - klienti (dedup dle ICO; odberatel se urci az dle vystavenych faktur)
  - vydane faktury vc. polozek (issue_date=DUZP, due_date=splatnost, paid_at=uhrazeno)
  - prijate faktury (default sazba 21 %); EU porizeni s reverse-charge se detekuje
    automaticky dle DIC dodavatele (zahranicni EU) -> DPHDP3 r.3, danove neutralni

KONFIGURACE (env promenne nebo uprav nize):
  MI_TOKEN  = API token (Personal Access Token, scope read_write)  [POVINNE]
  MI_URL    = URL MyInvoice instance (napr. https://myinvoice.example.com:9443)
  MI_EXPORT = cesta k money-export.json
Spusteni:   MI_TOKEN=mi_pat_xxx  MI_URL=https://...  python import-myinvoice.py
Idempotence: opakovane spusteni preskoci jiz existujici (dle ICO/varsymbol/cisla).
"""
import os, sys, json, time, urllib.request, urllib.error

# ===================== KONFIGURACE =====================
BASE      = os.environ.get("MI_URL", "https://localhost:9443").rstrip("/")
EXPORT    = os.environ.get("MI_EXPORT", os.path.join(os.path.dirname(os.path.abspath(__file__)), "money-export.json"))
TOKEN     = os.environ.get("MI_TOKEN", "").strip()
DEFAULT_PURCHASE_VAT_RATE = 21          # sazba pro prijate faktury (Money je casto bez polozek)
MARK_RECEIVED_PAID = os.environ.get("MI_MARK_PAID", "0") == "1"   # MI_MARK_PAID=1 = oznacit vsechny prijate jako paid (kdyz Money uhrady neevidoval)
# Rucni oprava DUZP pro post-datovane faktury (Money DatUcPr = datum tisku, ne skutecne DUZP).
# Pozna se: faktura vystavena na konci mesice s DUZP 1. dalsiho mesice. Format {"doklad":"YYYY-MM-DD"}.
DUZP_OVERRIDE = {}                      # napr. {"2412345":"2025-01-01"}
# ======================================================

MUT_SLEEP = float(os.environ.get("MI_SLEEP", "0"))   # prodleva mezi zapisy (s); 0 = bez brzdy (vyzaduje zvednuty serverovy rate-limit, jinak 429 retry); 0.1 ≈ 600/min
VAT_MAP = {21: 1, 12: 2, 0: 3}          # SazbaDPH -> vat_rate_id (zjisti si /api/v1/codebooks/vat-rates)
EU_ACQUISITION_CLASS = "23"            # vat_classification kod 23 = porizeni zbozi z JCS (reverse charge) -> DPHDP3 r.3 + r.43 + KH A.2
EU_RC_PREFIXES = {"DE","SK","AT","PL","HU","SI","NL","BE","FR","IT","ES","DK","SE","FI",
                  "IE","PT","LU","RO","BG","HR","GR","EE","LV","LT","MT","CY"}
if not TOKEN:
    print("CHYBA: nastav MI_TOKEN (API token)."); sys.exit(1)

def as_list(x):
    if x is None: return []
    if isinstance(x, dict): return [x]
    return x if isinstance(x, list) else []

def call(method, path, body=None, mut=False):
    data = json.dumps(body).encode("utf-8") if body is not None else None
    for _ in range(7):
        req = urllib.request.Request(BASE + path, data=data, method=method)
        req.add_header("Authorization", "Bearer " + TOKEN)
        if data is not None: req.add_header("Content-Type", "application/json")
        try:
            with urllib.request.urlopen(req, timeout=40) as r:
                out = r.read()
                if mut: time.sleep(MUT_SLEEP)
                return r.status, (json.loads(out) if out else {})
        except urllib.error.HTTPError as e:
            if e.code == 429:
                ra = e.headers.get("Retry-After"); time.sleep(min(int(ra) if (ra and ra.isdigit()) else 35, 65)); continue
            if e.code >= 500: time.sleep(5); continue
            try: return e.code, json.loads(e.read())
            except: return e.code, {}
        except Exception:
            time.sleep(4); continue
    return 0, {"error": "retry exhausted"}

def collect(o, key, acc):
    if isinstance(o, dict):
        for k, v in o.items():
            if k == key and isinstance(v, str): acc.add(v)
            else: collect(v, key, acc)
    elif isinstance(o, list):
        for v in o: collect(v, key, acc)

def fetch_all_key(path, key):
    acc = set(); page = 1
    while True:
        st, r = call("GET", "%s?per_page=200&page=%d" % (path, page))
        if st != 200: break
        collect(r, key, acc)
        meta = r.get("meta") or {}
        if page >= (meta.get("pages", 1) or 1): break
        page += 1
        if page > 60: break
    return acc

def ne(s, d): s = (s or "").strip(); return s if s else d

def valid_ic(ic):
    """Validace ceskeho ICO: 8 cislic + kontrolni cislice (mod 11). Cizi/nevalidni -> False."""
    ic = (ic or "").strip()
    if not (ic.isdigit() and len(ic) == 8): return False
    s = sum(int(ic[i]) * (8 - i) for i in range(7))
    return (11 - s % 11) % 10 == int(ic[7])

d = json.load(open(EXPORT, encoding="utf-8"))
SUP_ICO = d.get("supplier_ico", "")
adr_by_idx = {a["index"]: a for a in d["adresar"]}

print("== codebooks ==")
_, cc = call("GET", "/api/v1/codebooks/countries")
COUNTRIES = {c["iso2"]: c["id"] for c in cc}; CZ = COUNTRIES.get("CZ", 1)
_, cur = call("GET", "/api/v1/codebooks/currencies")
CURRENCIES = {c["code"]: c["id"] for c in cur} if isinstance(cur, list) else {}
if not CURRENCIES: CURRENCIES = {"CZK": 1, "EUR": 2}

def country_of(code, dic):
    code = (code or "").strip().upper()
    if code in COUNTRIES: return COUNTRIES[code]
    pref = (dic or "").strip().upper()[:2]
    return COUNTRIES.get(pref, CZ)

print("== preload (idempotence) ==")
ic_to_id = {}; dic_to_id = {}; page = 1
while True:
    st, r = call("GET", "/api/v1/clients?per_page=200&page=%d" % page)
    if st != 200: break
    for c in (r.get("data") or r.get("clients") or []):
        if c.get("ic"): ic_to_id[str(c["ic"]).strip()] = c["id"]
        if c.get("dic"): dic_to_id[str(c["dic"]).strip().upper()] = c["id"]
    meta = r.get("meta") or {}
    if page >= (meta.get("pages", 1) or 1): break
    page += 1
existing_vs  = fetch_all_key("/api/v1/invoices", "varsymbol")
existing_vin = fetch_all_key("/api/v1/purchase-invoices", "vendor_invoice_number")
print("  klienti(ic):%d vydane(vs):%d prijate(vin):%d" % (len(ic_to_id), len(existing_vs), len(existing_vin)))

errs = []
# ---------- KLIENTI ----------
print("== KLIENTI ==")
index_to_id = {}; c_new = c_skip = c_err = 0
# Role se nastavi az ve FINALNIM pruchodu dle SKUTECNE vytvorenych faktur (ground truth) -
# create-time odhad dle export indexu je nespolehlivy (dedup, vendor dobropisy v issued).
real_customers = set(); real_vendors = set()   # cid -> ma vytvorenou vydanou / prijatou
cid_body = {}                                   # cid -> telo klienta (pro finalni PUT role)
for a in d["adresar"]:
    if a["index"] == 1 or (a["ic"] and a["ic"] == SUP_ICO): continue
    ic_raw = (a["ic"] or "").strip()
    dic = (a.get("dic") or "").strip()
    ic = ic_raw if valid_ic(ic_raw) else ""        # cizi/nevalidni ICO se nepouzije jako ic (jinak API klienta odmitne -> doklad by se preskocil)
    if ic_raw and not ic:
        print("  ! nevalidni ICO '%s' (%s) -> zakladam bez ICO%s" % (ic_raw, ne(a["company_name"], a.get("nazev") or "?"), (", dedup dle DIC" if dic else "")))
    if ic and ic in ic_to_id: index_to_id[a["index"]] = ic_to_id[ic]; c_skip += 1; continue
    if not ic and dic and dic.upper() in dic_to_id: index_to_id[a["index"]] = dic_to_id[dic.upper()]; c_skip += 1; continue
    name = ne(a["company_name"], a.get("nazev") or ("Klient %d" % a["index"]))
    email = (ic + "@imported.local") if ic else ((dic.lower() + "@imported.local") if dic else ("adr%d@imported.local" % a["index"]))
    body = {"company_name": name, "street": ne(a["street"], "neuvedeno"), "city": ne(a["city"], "neuvedeno"),
            "zip": ne(a["zip"], "00000"), "country_id": country_of(a.get("country_code"), dic),
            "main_email": email, "language": "cs",
            "is_customer": False, "is_vendor": True}   # default vendor (API vynuti is_customer pri obou false!); finalni pruchod opravi 4 odberatele
    if ic: body["ic"] = ic
    if dic: body["dic"] = dic
    st, r = call("POST", "/api/v1/clients", body, mut=True)
    if st == 201:
        index_to_id[a["index"]] = r["id"]
        if ic: ic_to_id[ic] = r["id"]
        if dic: dic_to_id[dic.upper()] = r["id"]
        cid_body[r["id"]] = body
        c_new += 1
    else:
        c_err += 1; errs.append("KLIENT %s: %s %s" % (name, st, json.dumps(r, ensure_ascii=False)[:140]))
print("  novych:%d skip:%d chyb:%d" % (c_new, c_skip, c_err))

# ---------- VYDANE ----------
print("== VYDANE FAKTURY ==")
v_new = v_skip = v_err = 0
for inv in d["issued"]:
    vs = inv["doklad"]
    if vs in existing_vs: v_skip += 1; continue
    cid = index_to_id.get(inv["odberatel"])
    if not cid: v_err += 1; errs.append("VYDANA %s: chybi klient idx %s" % (vs, inv["odberatel"])); continue
    duzp = DUZP_OVERRIDE.get(vs, inv["tax_date"])     # DUZP = DatUcPr (s rucni opravou pro post-datovane)
    items = []
    for it in as_list(inv.get("items")):
        rid = VAT_MAP.get(int(round(it["sazba"])), 1)
        items.append({"description": ne(it["popis"], "Polozka"), "quantity": it["pocet"],
                      "unit_price_without_vat": it["cena"], "vat_rate_id": rid})
        if it.get("sleva"): items.append({"description": "Sleva", "quantity": 1, "unit_price_without_vat": -it["sleva"], "vat_rate_id": rid})
    if not items:
        items = [{"description": ne(inv["popis"], "Sluzba"), "quantity": 1,
                  "unit_price_without_vat": round(inv["total_with_vat"]/(1+DEFAULT_PURCHASE_VAT_RATE/100), 2), "vat_rate_id": 1}]
    body = {"client_id": cid, "invoice_type": "invoice", "currency_id": 1, "varsymbol": vs, "items": items}
    if duzp: body["issue_date"] = duzp; body["tax_date"] = duzp     # vystaveni = DUZP
    if inv.get("due_date"): body["due_date"] = inv["due_date"]       # splatnost (Money Splatno)
    st, r = call("POST", "/api/v1/invoices", body, mut=True)
    if st != 201: v_err += 1; errs.append("VYDANA %s: %s %s" % (vs, st, json.dumps(r, ensure_ascii=False)[:140])); continue
    iid = r["id"]
    call("POST", "/api/v1/invoices/%d/issue" % iid, {}, mut=True)
    if inv.get("paid_date"):
        call("POST", "/api/v1/invoices/%d/mark-paid" % iid, {"paid_at": inv["paid_date"]}, mut=True)  # pole je paid_at!
    real_customers.add(cid)   # tento klient skutecne dostal vydanou fakturu = odberatel
    v_new += 1
print("  novych:%d skip:%d chyb:%d" % (v_new, v_skip, v_err))

# ---------- PRIJATE (vc. auto-detekce EU reverse charge) ----------
print("== PRIJATE FAKTURY ==")
p_new = p_skip = p_err = p_rc = 0
for p in d["received"]:
    doklad = p["doklad"]
    vend = adr_by_idx.get(p["dodavatel"], {})
    dic_pref = (vend.get("dic") or "").strip().upper()[:2]
    is_eu_rc = dic_pref in EU_RC_PREFIXES                 # EU porizeni s reverse charge (dle prefixu DIC dodavatele)
    # vendor_invoice_number = cislo dokladu DODAVATELE = Money PrijatDokl (= KH c_evid_dd); fallback Money Doklad
    vin = (p.get("prijatdokl") or "").strip() or doklad
    if not vin or vin in existing_vin: p_skip += 1; continue
    vid = index_to_id.get(p["dodavatel"])
    if not vid: p_err += 1; errs.append("PRIJATA %s: chybi dodavatel idx %s" % (vin, p["dodavatel"])); continue
    mena = (p.get("mena") or "").strip().upper()
    kurs = p.get("kurs") or 0
    cur_id = 1
    item = {"description": ne(p["popis"], "Nakup"), "quantity": 1, "vat_rate_id": 1}
    if is_eu_rc:
        item["vat_classification_code"] = EU_ACQUISITION_CLASS   # 23 -> r.3 + r.43 + KH A.2 (MyInvoice samovymeri dan ze sazby)
        if mena and mena != "CZK" and kurs and CURRENCIES.get(mena):
            cur_id = CURRENCIES[mena]                             # verne v puvodni mene
            item["unit_price_without_vat"] = round(p["total_with_vat"]/kurs, 2)   # RC = bez DPH; zaklad v cizi mene = CZK celkem / kurz; MyInvoice prepocte kurzem zpet
        else:
            item["unit_price_without_vat"] = p["total_with_vat"]  # fallback: zaklad rovnou v CZK
    else:
        item["unit_price_without_vat"] = round(p["total_with_vat"]/(1+DEFAULT_PURCHASE_VAT_RATE/100), 2)
    is_dobropis = bool(p.get("dobropis")) or (p["total_with_vat"] < 0)   # dobropis (opravny danovy doklad) -> credit_note, castky zaporne (dle manualu MyInvoice)
    body = {"vendor_id": vid, "vendor_invoice_number": vin,
            "document_kind": "credit_note" if is_dobropis else "invoice", "currency_id": cur_id,
            "reverse_charge": bool(is_eu_rc), "items": [item]}
    duzp = p["tax_date"]
    if cur_id != 1 and kurs: body["exchange_rate"] = kurs; body["exchange_rate_date"] = duzp
    if p.get("issue_date"): body["issue_date"] = p["issue_date"]; body["received_at"] = p["issue_date"]
    if duzp: body["tax_date"] = duzp
    body["due_date"] = p.get("due_date") or p.get("issue_date") or duzp
    st, r = call("POST", "/api/v1/purchase-invoices", body, mut=True)
    if st != 201: p_err += 1; errs.append("PRIJATA %s: %s %s" % (vin, st, json.dumps(r, ensure_ascii=False)[:140])); continue
    pid = r["id"]
    call("POST", "/api/v1/purchase-invoices/%d/transition" % pid, {"target": "received"}, mut=True)
    if p.get("paid_date") or MARK_RECEIVED_PAID:
        pay = p.get("paid_date") or duzp or p.get("issue_date")
        s, _ = call("POST", "/api/v1/purchase-invoices/%d/transition" % pid, {"target": "paid", "paid_date": pay}, mut=True)
        if s not in (200, 201):
            call("POST", "/api/v1/purchase-invoices/%d/transition" % pid, {"target": "booked"}, mut=True)
            call("POST", "/api/v1/purchase-invoices/%d/transition" % pid, {"target": "paid", "paid_date": pay}, mut=True)
    real_vendors.add(vid)   # tento klient skutecne dostal prijatou fakturu = dodavatel
    if is_eu_rc: p_rc += 1
    p_new += 1
print("  novych:%d (z toho EU reverse-charge:%d) skip:%d chyb:%d" % (p_new, p_rc, p_skip, p_err))

# ---------- FINALNI PRUCHOD: role dle skutecne vytvorenych faktur ----------
print("== ROLE (dle skutecnych faktur) ==")
r_cust = r_vend = 0
for cid in sorted(real_customers | real_vendors):
    b = dict(cid_body.get(cid) or {})
    if not b: continue
    b["is_customer"] = cid in real_customers
    b["is_vendor"] = cid in real_vendors
    call("PUT", "/api/v1/clients/%d" % cid, b, mut=True)
    if b["is_customer"]: r_cust += 1
    if b["is_vendor"]: r_vend += 1
print("  odberatelu:%d dodavatelu:%d" % (r_cust, r_vend))

print("\n== CHYBY (max 25) =="); [print(" -", e) for e in errs[:25]]
print("\nHOTOVO. klienti:+%d vydane:+%d prijate:+%d chyb:%d" % (c_new, v_new, p_new, len(errs)))
