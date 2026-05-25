import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
    submission_deadline: string
    supplier_vat_period: string
  }
  warnings: string[]
}

export interface DphSettings {
  vat_period: 'monthly' | 'quarterly' | null
  is_vat_payer: boolean
  taxpayer_type: 'fo' | 'po' | null
  has_financial_office: boolean
}

export interface DphTrendRow {
  period: string
  vat_output: number
  vat_input: number
  vat_due: number
}

export interface DphDraftsPrediction {
  year: number
  month: number
  period: 'monthly' | 'quarterly'
  vat_output: number
  vat_input: number
  tax_due: number
  sale_count: number
  sale_draft_count: number
  purchase_count: number
  purchase_draft_count: number
}

export interface DphBookRow {
  invoice_id: number
  direction: 'issued' | 'received'
  doc_number: string | null
  original_doc_number: string | null
  tax_date: string | null
  accounting_date: string | null
  description: string
  counterparty_name: string
  counterparty_dic: string
  vat_classification_code: string | null
  vat_rate: number
  currency: string
  exchange_rate: number
  base: number
  vat: number
  total: number
  status: string
  is_draft: boolean
  kh_section: string | null
}

export interface DphBookSection {
  key: string
  direction: 'USKUTEČNĚNÁ' | 'PŘIJATÁ'
  label: string
  dphdp3_line: string
  vat_rate: number
  is_secondary: boolean
  rows: DphBookRow[]
  subtotal_base: number
  subtotal_vat: number
  subtotal_total: number
}

export interface DphBookPreview {
  period: {
    year: number
    month: number
    start: string
    end: string
    label: string
  }
  supplier: Record<string, unknown>
  sections: DphBookSection[]
  totals: {
    issued: { base: number; vat: number; total: number }
    received: { base: number; vat: number; total: number }
    vat_balance: number
  }
}

export const reportsApi = {
  dphSettings: () =>
    api.get<DphSettings>('/reports/dphdp3/settings').then(r => r.data),

  dphPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  dphTrend: (months = 12) =>
    api.get<DphTrendRow[]>('/reports/dphdp3/trend', { params: { months } }).then(r => r.data),

  dphDraftsPrediction: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphDraftsPrediction>('/reports/dphdp3/drafts-prediction', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  khPreview: (year: number, month: number) =>
    api.get<{
      summary: {
        period: string
        a1_count: number
        a4_count: number
        a5_count_aggregated: number
        b1_count: number
        b2_count: number
        b3_count_aggregated: number
        submission_deadline: string
      }
      warnings: string[]
    }>('/reports/dphkh1/preview', { params: { year, month } }).then(r => r.data),

  // Souhrnné hlášení (EU dodání) — měsíční, plátci i identifikované osoby
  shvPreview: (year: number, month: number) =>
    api.get<{
      summary: {
        period: string
        rows_count: number
        total_amount: number
        rows: Array<{
          country_iso2: string
          vat_id: string
          sh_type: '0' | '1' | '2' | '3'
          amount: number
          count: number
          counterparty_name: string
        }>
        submission_deadline: string
      }
      warnings: string[]
    }>('/reports/dphshv/preview', { params: { year, month } }).then(r => r.data),

  shvDownloadUrl: (year: number, month: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphshv?${params.toString()}`
  },

  incomeTaxPreview: (year: number, type: 'fo' | 'po') =>
    api.get<{
      summary: {
        year: number
        taxpayer_type: 'fo' | 'po'
        revenue_orientacni: number
        costs_orientacni: number
        profit_orientacni: number
        submission_deadline: string
        currency: string
      }
      warnings: string[]
    }>('/reports/income-tax/preview', { params: { year, type } }).then(r => r.data),

  incomeTaxDownloadUrl: (year: number, type: 'fo' | 'po') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), type })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/income-tax?${params.toString()}`
  },

  khDownloadUrl: (year: number, month: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphkh1?${params.toString()}`
  },

  // Kniha DPH (interní VAT žurnál — NE EPO podání)
  dphBookPreview: (year: number, month: number) =>
    api.get<DphBookPreview>('/reports/dph-book/preview', { params: { year, month } }).then(r => r.data),

  dphBookPdfUrl: (year: number, month: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dph-book?${params.toString()}`
  },

  /** URL na download endpoint — frontend ho otevírá v novém okně */
  dphDownloadUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}
