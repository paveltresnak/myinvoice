<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { RouterLink, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseInvoicesApi,
  type PurchaseMonthGroup,
  type PurchaseInvoiceListItem,
  type PurchaseInvoiceStatus,
  type PurchaseDocumentKind,
} from '@/api/purchaseInvoices'
import { formatMoney, formatDate, formatMonth } from '@/composables/useFormat'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t, locale } = useI18n()
const router = useRouter()
const route = useRoute()
const toast = useToast()

useHotkey('ctrl+n', (e) => { e.preventDefault(); router.push('/purchase-invoices/new') })

const groups = ref<PurchaseMonthGroup[]>([])
const total = ref(0)
const loading = ref(true)
const error = ref('')

// Filtry
const search = ref('')
const statusFilter = ref<PurchaseInvoiceStatus | ''>('')
const kindFilter = ref<PurchaseDocumentKind | ''>('')
const yearFilter = ref<number | ''>(new Date().getFullYear())
const monthFilter = ref<number | ''>('')
const dateFrom = ref('')
const dateTo = ref('')
const overdueOnly = ref(false)
const unpaidOnly = ref(false)
const currencyFilter = ref('')

// Hromadné akce
const selectedIds = ref<number[]>([])
const bulkBusy = ref(false)

let searchTimeout: ReturnType<typeof setTimeout> | null = null

onMounted(() => {
  // Pre-fill filters from URL query (e.g. /purchase-invoices?overdue=1 from CRM action items)
  const q = route.query
  if (q.overdue === '1' || q.overdue === 'true') {
    overdueOnly.value = true
    yearFilter.value = ''
  }
  if (q.unpaid === '1' || q.unpaid === 'true') {
    unpaidOnly.value = true
    yearFilter.value = ''
  }
  if (typeof q.status === 'string') statusFilter.value = q.status as PurchaseInvoiceStatus
  load()
})
watch([statusFilter, kindFilter, yearFilter, monthFilter, dateFrom, dateTo,
       overdueOnly, unpaidOnly, currencyFilter], () => load())
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(load, 300)
})

async function load() {
  loading.value = true
  error.value = ''
  selectedIds.value = []
  try {
    const res = await purchaseInvoicesApi.listGrouped({
      status:        statusFilter.value || undefined,
      document_kind: kindFilter.value   || undefined,
      year:          yearFilter.value   || undefined,
      month:         monthFilter.value  || undefined,
      date_from:     dateFrom.value     || undefined,
      date_to:       dateTo.value       || undefined,
      currency:      currencyFilter.value || undefined,
      unpaid_only:   unpaidOnly.value   || undefined,
      overdue:       overdueOnly.value  || undefined,
      q:             search.value       || undefined,
      per_page: 200,
    })
    groups.value = res.data
    total.value = res.meta.total
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

function openInvoice(inv: PurchaseInvoiceListItem) {
  router.push(`/purchase-invoices/${inv.id}`)
}

// Year picker (rozsah 5 let)
const yearOptions = computed(() => {
  const cur = new Date().getFullYear()
  return [cur, cur - 1, cur - 2, cur - 3, cur - 4]
})
const monthOptions = computed(() => {
  const locStr = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return Array.from({ length: 12 }, (_, i) =>
    new Date(2000, i, 1).toLocaleDateString(locStr, { month: 'long' })
  )
})

const loadedCount = computed(() =>
  groups.value.reduce((sum, g) => sum + g.invoices.length, 0)
)

const isOverdue = (dueDate: string, status: PurchaseInvoiceStatus): boolean => {
  if (status !== 'received' && status !== 'booked') return false
  return new Date(dueDate) < new Date(new Date().toISOString().slice(0, 10))
}

// Status badge ve stejných tokenech jako Detail (sjednoceno s vystavenou)
const statusBadgeClass = (s: PurchaseInvoiceStatus): string => ({
  draft:     'bg-neutral-100 text-neutral-600 border border-neutral-200',
  received:  'bg-primary-50 text-primary-700 border border-primary-500/40',
  booked:    'bg-warning-50 text-warning-600 border border-warning-500/40',
  paid:      'bg-success-50 text-success-600 border border-success-500/40',
  cancelled: 'bg-danger-50 text-danger-500 border border-danger-500/40',
}[s])

// Row class — soft red background pro overdue, soft gray pro cancelled
const rowClass = (inv: PurchaseInvoiceListItem): string => {
  if (inv.status === 'cancelled') return 'opacity-60'
  if (isOverdue(inv.due_date, inv.status)) return 'bg-danger-50/30'
  return ''
}

// ── Hromadné akce ─────────────────────────────────────────────────────
function toggleSelected(id: number) {
  const idx = selectedIds.value.indexOf(id)
  if (idx >= 0) selectedIds.value.splice(idx, 1)
  else selectedIds.value.push(id)
}

function allRowIds(): number[] {
  return groups.value.flatMap(g => g.invoices.map(i => i.id))
}

const allSelected = computed(() => {
  const ids = allRowIds()
  return ids.length > 0 && ids.every(id => selectedIds.value.includes(id))
})

function toggleAll() {
  if (allSelected.value) {
    selectedIds.value = []
  } else {
    selectedIds.value = allRowIds()
  }
}

// Helpers per row
function statusOf(id: number): PurchaseInvoiceStatus | null {
  for (const g of groups.value) {
    const f = g.invoices.find(i => i.id === id)
    if (f) return f.status
  }
  return null
}

const draftsSelected     = computed(() => selectedIds.value.filter(id => statusOf(id) === 'draft'))
const markPayableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s === 'received' || s === 'booked'
}))
const markBookableSelected = computed(() => selectedIds.value.filter(id => statusOf(id) === 'received'))
const cancellableSelected = computed(() => selectedIds.value.filter(id => {
  const s = statusOf(id); return s && s !== 'cancelled'
}))

async function bulkTransition(target: PurchaseInvoiceStatus, ids: number[]) {
  if (ids.length === 0 || bulkBusy.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.bulk.confirm_cancel', { n: ids.length }))) return
  bulkBusy.value = true
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.transition(id, target); ok++ } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.success', { n: ok }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok, fail }))
  await load()
}

async function bulkDelete() {
  const ids = draftsSelected.value
  if (ids.length === 0 || bulkBusy.value) return
  if (!confirm(t('purchase_invoice.bulk.confirm_delete', { n: ids.length }))) return
  bulkBusy.value = true
  let ok = 0, fail = 0
  for (const id of ids) {
    try { await purchaseInvoicesApi.delete(id); ok++ } catch { fail++ }
  }
  bulkBusy.value = false
  if (fail === 0) toast.success(t('purchase_invoice.bulk.delete_success', { n: ok }))
  else            toast.error(t('purchase_invoice.bulk.partial', { ok, fail }))
  await load()
}
</script>

<template>
  <div>
    <!-- ═══ Topbar: title + bulk actions + new ═══ -->
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_invoice.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('purchase_invoice.subtitle') }}</p>
      </div>

      <div class="flex items-center gap-2 flex-wrap">
        <!-- Bulk actions — viditelné jen pokud něco vybráno -->
        <button v-if="markBookableSelected.length > 0"
          @click="bulkTransition('booked', markBookableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-primary-500 text-primary-700 hover:bg-primary-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_booked', { n: markBookableSelected.length }) }}
        </button>
        <button v-if="markPayableSelected.length > 0"
          @click="bulkTransition('paid', markPayableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-success-500 text-success-600 hover:bg-success-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.mark_paid', { n: markPayableSelected.length }) }}
        </button>
        <button v-if="cancellableSelected.length > 0"
          @click="bulkTransition('cancelled', cancellableSelected)"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.cancel', { n: cancellableSelected.length }) }}
        </button>
        <button v-if="draftsSelected.length > 0"
          @click="bulkDelete"
          :disabled="bulkBusy"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 border border-danger-500/50 text-danger-500 hover:bg-danger-50 disabled:opacity-50 text-sm font-medium rounded-md">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ bulkBusy ? '…' : t('purchase_invoice.bulk.delete', { n: draftsSelected.length }) }}
        </button>

        <RouterLink
          to="/purchase-invoices/new"
          class="cursor-pointer inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
        >
          {{ t('purchase_invoice.new') }}
        </RouterLink>
      </div>
    </div>

    <!-- ═══ Filtry v boxu ═══ -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <input
          v-model="search"
          type="search"
          :placeholder="t('purchase_invoice.filters.search_placeholder')"
          class="flex-1 min-w-48 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <select v-model="statusFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_statuses') }}</option>
          <option value="draft">{{ t('purchase_invoice.status.draft') }}</option>
          <option value="received">{{ t('purchase_invoice.status.received') }}</option>
          <option value="booked">{{ t('purchase_invoice.status.booked') }}</option>
          <option value="paid">{{ t('purchase_invoice.status.paid') }}</option>
          <option value="cancelled">{{ t('purchase_invoice.status.cancelled') }}</option>
        </select>
        <select v-model="kindFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm">
          <option value="">{{ t('purchase_invoice.filters.all_kinds') }}</option>
          <option value="invoice">{{ t('purchase_invoice.document_kind.invoice') }}</option>
          <option value="receipt">{{ t('purchase_invoice.document_kind.receipt') }}</option>
          <option value="credit_note">{{ t('purchase_invoice.document_kind.credit_note') }}</option>
          <option value="advance">{{ t('purchase_invoice.document_kind.advance') }}</option>
        </select>
        <select v-model="yearFilter" :disabled="!!dateFrom || !!dateTo"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm disabled:opacity-50">
          <option value="">{{ t('invoice.all_years') }}</option>
          <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="!!dateFrom || !!dateTo || yearFilter === ''"
          class="h-9 px-3 border border-neutral-300 rounded-md bg-white text-sm disabled:opacity-50">
          <option :value="''">{{ t('invoice.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <input v-model="dateFrom" type="date" :placeholder="t('common.from')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.from')" />
        <input v-model="dateTo" type="date" :placeholder="t('common.to')"
          class="h-9 px-2 border border-neutral-300 rounded-md text-sm" :title="t('common.to')" />
        <button v-if="dateFrom || dateTo" @click="dateFrom = ''; dateTo = ''"
          class="cursor-pointer h-9 px-2 text-xs text-neutral-500 hover:text-neutral-700">{{ t('invoice.clear_date_filter') }}</button>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="overdueOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.overdue') }}
        </label>
        <label class="flex items-center gap-1.5 text-sm text-neutral-700 px-2">
          <input v-model="unpaidOnly" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('purchase_invoice.filters.unpaid_only') }}
        </label>
      </div>
    </div>

    <!-- ═══ Loading / Error / Empty / Data ═══ -->
    <div v-if="loading" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <TableSkeleton :rows="6" :cols="7" />
    </div>

    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm">
      {{ error }}
    </div>

    <div v-else-if="!groups.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <EmptyState
        :title="search || statusFilter || kindFilter ? t('purchase_invoice.empty_filtered') : t('purchase_invoice.empty')"
        :cta="t('purchase_invoice.new')"
        to="/purchase-invoices/new" />
    </div>

    <div v-else>
      <div class="text-xs text-neutral-500 mb-3 flex items-center justify-between">
        <span>{{ t('purchase_invoice.summary_count', { count: total }) }}</span>
        <span v-if="loadedCount < total">{{ t('common.loaded_count', { loaded: loadedCount, total }) }}</span>
      </div>

      <!-- ═══ Skupiny po měsících ═══ -->
      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="sticky top-16 z-[5] flex items-center justify-between bg-neutral-50/95 backdrop-blur border border-neutral-200 rounded-t-lg px-4 py-2.5 mb-0">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.count }}</span>
          </div>
          <div class="flex items-center gap-3 text-xs">
            <span v-for="tc in g.totals_per_currency" :key="tc.currency" class="font-mono">
              <span class="text-neutral-500">{{ tc.currency }}:</span>
              <span class="font-semibold text-neutral-900 ml-1">{{ formatMoney(tc.with_vat, tc.currency) }}</span>
            </span>
          </div>
        </header>

        <!-- Desktop: tabulka -->
        <div class="hidden md:block bg-white border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <div class="overflow-x-auto">
            <table class="w-full text-sm table-sticky-first">
              <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
                <tr>
                  <th class="px-2 py-2 w-10 text-center">
                    <input
                      type="checkbox"
                      :checked="allSelected"
                      @change="toggleAll"
                      :title="t('common.select_all')"
                      class="w-4 h-4 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                    />
                  </th>
                  <th class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.varsymbol') }}</th>
                  <th class="text-left px-4 py-2 font-medium">{{ t('purchase_invoice.fields.vendor') }}</th>
                  <th class="text-left px-4 py-2 font-medium w-32">{{ t('purchase_invoice.fields.vendor_invoice_number') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.document_kind') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.tax_date') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.fields.due_date') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('purchase_invoice.totals.to_pay') }}</th>
                  <th class="text-center px-4 py-2 font-medium">{{ t('purchase_invoice.status.draft') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr
                  v-for="inv in g.invoices"
                  :key="inv.id"
                  @click="openInvoice(inv)"
                  class="cursor-pointer hover:bg-neutral-50 transition"
                  :class="rowClass(inv)"
                >
                  <td class="px-2 py-2.5 text-center" @click.stop>
                    <input
                      type="checkbox"
                      :checked="selectedIds.includes(inv.id)"
                      @change="toggleSelected(inv.id)"
                      class="w-5 h-5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
                    />
                  </td>
                  <td class="px-4 py-2.5 font-mono text-xs">
                    <span v-if="inv.varsymbol">{{ inv.varsymbol }}</span>
                    <span v-else class="text-neutral-400">#{{ inv.id }}</span>
                  </td>
                  <td class="px-4 py-2.5">
                    <div class="font-medium text-neutral-900">{{ inv.vendor_company_name }}</div>
                    <div v-if="inv.vendor_ic" class="text-xs text-neutral-500 font-mono">{{ t('common.ic') }} {{ inv.vendor_ic }}</div>
                  </td>
                  <td class="px-4 py-2.5 font-mono text-xs text-neutral-600">{{ inv.vendor_invoice_number }}</td>
                  <td class="px-4 py-2.5 text-center text-xs text-neutral-600">{{ t(`purchase_invoice.document_kind.${inv.document_kind}`) }}</td>
                  <td class="px-4 py-2.5 text-center text-xs text-neutral-600">
                    {{ formatDate(inv.tax_date || inv.issue_date) }}
                  </td>
                  <td class="px-4 py-2.5 text-center text-xs">
                    <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : 'text-neutral-600'">
                      {{ formatDate(inv.due_date) }}
                    </span>
                  </td>
                  <td class="px-4 py-2.5 text-right font-mono">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </td>
                  <td class="px-4 py-2.5 text-center">
                    <span class="text-xs px-2 py-0.5 rounded" :class="statusBadgeClass(inv.status)">
                      {{ t(`purchase_invoice.status.${inv.status}`) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Mobile: karty -->
        <div class="md:hidden bg-white border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div
            v-for="inv in g.invoices"
            :key="`m-${inv.id}`"
            @click="openInvoice(inv)"
            class="cursor-pointer hover:bg-neutral-50 transition px-3 py-3"
            :class="rowClass(inv)"
          >
            <div class="flex items-start gap-3">
              <input
                type="checkbox"
                :checked="selectedIds.includes(inv.id)"
                @change="toggleSelected(inv.id)"
                @click.stop
                class="w-5 h-5 mt-0.5 cursor-pointer rounded border-neutral-300 text-primary-600 focus:ring-2 focus:ring-primary-500/30"
              />
              <div class="flex-1 min-w-0">
                <div class="flex items-baseline justify-between gap-2">
                  <div class="font-medium text-neutral-900 truncate">{{ inv.vendor_company_name }}</div>
                  <div class="font-mono text-sm whitespace-nowrap">
                    {{ formatMoney(inv.amount_to_pay ?? inv.total_with_vat, inv.currency) }}
                  </div>
                </div>
                <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <div class="font-mono truncate">
                    <span>{{ inv.varsymbol || '#' + inv.id }}</span>
                    <span class="text-neutral-400"> · </span>
                    <span>{{ inv.vendor_invoice_number }}</span>
                  </div>
                  <span class="text-xs px-1.5 py-0.5 rounded whitespace-nowrap" :class="statusBadgeClass(inv.status)">
                    {{ t(`purchase_invoice.status.${inv.status}`) }}
                  </span>
                </div>
                <div class="flex items-center justify-between gap-2 mt-1 text-xs text-neutral-500">
                  <span>{{ formatDate(inv.tax_date || inv.issue_date) }}</span>
                  <span :class="isOverdue(inv.due_date, inv.status) ? 'text-danger-500 font-medium' : ''">
                    {{ t('purchase_invoice.fields.due_date') }}: {{ formatDate(inv.due_date) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
