# === FAZE 1: Export Money S3 -> JSON (32-bit, READ-ONLY) ===
# Cte data Money S3 pres COM (mon2kdbe.BFTable) a zapise money-export.json pro import-myinvoice.py.
# MUSI bezet 32-bit Windows PowerShell:
#   C:\Windows\SysWOW64\WindowsPowerShell\v1.0\powershell.exe -NoProfile -File export-money.ps1
# (RemoteSigned policy spousti lokalni .ps1 bez -ExecutionPolicy Bypass)
#
# ===================== KONFIGURACE (uprav dle sebe) =====================
$DataRoot    = if ($env:MONEY_DATA) { $env:MONEY_DATA } else { "C:\Users\Public\Documents\Solitea\Money S3\Data" }
$AgendaName  = if ($env:MONEY_AGENDA) { $env:MONEY_AGENDA } else { "AGENDA.001" }   # ktera agenda
$OutJson     = if ($env:MONEY_OUT) { $env:MONEY_OUT } else { "$PSScriptRoot\money-export.json" }
$SupplierICO = if ($env:MONEY_ICO) { $env:MONEY_ICO } else { "12345678" }   # ZADEJ sve ICO (env MONEY_ICO nebo zde)            # tvoje ICO (vynecha se z klientu)
$YearFrom    = 1; $YearTo = 10                                                        # ROK.001..ROK.010 (rozsah let)
# =======================================================================
$ErrorActionPreference = "Stop"
$Agenda = "$DataRoot\$AgendaName"

function NewTbl($path) { $t = New-Object -ComObject "mon2kdbe.BFTable"; $t.Open($path) | Out-Null; return $t }
function Fmt-Date($v) { if ($v -is [datetime]) { return $v.ToString("yyyy-MM-dd") } ; if ($v) { try { return ([datetime]$v).ToString("yyyy-MM-dd") } catch { return $null } } ; return $null }
function ColVal($t,$c) { try { return $t.Value($c) } catch { return $null } }

# --- 1. AdresarF (raw poradi = index pro Odberatel/Dodavatel) ---
Write-Host "Ctu AdresarF..."
$adr = @()
$t = NewTbl "$Agenda\AdresarF.DAT"; $t.Top() | Out-Null; $idx = 0
while (-not $t.EOF) {
  $idx++
  $adr += [pscustomobject]@{
    index        = $idx
    company_name = ("" + (ColVal $t "ObchNazev"));
    nazev        = ("" + (ColVal $t "Nazev"))
    ic           = ("" + (ColVal $t "ICO")).Trim()
    dic          = ("" + (ColVal $t "DIC")).Trim()
    street       = ("" + (ColVal $t "Ulice")).Trim()
    city         = ("" + (ColVal $t "Misto")).Trim()
    zip          = (("" + (ColVal $t "PSC")) -replace "\s","")
    country_code = ("" + (ColVal $t "KodStatu")).Trim()
  }
  $t.Next() | Out-Null
}
$t.Close()
Write-Host ("  AdresarF: " + $adr.Count + " zaznamu")

# --- helper: nacti hlavicky + dedup ghost (dle Doklad, ponech nejvyssi Cislo) ---
function ReadHeaders($path, $partnerCol) {
  $rows = @{}   # Doklad -> record (keep highest Cislo)
  $t = NewTbl $path; $t.Top() | Out-Null
  while (-not $t.EOF) {
    $dok = "" + (ColVal $t "Doklad")
    $cis = [int](ColVal $t "Cislo")
    # DUZP = autoritativni pole PlnenoDPH (datum zd. plneni), vystaveni = Vystaveno.
    # NE DatUcPr (datum ucetniho pripadu/zauctovani) - to muze byt o mesic pozdeji a poslalo by doklad do spatneho obdobi DPH.
    $vyst = (ColVal $t "Vystaveno"); if (-not $vyst) { $vyst = (ColVal $t "DatUcPr") }   # fallback DatUcPr
    $duzp = (ColVal $t "PlnenoDPH"); if (-not $duzp) { $duzp = (ColVal $t "DatUcPr") }   # fallback DatUcPr
    $rec = [pscustomobject]@{
      doklad   = $dok
      cislo    = $cis
      popis    = ("" + (ColVal $t "Popis"))
      issue    = Fmt-Date $vyst   # datum vystaveni
      taxdate  = Fmt-Date $duzp   # DUZP (datum zdanitelneho plneni) = obdobi DPH
      duedate  = Fmt-Date (ColVal $t "Splatno")   # datum splatnosti
      total    = [double](ColVal $t "CelkemSDPH")
      paid     = Fmt-Date (ColVal $t "Uhrazeno")  # datum uhrady (prazdne = neuhrazeno)
      mena     = ("" + (ColVal $t "Mena")).Trim()
      kurs     = [double](ColVal $t "Kurs")
      partner  = [int](ColVal $t $partnerCol)
      koddph   = ("" + (ColVal $t "KodDPH"))
      guid     = ("" + (ColVal $t "GUID"))
      prijatdokl = ("" + (ColVal $t "PrijatDokl"))   # cislo dokladu dodavatele (jen prijate) -> KH A.2 c_evid_dd
      dobropis = [bool](ColVal $t "Dobropis")        # priznak dobropisu (opravny danovy doklad) -> credit_note, castky zaporne
    }
    if (-not $rows.ContainsKey($dok) -or $rows[$dok].cislo -lt $cis) { $rows[$dok] = $rec }
    $t.Next() | Out-Null
  }
  $t.Close()
  return $rows
}

# --- helper: nacti polozky -> hash Cislo -> [polozky] ---
function ReadItems($path) {
  $map = @{}
  if (-not (Test-Path $path)) { return $map }
  $t = NewTbl $path; $t.Top() | Out-Null; $any = $false
  while (-not $t.EOF) {
    $any = $true
    $cis = [int](ColVal $t "Cislo")
    $it = [pscustomobject]@{
      poradi = [int](ColVal $t "Poradi")
      popis  = ("" + (ColVal $t "Popis"))
      pocet  = [double](ColVal $t "PocetMJ")
      cena   = [double](ColVal $t "Cena")
      cenatyp= [int](ColVal $t "CenaTyp")
      sazba  = [double](ColVal $t "SazbaDPH")
      sleva  = [double](ColVal $t "Sleva")
    }
    if (-not $map.ContainsKey($cis)) { $map[$cis] = @() }
    $map[$cis] += $it
    $t.Next() | Out-Null
  }
  $t.Close()
  return $map
}

# --- 2. projdi roky ROK.001..ROK.010 (2017..2026) ---
$issued = @()
$received = @()
for ($r = $YearFrom; $r -le $YearTo; $r++) {
  $year = 2016 + $r
  $rok = "$Agenda\ROK." + ("{0:D3}" -f $r)
  if (-not (Test-Path $rok)) { continue }
  # vydane
  if (Test-Path "$rok\VFaktury.DAT") {
    $vh = ReadHeaders "$rok\VFaktury.DAT" "Odberatel"
    $vi = ReadItems "$rok\VFaktPol.DAT"
    foreach ($k in $vh.Keys) {
      $h = $vh[$k]
      $items = @()
      if ($vi.ContainsKey($h.cislo)) { $items = $vi[$h.cislo] | Sort-Object poradi }
      $issued += [pscustomobject]@{
        year=$year; doklad=$h.doklad; cislo=$h.cislo; popis=$h.popis
        issue_date=$h.issue; tax_date=$h.taxdate; due_date=$h.duedate; total_with_vat=$h.total; paid_date=$h.paid
        mena=$h.mena; kurs=$h.kurs; odberatel=$h.partner; koddph=$h.koddph; guid=$h.guid
        items=$items
      }
    }
  }
  # prijate
  if (Test-Path "$rok\PFaktury.DAT") {
    $ph = ReadHeaders "$rok\PFaktury.DAT" "Dodavatel"
    foreach ($k in $ph.Keys) {
      $h = $ph[$k]
      $received += [pscustomobject]@{
        year=$year; doklad=$h.doklad; cislo=$h.cislo; popis=$h.popis
        issue_date=$h.issue; tax_date=$h.taxdate; due_date=$h.duedate; total_with_vat=$h.total; paid_date=$h.paid
        mena=$h.mena; kurs=$h.kurs; dodavatel=$h.partner; koddph=$h.koddph; guid=$h.guid; prijatdokl=$h.prijatdokl; dobropis=$h.dobropis
      }
    }
  }
}

$out = [pscustomobject]@{
  exported_at = (Get-Date).ToString("s")
  supplier_ico = $SupplierICO
  adresar = $adr
  issued = $issued
  received = $received
}
$json = $out | ConvertTo-Json -Depth 8
$enc = New-Object System.Text.UTF8Encoding($false)
[System.IO.File]::WriteAllText($OutJson, $json, $enc)

# --- souhrn ---
Write-Host ""
Write-Host "=== SOUHRN EXPORTU ==="
Write-Host ("AdresarF zaznamu : " + $adr.Count)
Write-Host ("Vydane faktury   : " + $issued.Count)
Write-Host ("Prijate faktury  : " + $received.Count)
$byYearV = $issued | Group-Object year | Sort-Object Name | ForEach-Object { $_.Name + ":" + $_.Count }
$byYearP = $received | Group-Object year | Sort-Object Name | ForEach-Object { $_.Name + ":" + $_.Count }
Write-Host ("Vydane/rok  : " + ($byYearV -join "  "))
Write-Host ("Prijate/rok : " + ($byYearP -join "  "))
$sumV = ($issued | Measure-Object total_with_vat -Sum).Sum
$sumP = ($received | Measure-Object total_with_vat -Sum).Sum
Write-Host ("Suma vydane (s DPH)  : " + $sumV)
Write-Host ("Suma prijate (s DPH) : " + $sumP)
$noItems = ($issued | Where-Object { $_.items.Count -eq 0 }).Count
Write-Host ("Vydane bez polozek   : " + $noItems)
Write-Host ("JSON zapsan: " + $OutJson + " (" + [math]::Round((Get-Item $OutJson).Length/1kb,1) + " kB)")
Write-Host "HOTOVO"
