import { api } from './client'

export type VatDirection = 'sale' | 'purchase' | 'both'

export interface VatClassification {
  id: number
  supplier_id: number | null  // null = globální seed (read-only)
  code: string
  label: string
  direction: VatDirection
  dphdp3_line: string | null
  kh_section: string | null
  vat_rate: number | null
  is_reverse_charge: boolean
  display_order: number
  archived: boolean
  created_at: string
}

export const vatClassificationsApi = {
  /** List kódů — `direction=sale` vrátí jen vystavené, `purchase` jen přijaté, `both` vše. */
  list: (direction?: VatDirection, includeArchived = false) =>
    api.get<VatClassification[]>('/vat-classifications', {
      params: {
        ...(direction ? { direction } : {}),
        ...(includeArchived ? { include_archived: 1 } : {}),
      },
    }).then(r => r.data),
  create: (data: Omit<VatClassification, 'id' | 'supplier_id' | 'archived' | 'created_at'>) =>
    api.post<VatClassification>('/vat-classifications', data).then(r => r.data),
  update: (id: number, data: Partial<VatClassification>) =>
    api.put<VatClassification>(`/vat-classifications/${id}`, data).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ ok: boolean; archived: boolean }>(`/vat-classifications/${id}`).then(r => r.data),
}
