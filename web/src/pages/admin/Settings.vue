<script setup lang="ts">
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi, type Supplier, type CurrencyAccount } from '@/api/settings'
import { useHotkey } from '@/composables/useHotkey'
import { useToast } from '@/composables/useToast'
import { renderVarsymbolTemplate, hasCounterPlaceholder } from '@/utils/varsymbol'

const { t } = useI18n()
const toast = useToast()

const supplier = ref<Supplier | null>(null)
const currencies = ref<CurrencyAccount[]>([])
const loading = ref(true)

const editingCurrency = ref<number | null>(null)
const editingCurrencyLabel = ref<string>('')
const currencyDraft = reactive<Partial<CurrencyAccount>>({})

useHotkey('escape', () => { if (editingCurrency.value !== null) editingCurrency.value = null })

// Live preview pro číslování faktur — okamžitá zpětná vazba pod každým polem.
// Chybějící counter → červený error; jinak „Náhled: JD2026-01".
function validateAndPreview(template: string | null) {
  const tmpl = (template ?? '').trim()
  if (tmpl === '') return { error: '', preview: '' }
  if (!hasCounterPlaceholder(tmpl)) return { error: t('settings.numbering_must_have_counter'), preview: '' }
  return { error: '', preview: renderVarsymbolTemplate(tmpl, new Date(), 1) }
}
const invoicePreview        = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).preview)
const invoiceFormatError    = computed(() => validateAndPreview(supplier.value?.invoice_number_format ?? null).error)
const proformaPreview       = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).preview)
const proformaFormatError   = computed(() => validateAndPreview(supplier.value?.proforma_number_format ?? null).error)
const creditNotePreview     = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).preview)
const creditNoteFormatError = computed(() => validateAndPreview(supplier.value?.credit_note_number_format ?? null).error)

async function load() {
  loading.value = true
  try {
    [supplier.value, currencies.value] = await Promise.all([
      settingsApi.getSupplier(),
      settingsApi.listCurrencies(),
    ])
  } finally { loading.value = false }
}

onMounted(load)

async function saveSupplier() {
  if (!supplier.value) return
  // Klient-side guard pro varsymbol formáty — stejná pravidla jako backend, ale uživatel
  // dostane okamžitou zpětnou vazbu (hláška u pole) místo toastu, který zmizí.
  const errs = [invoiceFormatError.value, proformaFormatError.value, creditNoteFormatError.value].filter(Boolean)
  if (errs.length > 0) {
    toast.error(errs[0])
    return
  }
  try {
    supplier.value = await settingsApi.updateSupplier({
      company_name: supplier.value.company_name,
      display_name: supplier.value.display_name,
      street: supplier.value.street,
      city: supplier.value.city,
      zip: supplier.value.zip,
      ic: supplier.value.ic,
      dic: supplier.value.dic,
      is_vat_payer: supplier.value.is_vat_payer,
      email: supplier.value.email,
      phone: supplier.value.phone,
      web: supplier.value.web,
      tagline: supplier.value.tagline,
      commercial_register: supplier.value.commercial_register,
      default_payment_due_days: supplier.value.default_payment_due_days,
      default_hourly_rate: supplier.value.default_hourly_rate,
      auto_send_reminders: supplier.value.auto_send_reminders,
      pohoda_account_code: supplier.value.pohoda_account_code,
      pohoda_centre_code: supplier.value.pohoda_centre_code,
      pohoda_activity_code: supplier.value.pohoda_activity_code,
      pohoda_contract_code: supplier.value.pohoda_contract_code,
      invoice_number_format: supplier.value.invoice_number_format,
      proforma_number_format: supplier.value.proforma_number_format,
      credit_note_number_format: supplier.value.credit_note_number_format,
      invoice_number_period: supplier.value.invoice_number_period,
    })
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function startEditCurrency(c: CurrencyAccount) {
  editingCurrency.value = c.id
  editingCurrencyLabel.value = c.label
  Object.assign(currencyDraft, { ...c })
}

async function saveCurrency() {
  if (editingCurrency.value === null) return
  try {
    const updated = await settingsApi.updateCurrency(editingCurrency.value, {
      label: currencyDraft.label,
      is_active: currencyDraft.is_active,
      is_default: currencyDraft.is_default,
      account_number: currencyDraft.account_number || null,
      bank_code: currencyDraft.bank_code || null,
      bank_name: currencyDraft.bank_name || null,
      iban: currencyDraft.iban || null,
      bic: currencyDraft.bic || null,
    })
    currencies.value = await settingsApi.listCurrencies()
    editingCurrency.value = null
    toast.success(`${updated.code} (${updated.label}) — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function addCurrencyAccount(code: string) {
  const label = window.prompt(t('settings.add_account_prompt', { code }), t('settings.add_account_default_label', { code }))
  if (!label) return
  try {
    await settingsApi.createCurrency({ code, label, is_active: true })
    currencies.value = await settingsApi.listCurrencies()
    toast.success(`${label} — ${t('common.saved')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function removeCurrency(c: CurrencyAccount) {
  if (!window.confirm(t('settings.delete_account_confirm', { label: c.label }))) return
  try {
    await settingsApi.deleteCurrency(c.id)
    currencies.value = await settingsApi.listCurrencies()
    toast.success(`${c.label} — ${t('common.deleted')}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('settings.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('settings.subtitle') }}</p>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else-if="supplier" class="space-y-6">
      <!-- Supplier -->
      <section class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">{{ t('settings.supplier') }}</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.company_name') }} *</label>
            <input v-model="supplier.company_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.display_name') }}</label>
            <input v-model="supplier.display_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.street') }}</label>
            <input v-model="supplier.street" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.zip') }}</label>
              <input v-model="supplier.zip" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.city') }}</label>
              <input v-model="supplier.city" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.ic') }}</label>
            <input v-model="supplier.ic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.dic') }}</label>
            <input v-model="supplier.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="flex items-center gap-2 text-sm mt-7">
              <input v-model="supplier.is_vat_payer" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.is_vat_payer') }}
            </label>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.email') }} *</label>
            <input v-model="supplier.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.phone') }}</label>
            <input v-model="supplier.phone" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.web') }}</label>
            <input v-model="supplier.web" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.tagline') }}</label>
            <input v-model="supplier.tagline" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.commercial_register') }}</label>
            <input v-model="supplier.commercial_register" type="text"
              :placeholder="t('settings.commercial_register_placeholder')"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            <p class="text-xs text-neutral-500 mt-1">{{ t('settings.commercial_register_hint') }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_due') }}</label>
            <input v-model.number="supplier.default_payment_due_days" type="number" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.default_hourly_rate') }} ({{ supplier.default_currency }})</label>
            <input v-model.number="supplier.default_hourly_rate" type="number" step="0.01" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div class="md:col-span-2">
            <label class="flex items-center gap-2 text-sm">
              <input v-model="supplier.auto_send_reminders" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('settings.auto_send_reminders') }}
            </label>
            <p class="text-xs text-neutral-500 mt-1 ml-6">{{ t('settings.auto_send_reminders_hint') }}</p>
          </div>
        </div>

        <!-- Číslování faktur — per-supplier templ + period (migrace 0014) -->
        <div class="mt-6 pt-4 border-t border-neutral-200">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('settings.numbering_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-1">{{ t('settings.numbering_hint_intro') }}</p>
          <ul class="text-xs text-neutral-500 mb-3 space-y-0.5 ml-2">
            <li><code class="bg-neutral-100 px-1 rounded">{YYYY}</code> &mdash; {{ t('settings.numbering_hint_yyyy') }} <span class="text-neutral-400">(2026)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{YY}</code> &mdash; {{ t('settings.numbering_hint_yy') }} <span class="text-neutral-400">(26)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{MM}</code> &mdash; {{ t('settings.numbering_hint_mm') }} <span class="text-neutral-400">(05)</span></li>
            <li><code class="bg-neutral-100 px-1 rounded">{CC}</code>, <code class="bg-neutral-100 px-1 rounded">{CCC}</code>&hellip; &mdash; {{ t('settings.numbering_hint_c') }} <span class="text-neutral-400">(01, 001…)</span></li>
          </ul>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_format') }}</label>
              <input v-model="supplier.invoice_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.invoice || '{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="invoiceFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="invoiceFormatError" class="text-xs text-danger-500 mt-1">{{ invoiceFormatError }}</p>
              <p v-else-if="invoicePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ invoicePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.invoice_number_period') }}</label>
              <select v-model="supplier.invoice_number_period" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm">
                <option value="year">{{ t('settings.numbering_period_year') }}</option>
                <option value="month">{{ t('settings.numbering_period_month') }}</option>
                <option value="none">{{ t('settings.numbering_period_none') }}</option>
              </select>
              <p class="text-xs text-neutral-400 mt-1">{{ t('settings.invoice_number_period_hint') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.proforma_number_format') }}</label>
              <input v-model="supplier.proforma_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.proforma || '9{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="proformaFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="proformaFormatError" class="text-xs text-danger-500 mt-1">{{ proformaFormatError }}</p>
              <p v-else-if="proformaPreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ proformaPreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.credit_note_number_format') }}</label>
              <input v-model="supplier.credit_note_number_format" type="text"
                :placeholder="supplier.cfg_varsymbol_fallback?.credit_note || '7{YY}{MM}{CCC}'" maxlength="60"
                class="w-full h-9 px-3 border rounded-md text-sm font-mono"
                :class="creditNoteFormatError ? 'border-danger-500 bg-danger-50' : 'border-neutral-300'" />
              <p v-if="creditNoteFormatError" class="text-xs text-danger-500 mt-1">{{ creditNoteFormatError }}</p>
              <p v-else-if="creditNotePreview" class="text-xs text-success-600 mt-1">
                {{ t('settings.numbering_preview') }}: <code class="font-mono font-semibold">{{ creditNotePreview }}</code>
              </p>
              <p v-else class="text-xs text-neutral-400 mt-1">{{ t('settings.numbering_preview') }}: {{ t('settings.numbering_preview_fallback') }}</p>
            </div>
          </div>
        </div>

        <!-- Pohoda XML export config (volitelné) -->
        <div class="mt-6 pt-4 border-t border-neutral-200">
          <h3 class="text-xs font-semibold uppercase tracking-wide text-neutral-500 mb-1">{{ t('settings.pohoda_section') }}</h3>
          <p class="text-xs text-neutral-500 mb-3">{{ t('settings.pohoda_hint') }}</p>
          <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_account_code') }}</label>
              <input v-model="supplier.pohoda_account_code" type="text" placeholder="KB" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_centre_code') }}</label>
              <input v-model="supplier.pohoda_centre_code" type="text" placeholder="STR1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_activity_code') }}</label>
              <input v-model="supplier.pohoda_activity_code" type="text" placeholder="ACT1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('settings.pohoda_contract_code') }}</label>
              <input v-model="supplier.pohoda_contract_code" type="text" placeholder="ZAK1" class="w-full h-9 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
          </div>
        </div>

        <div class="mt-4 flex justify-end">
          <button @click="saveSupplier" class="cursor-pointer px-4 h-10 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md">
            {{ t('settings.save_supplier') }}
          </button>
        </div>
      </section>

      <!-- Currencies / Bank accounts -->
      <section class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <header class="px-5 py-3 border-b border-neutral-200">
          <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('settings.currencies_banks') }}</h2>
        </header>
        <div class="overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.currency') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_th') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.account_cz') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.iban') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('settings.bic') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('common.default') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('settings.active') }}</th>
              <th class="px-3 py-2 w-32"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="c in currencies" :key="c.id">
              <td class="px-3 py-2 font-mono">{{ c.code }} <span class="text-xs text-neutral-500">{{ c.symbol }}</span></td>
              <td class="px-3 py-2">{{ c.label }}</td>
              <td class="px-3 py-2 font-mono text-xs">
                {{ c.account_number }}<span v-if="c.bank_code"> / {{ c.bank_code }}</span>
              </td>
              <td class="px-3 py-2 font-mono text-xs">{{ c.iban || '—' }}</td>
              <td class="px-3 py-2 font-mono text-xs">{{ c.bic || '—' }}</td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_default" class="text-primary-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-center">
                <span v-if="c.is_active" class="text-success-600">✓</span>
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td class="px-3 py-2 text-right">
                <button @click="startEditCurrency(c)" class="cursor-pointer text-primary-600 hover:text-primary-700 text-xs">{{ t('common.edit') }}</button>
                <button v-if="(c.invoices_count ?? 0) === 0" @click="removeCurrency(c)"
                  class="cursor-pointer text-danger-600 hover:text-danger-700 text-xs ml-2">{{ t('common.delete') }}</button>
              </td>
            </tr>
          </tbody>
        </table>
        </div>
        <div class="px-5 py-3 border-t border-neutral-200 bg-neutral-50 text-xs text-neutral-600 flex flex-wrap gap-3 items-center">
          <span>{{ t('settings.add_another_account') }}</span>
          <button v-for="code in [...new Set(currencies.map(c => c.code))]" :key="code"
            @click="addCurrencyAccount(code)"
            class="cursor-pointer px-2 h-7 border border-neutral-300 rounded text-xs hover:bg-white">
            + {{ code }}
          </button>
        </div>
      </section>
    </div>

    <!-- Modal — currency edit -->
    <div v-if="editingCurrency" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('settings.edit_currency_label_full', { label: editingCurrencyLabel }) }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.account_label_form') }}</label>
            <input v-model="currencyDraft.label" type="text" placeholder="CZK — Fio Bank"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_account_cz') }}</label>
            <input v-model="currencyDraft.account_number" type="text" placeholder="1000000005"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_code') }}</label>
            <input v-model="currencyDraft.bank_code" type="text" placeholder="0100"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bank_name') }}</label>
            <input v-model="currencyDraft.bank_name" type="text" placeholder="KB"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.iban') }}</label>
            <input v-model="currencyDraft.iban" type="text" placeholder="CZ65 0100 0000 0019 2000 1453"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('settings.currency_bic') }}</label>
            <input v-model="currencyDraft.bic" type="text" placeholder="KOMBCZPP"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('settings.currency_active_hint') }}
          </label>
          <label class="flex items-center gap-2 text-sm">
            <input v-model="currencyDraft.is_default" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('codebooks.is_default_account_hint') }}
          </label>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="editingCurrency = null" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
            <button @click="saveCurrency" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.save') }}</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
