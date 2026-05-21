import { api } from './client'

export interface CrmKpi {
  period?: string | null
  currency: string
  revenue: number
  revenue_net: number
  costs: number
  costs_net: number
  profit: number
  invoice_count: number
  purchase_count: number
  vat_output: number
  vat_input: number
}

export interface CrmOverview {
  current_month: CrmKpi[]
  last_month: CrmKpi[]
  ytd: CrmKpi[]
  currencies: string[]
}

export interface CrmMonthlyRow extends CrmKpi {
  period: string
}

export interface TopClient {
  client_id: number
  company_name: string
  revenue: number
  invoice_count: number
  currency: string
  percent_share: number
}

export interface TopVendor {
  vendor_id: number
  company_name: string
  costs: number
  purchase_count: number
  currency: string
  percent_share: number
}

export interface AgingBucket {
  bucket: 'not_due' | 'overdue_30' | 'overdue_60' | 'overdue_90' | 'overdue_90_plus'
  currency: string
  count: number
  total: number
}

export interface DsoResult {
  avg_days: number
  sample_size: number
  period_months: number
}

export interface PunctualityResult {
  on_time: number
  late: number
  total: number
  on_time_pct: number
  period_months: number
}

export interface ConcentrationResult {
  top1_share: number
  top3_share: number
  top5_share: number
  total_clients: number
  pareto_80_count: number
  risk_level: 'low' | 'medium' | 'high'
  currency: string
}

export interface ExpenseCategoryRow {
  category_id: number | null
  code: string | null
  label: string | null
  total: number
  count: number
  percent: number
}

export interface ChurnRiskClient {
  client_id: number
  company_name: string
  last_invoice_date: string
  days_since: number
  total_revenue: number
  currency: string
}

export const crmApi = {
  overview: () =>
    api.get<CrmOverview>('/crm/overview').then(r => r.data),
  monthly: (months = 12, currency?: string) =>
    api.get<CrmMonthlyRow[]>('/crm/monthly', { params: { months, currency } }).then(r => r.data),
  topClients: (months = 12, limit = 10, currency?: string) =>
    api.get<TopClient[]>('/crm/top-clients', { params: { months, limit, currency } }).then(r => r.data),
  topVendors: (months = 12, limit = 10, currency?: string) =>
    api.get<TopVendor[]>('/crm/top-vendors', { params: { months, limit, currency } }).then(r => r.data),
  agingReceivables: () =>
    api.get<AgingBucket[]>('/crm/aging-receivables').then(r => r.data),
  agingPayables: () =>
    api.get<AgingBucket[]>('/crm/aging-payables').then(r => r.data),
  dso: (months = 12) =>
    api.get<DsoResult>('/crm/dso', { params: { months } }).then(r => r.data),
  punctuality: (months = 12) =>
    api.get<PunctualityResult>('/crm/payment-punctuality', { params: { months } }).then(r => r.data),
  concentration: (months = 12, currency?: string) =>
    api.get<ConcentrationResult>('/crm/concentration', { params: { months, currency } }).then(r => r.data),
  expenseBreakdown: (months = 12, currency?: string) =>
    api.get<ExpenseCategoryRow[]>('/crm/expense-breakdown', { params: { months, currency } }).then(r => r.data),
  churnRisk: (days = 60, limit = 20) =>
    api.get<ChurnRiskClient[]>('/crm/churn-risk', { params: { days, limit } }).then(r => r.data),
  recompute: () =>
    api.post<{ ok: boolean; elapsed_ms: number }>('/crm/recompute', {}).then(r => r.data),

  // ─── Tier 1 (action + predictive) ───────────────────────────────────
  actionItems: () =>
    api.get<ActionItemsResult>('/crm/action-items').then(r => r.data),
  dismissActionItem: (itemType: string, mode: 'day' | 'week' | 'forever' | 'historical') =>
    api.post<{ ok: boolean }>('/crm/action-items/dismiss', { item_type: itemType, mode }).then(r => r.data),
  restoreActionItem: (itemType: string) =>
    api.post<{ ok: boolean }>('/crm/action-items/restore', { item_type: itemType }).then(r => r.data),
  cashFlowForecast: (weeks = 4, currency = 'CZK') =>
    api.get<CashFlowResult>('/crm/cash-flow-forecast', { params: { weeks, currency } }).then(r => r.data),
  lateRisk: (limit = 10) =>
    api.get<LateRiskClient[]>('/crm/late-risk', { params: { limit } }).then(r => r.data),
  reminderEffectiveness: (months = 12) =>
    api.get<ReminderEffectiveness>('/crm/reminder-effectiveness', { params: { months } }).then(r => r.data),
  paymentTimeHistogram: (months = 12) =>
    api.get<PaymentTimeHistogram>('/crm/payment-time-histogram', { params: { months } }).then(r => r.data),
}

// ─── Tier 1 types ─────────────────────────────────────────────────────
export interface ActionItem {
  type: string
  severity: 'low' | 'medium' | 'high'
  title: string
  hint: string
  link: string
  count?: number
  days?: number
}
export interface ActionItemsResult {
  items: ActionItem[]
  total: number
}

export interface CashFlowWeek {
  week_start: string
  week_end: string
  in: number
  out: number
  net: number
  running: number
}
export interface CashFlowResult {
  currency: string
  weeks: CashFlowWeek[]
  total_in: number
  total_out: number
  total_net: number
}

export interface LateRiskClient {
  client_id: number
  company_name: string
  total_paid: number
  late_count: number
  late_rate: number
  avg_days_late: number
  score: number
  risk_level: 'low' | 'medium' | 'high'
}

export interface ReminderEffectiveness {
  total_paid: number
  no_reminder: number
  after_first: number
  after_second: number
  after_third_plus: number
  never_paid: number
  avg_reminders_to_paid: number
}

export interface HistogramBucket {
  label: string
  min: number
  max: number | null
  count: number
  percent: number
}
export interface PaymentTimeHistogram {
  buckets: HistogramBucket[]
  total_invoices: number
  median_days: number | null
  p90_days: number | null
}
