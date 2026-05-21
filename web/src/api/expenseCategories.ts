import { api } from './client'

export interface ExpenseCategory {
  id: number
  code: string
  label: string
  fixed_or_var: 'fixed' | 'variable'
  display_order: number
  archived: boolean
  purchases_count?: number
  created_at: string
}

export interface ExpenseCategoryCreatePayload {
  code: string
  label: string
  fixed_or_var?: 'fixed' | 'variable'
  display_order?: number
}

export const expenseCategoriesApi = {
  list: (includeArchived = false) =>
    api.get<ExpenseCategory[]>('/expense-categories', {
      params: includeArchived ? { include_archived: 1 } : undefined,
    }).then(r => r.data),
  create: (data: ExpenseCategoryCreatePayload) =>
    api.post<ExpenseCategory>('/expense-categories', data).then(r => r.data),
  update: (id: number, data: Partial<ExpenseCategoryCreatePayload> & { archived?: boolean }) =>
    api.put<ExpenseCategory>(`/expense-categories/${id}`, data).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: boolean; archived: boolean; usage_count?: number }>(
      `/expense-categories/${id}`,
    ).then(r => r.data),
}
