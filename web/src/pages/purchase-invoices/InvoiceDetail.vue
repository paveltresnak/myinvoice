<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { purchaseInvoicesApi, type PurchaseInvoice, type PurchaseInvoiceStatus } from '@/api/purchaseInvoices'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/api/errors'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()

const invoice = ref<PurchaseInvoice | null>(null)
const loading = ref(true)
const error = ref('')
const acting = ref(false)
const pdfPreviewOpen = ref(false) // default collapsed — user explicitně otevře (Edge blokuje attachment inline)

// Activity log — paralel s /invoices/{id}/activity
const activity = ref<Array<{
  id: number
  user_id: number | null
  user_email: string | null
  user_name: string | null
  action: string
  payload: Record<string, unknown> | null
  ip: string | null
  created_at: string
}>>([])

const id = computed(() => Number(route.params.id))

onMounted(load)

async function load() {
  loading.value = true
  try {
    invoice.value = await purchaseInvoicesApi.get(id.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
  // Activity log paralel — ne-blokuje main load
  purchaseInvoicesApi.activity(id.value)
    .then(a => { activity.value = a })
    .catch(() => {})
}

async function deletePdf() {
  if (!invoice.value || !invoice.value.pdf_path) return
  if (!confirm(t('purchase_invoice.pdf.delete_confirm'))) return
  try {
    await purchaseInvoicesApi.deletePdf(invoice.value.id)
    toast.success(t('purchase_invoice.pdf.delete_success'))
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

async function transition(target: PurchaseInvoiceStatus) {
  if (!invoice.value) return
  if (target === 'cancelled' && !confirm(t('purchase_invoice.confirm.cancel'))) return
  acting.value = true
  try {
    invoice.value = await purchaseInvoicesApi.transition(invoice.value.id, target)
    toast.success(t(`purchase_invoice.transition.success_${target}`))
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function remove() {
  if (!invoice.value) return
  if (!confirm(t('purchase_invoice.confirm.delete_draft'))) return
  try {
    await purchaseInvoicesApi.delete(invoice.value.id)
    toast.success(t('common.deleted'))
    router.push('/purchase-invoices')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

// Konzistentní s `statusBadgeClass` z useFormat (vystavené faktury) — používáme
// stejné `bg-X-50 text-X-600 border border-X-500/40` tokeny.
const statusBadgeClass = (s: PurchaseInvoiceStatus): string => ({
  draft:     'bg-neutral-100 text-neutral-600 border border-neutral-200',
  received:  'bg-primary-50 text-primary-700 border border-primary-500/40',
  booked:    'bg-warning-50 text-warning-600 border border-warning-500/40',
  paid:      'bg-success-50 text-success-600 border border-success-500/40',
  cancelled: 'bg-danger-50 text-danger-500 border border-danger-500/40',
}[s])

// Allowed transitions per status — sync s backend (TransitionPurchaseInvoiceStatusAction)
const allowedTransitions = computed<PurchaseInvoiceStatus[]>(() => {
  if (!invoice.value) return []
  switch (invoice.value.status) {
    case 'draft':     return ['received', 'cancelled']
    case 'received':  return ['booked', 'paid', 'cancelled']
    case 'booked':    return ['paid', 'cancelled']
    case 'paid':      return ['received', 'cancelled']   // unmark paid / storno už uhrazené
    case 'cancelled': return ['received']                  // un-cancel
    default:          return []
  }
})

const canEdit = computed(() => invoice.value?.status === 'draft')
// Force-edit pro received/booked — admin only (paid/cancelled jsou immutable)
const canForceEdit = computed(() =>
  auth.user?.role === 'admin' &&
  invoice.value &&
  ['received', 'booked'].includes(invoice.value.status)
)
const canDelete = computed(() => invoice.value?.status === 'draft')

/**
 * Action log helpers — strip "purchase_invoice." prefix, color-code badge per action group.
 */
function actionShortLabel(action: string): string {
  // purchase_invoice.created → created, purchase_invoice.pdf_uploaded → pdf_uploaded
  return action.replace(/^purchase_invoice\./, '')
}
function actionBadgeClass(action: string): string {
  const short = actionShortLabel(action)
  if (short === 'created')              return 'bg-success-50 text-success-600 border border-success-500/40'
  if (short.startsWith('transitioned')) return 'bg-primary-50 text-primary-700 border border-primary-500/40'
  if (short.includes('pdf'))            return 'bg-neutral-100 text-neutral-600 border border-neutral-200'
  if (short.includes('deleted') || short.includes('cancelled')) return 'bg-danger-50 text-danger-500 border border-danger-500/40'
  if (short.includes('updated'))        return 'bg-warning-50 text-warning-600 border border-warning-500/40'
  return 'bg-neutral-100 text-neutral-600 border border-neutral-200'
}

/**
 * Context-aware label pro transition tlačítko.
 * Pro reverse transitions (paid→received, cancelled→received) labelovat výmluvněji
 * než generic "Označit jako přijaté".
 */
function transitionLabel(target: PurchaseInvoiceStatus): string {
  const from = invoice.value?.status
  if (target === 'received' && from === 'paid')      return t('purchase_invoice.actions.unmark_paid')
  if (target === 'received' && from === 'cancelled') return t('purchase_invoice.actions.uncancel')
  return t(`purchase_invoice.actions.mark_${target}`)
}
</script>

<template>
  <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
  <div v-else-if="error" class="max-w-5xl">
    <div class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ error }}</div>
  </div>

  <div v-else-if="invoice" class="max-w-5xl space-y-4">
    <RouterLink to="/purchase-invoices" class="text-sm text-neutral-600 hover:text-neutral-900">
      {{ t('purchase_invoice.back_to_list') }}
    </RouterLink>

    <!-- ═══ Hlavička: varsymbol + status + akce ═══ -->
    <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-3 md:gap-4">
      <h1 class="text-2xl font-semibold flex items-center gap-3 flex-wrap min-w-0">
        <span v-if="invoice.varsymbol" class="font-mono">{{ invoice.varsymbol }}</span>
        <span v-else class="text-neutral-400 font-mono">#{{ invoice.id }}</span>
        <span class="text-xs px-2 py-0.5 rounded font-normal" :class="statusBadgeClass(invoice.status)">
          {{ t(`purchase_invoice.status.${invoice.status}`) }}
        </span>
        <span class="text-xs px-2 py-0.5 rounded font-normal bg-neutral-100 text-neutral-600">
          {{ t(`purchase_invoice.document_kind.${invoice.document_kind}`) }}
        </span>
      </h1>
      <div class="flex flex-wrap gap-2 md:justify-end">
        <RouterLink v-if="canEdit" :to="`/purchase-invoices/${invoice.id}/edit`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('common.edit') }}
        </RouterLink>
        <!-- Force-edit pro received/booked (admin only) -->
        <RouterLink v-else-if="canForceEdit" :to="`/purchase-invoices/${invoice.id}/edit?force=1`"
          class="cursor-pointer px-3 h-9 text-sm border border-warning-500/40 rounded-md text-warning-600 hover:bg-warning-50 inline-flex items-center gap-1.5"
          :title="t('purchase_invoice.force_edit_hint')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          {{ t('purchase_invoice.force_edit') }}
        </RouterLink>
        <a v-if="invoice.pdf_path" :href="purchaseInvoicesApi.pdfUrl(invoice.id)" target="_blank"
          class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 rounded-md text-primary-700 hover:bg-primary-50 inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z"/></svg>
          {{ t('purchase_invoice.pdf.download_original') }}
        </a>
        <!-- Export dropdown — Naše PDF / ISDOC / Pohoda (native details/summary) -->
        <details class="relative inline-block">
          <summary class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5 list-none">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            {{ t('purchase_invoice.export.menu') }}
            <svg class="w-3 h-3 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
          </summary>
          <div class="absolute right-0 top-full mt-1 z-20 bg-white border border-neutral-200 rounded-md shadow-lg min-w-[220px]">
            <a :href="purchaseInvoicesApi.ourPdfUrl(invoice.id)" target="_blank"
              class="cursor-pointer block px-4 py-2 text-sm hover:bg-neutral-50 text-neutral-700">
              <svg class="inline w-4 h-4 mr-1" viewBox="0 0 32 36"><path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/><text x="16" y="26" fill="#fff" font-size="8" font-weight="700" text-anchor="middle">PDF</text></svg>
              {{ t('purchase_invoice.export.our_pdf') }}
            </a>
            <a :href="purchaseInvoicesApi.isdocUrl(invoice.id)"
              class="cursor-pointer block px-4 py-2 text-sm hover:bg-neutral-50 text-neutral-700 border-t border-neutral-100">
              <svg class="inline w-4 h-4 mr-1 text-primary-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/></svg>
              {{ t('purchase_invoice.export.isdoc') }}
            </a>
            <a :href="purchaseInvoicesApi.pohodaUrl(invoice.id)"
              class="cursor-pointer block px-4 py-2 text-sm hover:bg-neutral-50 text-neutral-700 border-t border-neutral-100">
              <svg class="inline w-4 h-4 mr-1 text-warning-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414"/></svg>
              {{ t('purchase_invoice.export.pohoda') }}
            </a>
          </div>
        </details>
        <template v-for="target in allowedTransitions" :key="target">
          <!-- Reverse: paid→received (unmark paid) NEBO cancelled→received (un-cancel) — neutral styl -->
          <button v-if="target === 'received' && (invoice.status === 'paid' || invoice.status === 'cancelled')"
            type="button" @click="transition('received')" :disabled="acting"
            class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 0 1 8 8v2M3 10l6 6m-6-6l6-6"/></svg>
            {{ transitionLabel('received') }}
          </button>
          <!-- Cancel: danger styl -->
          <button v-else-if="target === 'cancelled'" type="button" @click="transition('cancelled')" :disabled="acting"
            class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            {{ t('purchase_invoice.actions.mark_cancelled') }}
          </button>
          <!-- Forward transitions — primary -->
          <button v-else type="button" @click="transition(target)" :disabled="acting"
            class="cursor-pointer px-3 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md inline-flex items-center gap-1.5">
            <svg v-if="target === 'paid'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 14l2 2 4-4m6 2a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            <svg v-else-if="target === 'received'" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2"/></svg>
            {{ transitionLabel(target) }}
          </button>
        </template>
        <button v-if="canDelete" type="button" @click="remove"
          class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
          {{ t('common.delete') }}
        </button>
      </div>
    </div>

    <!-- ═══ Vendor + číslo dokladu (řádek pod headerem, paralel s vystavenou InvoiceDetail) ═══ -->
    <div class="flex items-start justify-between gap-4">
      <div class="flex-1 min-w-0 space-y-1">
        <div class="text-lg font-semibold text-neutral-900">{{ invoice.vendor_company_name }}</div>
        <div class="text-sm text-neutral-600 font-mono">
          {{ t('purchase_invoice.fields.vendor_invoice_number') }}: {{ invoice.vendor_invoice_number }}
        </div>
      </div>
      <div v-if="invoice.vendor_ic || invoice.vendor_dic" class="text-xs font-mono text-neutral-500 text-right whitespace-nowrap">
        <span v-if="invoice.vendor_ic">{{ t('common.ic') }} {{ invoice.vendor_ic }}</span>
        <span v-if="invoice.vendor_ic && invoice.vendor_dic">, </span>
        <span v-if="invoice.vendor_dic">{{ t('common.dic') }} {{ invoice.vendor_dic }}</span>
      </div>
    </div>

    <!-- ═══ Datumy & metadata (3 sloupce ala vystavená InvoiceDetail) ═══ -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <!-- Datumy -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.fields.issue_date') }} / {{ t('purchase_invoice.fields.tax_date') }} / {{ t('purchase_invoice.fields.due_date') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.issue_date') }}</dt><dd class="font-mono">{{ formatDate(invoice.issue_date) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.tax_date') }}</dt><dd class="font-mono">{{ invoice.tax_date ? formatDate(invoice.tax_date) : '—' }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.due_date') }}</dt><dd class="font-mono">{{ formatDate(invoice.due_date) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.received_at') }}</dt><dd class="font-mono">{{ formatDate(invoice.received_at) }}</dd></div>
          <div v-if="invoice.paid_at" class="flex justify-between pt-2 border-t border-neutral-100"><dt class="text-neutral-500">{{ t('purchase_invoice.status.paid') }}</dt><dd class="font-mono text-success-600">{{ formatDate(invoice.paid_at) }}</dd></div>
        </dl>
      </div>

      <!-- Měna & kurz -->
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.fields.currency') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.currency') }}</dt><dd class="font-mono">{{ invoice.currency }}</dd></div>
          <div v-if="invoice.exchange_rate" class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.exchange_rate') }}</dt><dd class="font-mono">{{ invoice.exchange_rate }}</dd></div>
          <div v-if="invoice.exchange_rate_date" class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.exchange_rate') }} ({{ t('purchase_invoice.fields.exchange_rate_source') }})</dt><dd class="font-mono text-xs">{{ formatDate(invoice.exchange_rate_date) }} / {{ invoice.exchange_rate_source }}</dd></div>
          <div v-if="invoice.reverse_charge" class="flex justify-between pt-2 border-t border-neutral-100"><dt class="text-neutral-500">{{ t('purchase_invoice.fields.reverse_charge') }}</dt><dd class="text-warning-600 font-medium">✓</dd></div>
        </dl>
      </div>

      <!-- Platba v jiné měně -->
      <div v-if="invoice.payment_currency_id" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.payment_currency.toggle') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.payment_currency.currency') }}</dt><dd class="font-mono">{{ invoice.payment_currency || '—' }}</dd></div>
          <div v-if="invoice.payment_exchange_rate" class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.payment_currency.rate') }}</dt><dd class="font-mono">{{ invoice.payment_exchange_rate }}</dd></div>
          <div v-if="invoice.paid_amount_payment_ccy" class="flex justify-between"><dt class="text-neutral-500">{{ t('purchase_invoice.payment_currency.paid_payment_ccy') }}</dt><dd class="font-mono">{{ formatMoney(invoice.paid_amount_payment_ccy, invoice.payment_currency || '') }}</dd></div>
          <div v-if="invoice.exchange_diff_base !== null" class="flex justify-between pt-2 border-t border-neutral-100"><dt class="text-neutral-500">{{ t('purchase_invoice.payment_currency.exchange_diff') }}</dt><dd class="font-mono" :class="(invoice.exchange_diff_base ?? 0) < 0 ? 'text-danger-500' : 'text-success-600'">{{ formatMoney(invoice.exchange_diff_base, 'CZK') }}</dd></div>
        </dl>
      </div>
    </div>

    <!-- ═══ Položky ═══ -->
    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <h3 class="text-sm font-medium text-neutral-700 px-5 py-3 border-b border-neutral-100">{{ t('purchase_invoice.items.title') }}</h3>
      <table class="w-full text-sm">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left py-2 px-5 font-medium">{{ t('purchase_invoice.items.description') }}</th>
            <th class="text-right py-2 px-2 font-medium">{{ t('purchase_invoice.items.quantity') }}</th>
            <th class="text-left py-2 px-2 font-medium">{{ t('purchase_invoice.items.unit') }}</th>
            <th class="text-right py-2 px-2 font-medium">{{ t('purchase_invoice.items.unit_price') }}</th>
            <th class="text-right py-2 px-2 font-medium">{{ t('purchase_invoice.items.vat_rate') }}</th>
            <th class="text-right py-2 px-5 font-medium">{{ t('purchase_invoice.items.total_with_vat') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="it in invoice.items" :key="it.id">
            <td class="py-2 px-5">{{ it.description }}</td>
            <td class="py-2 px-2 text-right font-mono">{{ it.quantity }}</td>
            <td class="py-2 px-2">{{ it.unit }}</td>
            <td class="py-2 px-2 text-right font-mono">{{ formatMoney(it.unit_price_without_vat, invoice.currency) }}</td>
            <td class="py-2 px-2 text-right">{{ it.vat_rate_snapshot }}%</td>
            <td class="py-2 px-5 text-right font-mono">{{ formatMoney(it.total_with_vat, invoice.currency) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ═══ Totals + VAT breakdown ═══ -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.vat_breakdown.title') }}</h3>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
              <th class="text-left py-1.5 font-medium">{{ t('purchase_invoice.vat_breakdown.rate') }}</th>
              <th class="text-right py-1.5 font-medium">{{ t('purchase_invoice.vat_breakdown.base') }}</th>
              <th class="text-right py-1.5 font-medium">{{ t('purchase_invoice.vat_breakdown.vat') }}</th>
              <th class="text-right py-1.5 font-medium">{{ t('purchase_invoice.vat_breakdown.with_vat') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in invoice.vat_breakdown" :key="b.vat_rate" class="border-b border-neutral-50">
              <td class="py-1.5">{{ b.vat_rate }}%</td>
              <td class="py-1.5 text-right font-mono">{{ formatMoney(b.without_vat, invoice.currency) }}</td>
              <td class="py-1.5 text-right font-mono">{{ formatMoney(b.vat, invoice.currency) }}</td>
              <td class="py-1.5 text-right font-mono">{{ formatMoney(b.with_vat, invoice.currency) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.totals.with_vat') }}</h3>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between"><dt class="text-neutral-600">{{ t('purchase_invoice.totals.without_vat') }}</dt><dd class="font-mono">{{ formatMoney(invoice.total_without_vat, invoice.currency) }}</dd></div>
          <div class="flex justify-between"><dt class="text-neutral-600">{{ t('purchase_invoice.totals.vat') }}</dt><dd class="font-mono">{{ formatMoney(invoice.total_vat, invoice.currency) }}</dd></div>
          <div class="flex justify-between font-semibold border-t border-neutral-100 pt-2"><dt>{{ t('purchase_invoice.totals.with_vat') }}</dt><dd class="font-mono">{{ formatMoney(invoice.total_with_vat, invoice.currency) }}</dd></div>
          <div v-if="invoice.advance_paid_amount > 0" class="flex justify-between text-neutral-500"><dt>{{ t('purchase_invoice.totals.advance_paid') }}</dt><dd class="font-mono">−{{ formatMoney(invoice.advance_paid_amount, invoice.currency) }}</dd></div>
          <div class="flex justify-between font-semibold text-lg border-t border-neutral-200 pt-2"><dt>{{ t('purchase_invoice.totals.to_pay') }}</dt><dd class="font-mono">{{ formatMoney(invoice.amount_to_pay, invoice.currency) }}</dd></div>
        </dl>
      </div>
    </div>

    <!-- ═══ Originální PDF od dodavatele ═══ -->
    <div v-if="invoice.pdf_path" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="flex items-center justify-between px-5 py-3 border-b border-neutral-100">
        <div class="flex items-center gap-3">
          <svg class="w-7 h-8 shrink-0" viewBox="0 0 32 36" xmlns="http://www.w3.org/2000/svg">
            <path fill="#dc2626" d="M4 2h16l8 8v22a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"/>
            <path fill="#ffffff" opacity="0.35" d="M20 2v8h8z"/>
            <text x="16" y="26" fill="#ffffff" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="700" text-anchor="middle" letter-spacing="0.3">PDF</text>
          </svg>
          <div>
            <div class="font-medium text-sm">{{ invoice.pdf_original_name || 'invoice.pdf' }}</div>
            <div class="text-xs text-neutral-500">{{ Math.round((Number(invoice.pdf_size_bytes) || 0) / 1024) }} KiB · {{ invoice.pdf_uploaded_at ? formatDate(invoice.pdf_uploaded_at.slice(0,10)) : '' }}</div>
          </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
          <button type="button" @click="pdfPreviewOpen = !pdfPreviewOpen"
            class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/>
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
            </svg>
            {{ pdfPreviewOpen ? t('purchase_invoice.pdf.hide') : t('purchase_invoice.pdf.show') }}
          </button>
          <a :href="purchaseInvoicesApi.pdfUrl(invoice.id)" target="_blank"
             class="cursor-pointer px-3 h-9 text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md inline-flex items-center gap-1.5">
            <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            {{ t('purchase_invoice.pdf.download') }}
          </a>
          <button type="button" @click="deletePdf"
            class="cursor-pointer px-3 h-9 text-sm border border-danger-500/50 text-danger-500 hover:bg-danger-50 rounded-md inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
            {{ t('purchase_invoice.pdf.delete') }}
          </button>
        </div>
      </div>
      <!-- Inline PDF preview přes browser PDF viewer. Musí být ?inline=1 (jinak
           Content-Disposition: attachment a Edge/IE blokují embed). -->
      <div v-if="pdfPreviewOpen" class="bg-neutral-100">
        <iframe
          :src="purchaseInvoicesApi.pdfUrl(invoice.id, true) + '#view=FitH'"
          class="w-full h-[80vh] border-0"
          :title="invoice.pdf_original_name || 'PDF'"
        ></iframe>
      </div>
    </div>
    <div v-else class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.pdf.title') }}</h3>
      <p class="text-sm text-neutral-500">{{ t('purchase_invoice.pdf.no_pdf') }}</p>
    </div>

    <!-- ═══ Poznámky (jen pokud existují) ═══ -->
    <div v-if="invoice.note_above_items || invoice.note_below_items" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-sm font-medium text-neutral-700 mb-3">{{ t('purchase_invoice.fields.note_above_items') }} / {{ t('purchase_invoice.fields.note_below_items') }}</h3>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
        <div v-if="invoice.note_above_items">
          <div class="text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.fields.note_above_items') }}</div>
          <p class="whitespace-pre-line">{{ invoice.note_above_items }}</p>
        </div>
        <div v-if="invoice.note_below_items">
          <div class="text-xs text-neutral-500 mb-1">{{ t('purchase_invoice.fields.note_below_items') }}</div>
          <p class="whitespace-pre-line">{{ invoice.note_below_items }}</p>
        </div>
      </div>
    </div>

    <!-- ═══ More actions (vendor detail link, paralel s vystavenou InvoiceDetail) ═══ -->
    <div v-if="invoice && invoice.vendor_id" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
      <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-3">{{ t('invoice.more_actions') }}</h3>
      <div class="flex flex-wrap gap-2">
        <RouterLink :to="`/clients/${invoice.vendor_id}`"
          class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 text-neutral-700 hover:bg-neutral-50 rounded-md inline-flex items-center gap-1.5">
          <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM12 14a7 7 0 0 0-7 7h14a7 7 0 0 0-7-7z"/></svg>
          {{ t('purchase_invoice.vendor_detail') }}
        </RouterLink>
      </div>
    </div>

    <!-- ═══ Activity log (paralel s /invoices) ═══ -->
    <div v-if="activity.length > 0" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <header class="px-5 py-3 border-b border-neutral-200">
        <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('invoice.activity') }}</h3>
      </header>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="a in activity" :key="a.id" class="hover:bg-neutral-50 align-top">
              <td class="px-5 py-2 whitespace-nowrap">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="actionBadgeClass(a.action)">{{ actionShortLabel(a.action) }}</span>
              </td>
              <td class="px-3 py-2 text-xs text-neutral-500 whitespace-nowrap">{{ a.user_name || a.user_email || '—' }}</td>
              <td class="px-3 py-2 font-mono text-xs text-neutral-400 whitespace-nowrap">{{ a.created_at.replace('T', ' ').slice(0, 19) }}</td>
              <td class="px-3 py-2 text-xs text-neutral-600 break-all whitespace-pre-wrap leading-snug">
                <template v-if="a.payload">
                  {{ Object.entries(a.payload).map(([k, v]) => k + '=' + (typeof v === 'object' ? JSON.stringify(v) : String(v))).join(' · ') }}
                </template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
