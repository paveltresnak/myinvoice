import { api } from './client'

export interface DashboardKpi {
  per_currency: Array<{
    currency: string
    this_year: number
    prev_year: number
    prev_year_ytd: number
    change_pct: number | null
    this_year_invoice_count: number
    prev_year_invoice_count: number
    this_year_client_count: number
    prev_year_client_count: number
    this_year_project_count: number
    prev_year_project_count: number
  }>
  issued_count_ytd: number
  overdue_count: number
  overdue_per_currency: Array<{ currency: string; count: number; total: number }>
  avg_payment_days: number | null
  status_counts_ytd?: Record<string, number>
}

export interface DashboardInvoiceItem {
  id: number
  varsymbol: string | null
  invoice_type: string
  client_id: number
  client_company_name: string
  currency: string
  issue_date: string
  due_date: string
  amount_to_pay: number
  status: string
  days_overdue: number | null
}

export interface TopClient {
  client_id: number
  company_name: string
  currency: string
  total: number
  invoice_count: number
}

export interface RevenueByMonth {
  currency: string
  /** 12 entries, ascending, ending in current month */
  months: Array<{ ym: string; total: number }>
  /** Stejných 12 měsíců o rok dříve (porovnávací řada) */
  prev_year: Array<{ ym: string; total: number }>
}

export interface Rolling12mRevenue {
  currency: string
  /** Plovoucí 12měsíční obrat (rolling) — relevantní pro DPH limit (2 mil. CZK / 12 měsíců) */
  total: number
  /** Tentýž součet o 12 měsíců dříve — pro YoY srovnání */
  prev_period_total: number
}

export interface RevenueByYear {
  year: number
  currency: string
  total: number
  invoice_count: number
}

export interface CashflowByCurrency {
  currency: string
  months: Array<{ ym: string; total: number }>
  prev_year: Array<{ ym: string; total: number }>
}

export interface PaymentDaysHistogram {
  buckets: Array<{ key: string; label: string; count: number }>
  total: number
  avg_days: number | null
}

export interface VatBreakdownItem {
  label: string
  base: number
  currency: string
}

export interface CashflowForecast {
  currency: string
  in_30: number
  in_60: number
  in_90: number
  count_30: number
  count_60: number
  count_90: number
}

export interface DueBucket {
  currency: string
  today_count: number
  today_total: number
  week_count: number
  week_total: number
  month_count: number
  month_total: number
}

export interface AgingReportRow {
  currency: string
  current: number
  b1_30: number
  b31_60: number
  b61_90: number
  b90_plus: number
  current_n: number
  b1_30_n: number
  b31_60_n: number
  b61_90_n: number
  b90_plus_n: number
}

export interface RevenueForecast {
  currency: string
  ytd: number
  prev_year_ytd: number
  prev_year_remainder: number
  growth_ratio: number
  forecast: number
  prev_year_full: number
}

export interface Revenue30d {
  currency: string
  total: number
  invoice_count: number
}

export interface InvoiceSizeHistogram {
  buckets: Array<{ key: string; label: string; count: number; total_czk: number }>
  total: number
}

export interface DashboardSummary {
  kpi: DashboardKpi
  overdue: DashboardInvoiceItem[]
  unpaid_upcoming: DashboardInvoiceItem[]
  top_clients_ytd: TopClient[]
  top_clients_prev_year: TopClient[]
  top_clients_12m: TopClient[]
  revenue_by_month: RevenueByMonth[]
  revenue_by_year: RevenueByYear[]
  rolling_12m: Rolling12mRevenue[]
  cashflow_ytd: CashflowByCurrency[]
  payment_days_histogram: PaymentDaysHistogram
  vat_breakdown_12m: VatBreakdownItem[]
  cashflow_forecast: CashflowForecast[]
  due_buckets: DueBucket[]
  aging_report: AgingReportRow[]
  revenue_forecast: RevenueForecast[]
  invoice_size_histogram: InvoiceSizeHistogram
  revenue_last_30d: Revenue30d[]
  active_recurring_count: number
  active_clients_count: number
  pending_approvals?: { requested: number; overdue: number }
  today: string
  year: number
  prev_year: number
  is_vat_payer: boolean
}

export const dashboardApi = {
  summary: () => api.get<DashboardSummary>('/dashboard/summary').then(r => r.data),
}
