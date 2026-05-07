<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { suppliersApi, type SupplierListItem, type SupplierCreatePayload } from '@/api/suppliers'
import { clientsApi } from '@/api/clients'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const toast = useToast()
const supplierStore = useSupplierStore()
const auth = useAuthStore()

const list = ref<SupplierListItem[]>([])
const loading = ref(false)
const createOpen = ref(false)
const aresLoading = ref(false)
const aresMessage = ref<{ type: 'success' | 'error'; text: string } | null>(null)

const draft = reactive<SupplierCreatePayload>({
  company_name: '', street: '', city: '', zip: '', email: '',
  country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
  default_payment_due_days: 14, default_hourly_rate: 1500,
})

async function load() {
  loading.value = true
  try {
    list.value = await suppliersApi.list()
  } finally {
    loading.value = false
  }
}
onMounted(load)

function openNew() {
  Object.assign(draft, {
    company_name: '', street: '', city: '', zip: '', email: '',
    country_iso2: 'CZ', ic: '', dic: '', is_vat_payer: true,
    default_payment_due_days: 14, default_hourly_rate: 1500,
  })
  aresMessage.value = null
  createOpen.value = true
}

async function lookupAres() {
  const ic = (draft.ic || '').trim()
  if (!/^\d{8}$/.test(ic)) {
    aresMessage.value = { type: 'error', text: t('supplier.ares_invalid_ic') }
    return
  }
  aresLoading.value = true
  aresMessage.value = null
  try {
    const r = await clientsApi.lookupAres(ic)
    if (!r.found || !r.data) {
      aresMessage.value = { type: 'error', text: t('supplier.ares_not_found') }
      return
    }
    const d = r.data
    draft.company_name = d.company_name || draft.company_name
    draft.street       = d.street       || draft.street
    draft.city         = d.city         || draft.city
    draft.zip          = d.zip          || draft.zip
    draft.country_iso2 = d.country_iso2 || draft.country_iso2 || 'CZ'
    draft.ic           = d.ic           || ic
    draft.dic          = d.dic          || draft.dic
    draft.is_vat_payer = d.is_vat_payer
    aresMessage.value = { type: 'success', text: t('supplier.ares_loaded', { name: d.company_name }) }
  } catch (e: any) {
    aresMessage.value = { type: 'error', text: e?.response?.data?.error?.message || t('supplier.ares_failed') }
  } finally {
    aresLoading.value = false
  }
}

async function save() {
  if (!draft.company_name || !draft.street || !draft.city || !draft.zip || !draft.email) {
    toast.error(t('common.error'))
    return
  }
  try {
    await suppliersApi.create(draft)
    createOpen.value = false
    toast.success(t('common.saved'))
    await load()
    await auth.refresh()  // Reload available suppliers in store
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

async function remove(s: SupplierListItem) {
  if (s.clients_count > 0 || s.invoices_count > 0) return
  if (!confirm(t('supplier.delete_confirm'))) return
  try {
    await suppliersApi.delete(s.id)
    toast.success(t('common.deleted'))
    await load()
    await auth.refresh()
    if (supplierStore.currentSupplierId === s.id) {
      // Pokud jsme byli na něm, přepni na první v listu
      const first = list.value[0]
      if (first) supplierStore.setSupplier(first.id)
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

function switchTo(id: number) {
  if (id === supplierStore.currentSupplierId) return
  supplierStore.setSupplier(id)
  window.location.reload()
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('supplier.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('supplier.count', { n: list.length }) }}</p>
      </div>
      <button @click="openNew"
        class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md inline-flex items-center gap-1.5">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
        {{ t('supplier.new') }}
      </button>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
          <tr>
            <th class="px-3 py-2 w-10"></th>
            <th class="px-3 py-2 text-left font-medium">{{ t('supplier.company_name') }}</th>
            <th class="px-3 py-2 text-left font-medium">{{ t('supplier.ic') }} / {{ t('supplier.dic') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('supplier.clients') }}</th>
            <th class="px-3 py-2 text-right font-medium">{{ t('supplier.invoices') }}</th>
            <th class="px-3 py-2 w-48"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="s in list" :key="s.id" class="hover:bg-neutral-50">
            <td class="px-3 py-2 text-center">
              <span v-if="s.id === supplierStore.currentSupplierId" class="text-primary-600 text-base" :title="t('supplier.active_label')">●</span>
            </td>
            <td class="px-3 py-2">
              <div class="font-medium text-neutral-900">{{ s.company_name }}</div>
              <div v-if="s.display_name && s.display_name !== s.company_name" class="text-xs text-neutral-500">{{ s.display_name }}</div>
            </td>
            <td class="px-3 py-2 font-mono text-xs">
              <span v-if="s.ic">{{ s.ic }}</span>
              <span v-if="s.ic && s.dic"> / </span>
              <span v-if="s.dic">{{ s.dic }}</span>
              <span v-if="!s.ic && !s.dic" class="text-neutral-400">—</span>
            </td>
            <td class="px-3 py-2 text-right font-mono">{{ s.clients_count }}</td>
            <td class="px-3 py-2 text-right font-mono">{{ s.invoices_count }}</td>
            <td class="px-3 py-2 text-right text-xs">
              <button v-if="s.id !== supplierStore.currentSupplierId" @click="switchTo(s.id)"
                class="cursor-pointer text-primary-600 hover:text-primary-700 mr-3">
                {{ t('supplier.switch') }}
              </button>
              <button @click="remove(s)" :disabled="s.clients_count > 0 || s.invoices_count > 0 || list.length <= 1"
                class="cursor-pointer text-danger-500 hover:text-danger-600 disabled:opacity-30 disabled:cursor-not-allowed">
                {{ t('common.delete') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="createOpen" class="fixed inset-0 bg-neutral-900/40 z-50 flex items-center justify-center p-4">
      <div class="bg-white rounded-xl shadow-lg max-w-xl w-full p-5">
        <h3 class="text-lg font-semibold mb-1">{{ t('supplier.create_title') }}</h3>
        <p class="text-xs text-neutral-500 mb-4">{{ t('supplier.create_hint') }}</p>
        <div class="space-y-3">
          <!-- ARES lookup helper — vyplní pole níže podle IČ -->
          <div class="bg-primary-50/50 border border-primary-200 rounded-md p-3">
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.ares_lookup') }}</label>
            <div class="flex gap-2">
              <input v-model="draft.ic" type="text" placeholder="12345678" maxlength="8"
                @keydown.enter.prevent="lookupAres"
                class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <button type="button" @click="lookupAres" :disabled="aresLoading"
                class="cursor-pointer h-10 px-4 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md inline-flex items-center gap-1.5">
                <svg v-if="!aresLoading" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 1 1-14 0 7 7 0 0 1 14 0z"/></svg>
                <span v-else>…</span>
                {{ aresLoading ? t('common.loading') : t('supplier.ares_load') }}
              </button>
            </div>
            <div v-if="aresMessage" class="mt-2 text-xs px-2 py-1 rounded"
              :class="aresMessage.type === 'success' ? 'bg-success-50 text-success-600' : 'bg-danger-50 text-danger-500'">
              {{ aresMessage.text }}
            </div>
          </div>

          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.company_name') }} *</label>
            <input v-model="draft.company_name" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.dic') }}</label>
            <input v-model="draft.dic" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.street') }} *</label>
            <input v-model="draft.street" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.zip') }} *</label>
              <input v-model="draft.zip" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.city') }} *</label>
              <input v-model="draft.city" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('supplier.email') }} *</label>
            <input v-model="draft.email" type="email" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <div class="flex justify-end gap-2 pt-4 mt-3 border-t border-neutral-200">
          <button @click="createOpen = false" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
          <button @click="save" class="cursor-pointer px-4 h-9 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">{{ t('common.create') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
