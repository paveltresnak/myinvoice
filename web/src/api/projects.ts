import { api } from './client'
import type { ListResponse } from './clients'

export interface BillingEmail {
  position: 1 | 2 | 3
  email: string
  label?: string | null
}

export interface Project {
  id: number
  client_id: number
  name: string
  payment_due_days: number
  project_number?: string | null
  contract_number?: string | null
  budget_total?: number | null
  budget_yearly?: number | null
  budget_monthly?: number | null
  hourly_rate: number
  currency_id: number
  currency: string
  status: 'active' | 'paused' | 'closed'
  requires_work_report_approval: boolean
  note?: string | null
  archived_at?: string | null
  invoices_count?: number
  client_company_name?: string
  client_main_email?: string
  billing_emails: BillingEmail[]
  created_at?: string
  updated_at?: string
  // Cache stats z project_revenue_cache (per p.currency)
  revenue?: number
  last_invoice_date?: string | null
  invoice_count?: number
  // Per-detail stats — populated by GetProjectAction
  revenue_by_month?: Array<{ month: string; currency: string; total: number }>
  revenue_by_year?:  Array<{ year: number; currency: string; total: number; count: number }>
  unpaid_summary?:   Array<{ currency: string; unpaid_total: number; unpaid_count: number; overdue_total: number; overdue_count: number }>
}

export interface ProjectPayload {
  client_id: number
  name: string
  payment_due_days: number
  project_number?: string | null
  contract_number?: string | null
  budget_total?: number | null
  budget_yearly?: number | null
  budget_monthly?: number | null
  hourly_rate: number
  currency_id: number
  status: 'active' | 'paused' | 'closed'
  requires_work_report_approval?: boolean
  note?: string | null
  billing_emails: BillingEmail[]
}

export const projectsApi = {
  listForClient: (clientId: number) =>
    api.get<{ data: Project[] }>(`/clients/${clientId}/projects`).then((r) => r.data.data),

  list: (params?: { status?: string; client_id?: number; page?: number; per_page?: number; sort?: 'name' | 'revenue' | 'last_activity' | 'client' }) =>
    api
      .get<ListResponse<Project>>('/projects', {
        params: {
          page: params?.page,
          per_page: params?.per_page,
          sort: params?.sort,
          ...(params?.status ? { 'filter[status]': params.status } : {}),
          ...(params?.client_id ? { 'filter[client_id]': params.client_id } : {}),
        },
      })
      .then((r) => r.data),

  get:    (id: number) => api.get<Project>(`/projects/${id}`).then((r) => r.data),
  create: (p: ProjectPayload) => api.post<Project>('/projects', p).then((r) => r.data),
  update: (id: number, p: Omit<ProjectPayload, 'client_id'>) =>
    api.put<Project>(`/projects/${id}`, p).then((r) => r.data),
  archive: (id: number) => api.post(`/projects/${id}/archive`),
  delete:  (id: number) => api.delete(`/projects/${id}`).then((r) => r.data),

  stats: () => api.get<ProjectStats>('/projects/stats').then(r => r.data),
}

export interface ProjectStatsTopItem {
  id: number
  name: string
  client_company_name: string
  revenue: number
  invoice_count: number
}

export interface ProjectStats {
  this_year: number
  prev_year: number
  primary_currency: string
  top_this_year: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  top_prev_year: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  top_12m: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  totals_per_year: Array<{ year: number; currency: string; total: number; invoice_count: number }>
  status_breakdown: Array<{ status: 'active' | 'paused' | 'closed'; count: number }>
  is_vat_payer: boolean
}
