import { api } from './client'

export type PurchaseInvoiceStatus = 'draft' | 'received' | 'booked' | 'paid' | 'cancelled'
export type PurchaseDocumentKind = 'invoice' | 'receipt' | 'credit_note' | 'advance'
export type ExchangeRateSource = 'cnb' | 'manual' | 'idoklad' | 'fakturoid'

export interface PurchaseInvoiceItem {
  id?: number
  purchase_invoice_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  total_without_vat?: number
  total_vat?: number
  total_with_vat?: number
  order_index: number
  vat_classification_code?: string | null
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
}

export interface PurchaseVatBreakdownRow {
  vat_rate: number
  without_vat: number
  vat: number
  with_vat: number
}

export interface PurchaseInvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
}

export interface VendorSnapshot {
  id?: number
  company_name?: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  dic?: string | null
  street?: string
  city?: string
  zip?: string
  main_email?: string
  phone?: string | null
  language?: 'cs' | 'en'
  country_iso2?: string
  country_name_cs?: string
  country_name_en?: string
}

export interface PurchaseInvoice {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  exchange_rate: number | null
  exchange_rate_date: string | null
  exchange_rate_source: ExchangeRateSource
  reverse_charge: boolean
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  vendor_snapshot: VendorSnapshot | null
  own_snapshot: Record<string, unknown> | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
  // Multi-currency platba (USD faktura placená z CZK účtu)
  payment_currency_id: number | null
  payment_currency: string | null
  payment_exchange_rate: number | null
  paid_amount_payment_ccy: number | null
  paid_amount_invoice_ccy: number | null
  exchange_diff_base: number | null
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  pdf_path: string | null
  pdf_hash: string | null
  pdf_size_bytes: number | null
  pdf_original_name: string | null
  pdf_uploaded_at: string | null
  vat_classification_code: string | null
  expense_category_id: number | null
  created_by: number
  created_at: string
  updated_at: string
  // Joined fields
  vendor_company_name?: string
  vendor_ic?: string | null
  vendor_dic?: string | null
  vendor_main_email?: string
  vendor_language?: 'cs' | 'en'
  // Related
  items: PurchaseInvoiceItem[]
  vat_breakdown: PurchaseVatBreakdownRow[]
  totals: PurchaseInvoiceTotals
}

export interface PurchaseInvoiceListItem {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  vendor_company_name: string
  vendor_ic: string | null
  month_bucket: string
}

export interface PurchaseMonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
  }>
  invoices: PurchaseInvoiceListItem[]
}

export interface PurchaseInvoicePayload {
  vendor_id: number
  vendor_invoice_number: string
  document_kind?: PurchaseDocumentKind
  varsymbol?: string | null
  issue_date: string
  tax_date?: string | null
  due_date: string
  received_at?: string
  currency_id: number
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  exchange_rate_source?: ExchangeRateSource
  reverse_charge?: boolean
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  payment_currency_id?: number | null
  payment_exchange_rate?: number | null
  paid_amount_payment_ccy?: number | null
  paid_amount_invoice_ccy?: number | null
  exchange_diff_base?: number | null
  vat_classification_code?: string | null
  expense_category_id?: number | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
    vat_classification_code?: string | null
  }>
}

export interface PurchaseListFilters {
  status?: PurchaseInvoiceStatus | PurchaseInvoiceStatus[]
  document_kind?: PurchaseDocumentKind | PurchaseDocumentKind[]
  vendor_id?: number
  year?: number
  month?: number
  date_from?: string
  date_to?: string
  currency?: string
  unpaid_only?: boolean
  overdue?: boolean
  q?: string
  page?: number
  per_page?: number
}

export interface PurchaseListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export interface InboxScanResultDetail {
  file: string
  status: 'created' | 'skipped' | 'failed' | 'rejected' | 'mapper_pending' | 'config_missing' | 'inbox_missing' | 'limit_reached'
  reason?: string
  purchase_invoice_id?: number
  isdoc_invoice_count?: number
  supplier_ic?: string | null
}

export interface InboxScanResult {
  created: number
  skipped: number
  failed: number
  dry_run: boolean
  inbox_dir: string
  details: InboxScanResultDetail[]
}

export const purchaseInvoicesApi = {
  listGrouped: (filters: PurchaseListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    }
    if (filters.document_kind) {
      params['filter[document_kind]'] = Array.isArray(filters.document_kind)
        ? filters.document_kind.join(',')
        : filters.document_kind
    }
    if (filters.vendor_id)   params['filter[vendor_id]']   = filters.vendor_id
    if (filters.year)        params['filter[year]']        = filters.year
    if (filters.month)       params['filter[month]']       = filters.month
    if (filters.date_from)   params['filter[date_from]']   = filters.date_from
    if (filters.date_to)     params['filter[date_to]']     = filters.date_to
    if (filters.currency)    params['filter[currency]']    = filters.currency
    if (filters.unpaid_only) params['filter[unpaid_only]'] = 1
    if (filters.overdue)     params['filter[overdue]']     = 1
    if (filters.page)        params.page                   = filters.page
    if (filters.per_page)    params.per_page               = filters.per_page
    return api.get<{ data: PurchaseMonthGroup[]; meta: PurchaseListMeta }>(
      '/purchase-invoices',
      { params },
    ).then(r => r.data)
  },

  get:    (id: number) => api.get<PurchaseInvoice>(`/purchase-invoices/${id}`).then(r => r.data),
  create: (payload: PurchaseInvoicePayload) =>
    api.post<PurchaseInvoice>('/purchase-invoices', payload).then(r => r.data),
  update: (id: number, payload: PurchaseInvoicePayload, force = false) =>
    api.put<PurchaseInvoice>(
      `/purchase-invoices/${id}${force ? '?force=1' : ''}`,
      payload,
    ).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ ok: boolean }>(`/purchase-invoices/${id}`).then(r => r.data),

  setItems: (id: number, items: PurchaseInvoicePayload['items']) =>
    api.put<PurchaseInvoice>(`/purchase-invoices/${id}/items`, { items }).then(r => r.data),

  setExchangeRate: (id: number, rate: number | null, rateDate: string | null, source: ExchangeRateSource = 'manual') =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/exchange-rate`, {
      rate, rate_date: rateDate, source,
    }).then(r => r.data),

  transition: (id: number, target: PurchaseInvoiceStatus, paidDate?: string) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/transition`, {
      target,
      ...(target === 'paid' ? { paid_date: paidDate || new Date().toISOString().slice(0, 10) } : {}),
    }).then(r => r.data),

  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<{ ok: boolean; pdf_path: string; pdf_hash: string; pdf_size_bytes: number; pdf_original_name: string }>(
      `/purchase-invoices/${id}/pdf`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  deletePdf: (id: number) =>
    api.delete<{ ok: boolean; file_deleted: boolean; still_used_by: number }>(
      `/purchase-invoices/${id}/pdf`,
    ).then(r => r.data),

  activity: (id: number) =>
    api.get<Array<{
      id: number; user_id: number | null; user_email: string | null; user_name: string | null;
      action: string; payload: Record<string, unknown> | null; ip: string | null; created_at: string;
    }>>(`/purchase-invoices/${id}/activity`).then(r => r.data),

  pdfUrl: (id: number, inline = false) => {
    // Přímá navigace v prohlížeči — supplier_id v query param (X-Supplier-Id header se neposílá).
    // inline=true → Content-Disposition: inline (iframe preview; bez tohoto Edge/IE blokuje pro attachment).
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (inline) params.set('inline', '1')
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/pdf${qs ? '?' + qs : ''}`
  },

  scanInbox: (dryRun = false) =>
    api.post<InboxScanResult>('/purchase-invoices/scan-inbox', { dry_run: dryRun }).then(r => r.data),

  /**
   * Export ZIP s archivovanými vendor PDF za měsíc.
   * Priorita: pokud purchase_invoice.pdf_path je set, použije ho; jinak fakturu skipne.
   * Vrací URL pro přímou navigaci (axios by stáhl jako blob).
   */
  exportUrl: (month: string, dateBy: 'tax' | 'issue' | 'received' = 'tax') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ month, format: 'pdf-zip', date_by: dateBy })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/purchase-invoices/export?${params.toString()}`
  },
}
