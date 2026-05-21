<script setup lang="ts">
import { ref, onMounted, watch, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { clientsApi, type Client } from '@/api/clients'
import { formatMoney, formatDate } from '@/composables/useFormat'
import TableSkeleton from '@/components/ui/TableSkeleton.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

type RoleFilter = 'all' | 'customers' | 'vendors'

const { t } = useI18n()

const router = useRouter()
const items = ref<Client[]>([])
const total = ref(0)
const page = ref(1)
const pages = ref(1)
const loading = ref(false)
const loadingMore = ref(false)
const search = ref('')
const showArchived = ref(false)
const sort = ref<'name' | 'revenue' | 'last_activity'>('name')
const route = useRoute()
// Initial filter from ?role=vendors|all (default customers)
const roleFilter = ref<RoleFilter>(((): RoleFilter => {
  const q = String(route.query.role ?? '')
  return q === 'vendors' || q === 'all' ? q : 'customers'
})())
let searchTimeout: ReturnType<typeof setTimeout> | null = null

// Klient-side role filter (backend zatím vrací všechny; přepsání na server-side filter
// je TODO, kdy bude víc dat).
const filteredItems = computed(() => {
  if (roleFilter.value === 'all') return items.value
  return items.value.filter(c => {
    if (roleFilter.value === 'vendors')   return c.is_vendor   === true
    if (roleFilter.value === 'customers') return c.is_customer !== false  // default true pro existující data
    return true
  })
})

const roleCounts = computed(() => ({
  all:       items.value.length,
  customers: items.value.filter(c => c.is_customer !== false).length,
  vendors:   items.value.filter(c => c.is_vendor   === true).length,
}))

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await clientsApi.list({
      q: search.value,
      archived: showArchived.value,
      sort: sort.value,
      page: page.value,
    })
    if (reset) {
      items.value = r.data
    } else {
      items.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(() => load(true))
watch(showArchived, () => load(true))
watch(sort, () => load(true))
watch(search, () => {
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => load(true), 300)
})

function openClient(c: Client) {
  router.push(`/clients/${c.id}`)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-semibold">{{ t('client.title') }}</h1>
      <RouterLink
        to="/clients/new"
        class="inline-flex items-center gap-1.5 h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-md"
      >
        {{ t('client.new') }}
      </RouterLink>
    </div>

    <div class="bg-white border border-neutral-200 rounded-lg shadow-sm">
      <!-- Tabs: Klienti / Dodavatelé / Vše -->
      <div class="px-4 pt-2 border-b border-neutral-100 flex items-center gap-1">
        <button
          v-for="opt in [
            { key: 'customers' as RoleFilter, label: t('client.tab_customers') },
            { key: 'vendors' as RoleFilter,   label: t('client.tab_vendors') },
            { key: 'all' as RoleFilter,       label: t('client.tab_all') },
          ]"
          :key="opt.key"
          type="button"
          @click="roleFilter = opt.key"
          class="cursor-pointer relative px-3 py-2 text-sm border-b-2 transition"
          :class="roleFilter === opt.key
            ? 'border-primary-600 text-primary-700 font-medium'
            : 'border-transparent text-neutral-600 hover:text-neutral-900'"
        >
          {{ opt.label }}
          <span class="ml-1.5 inline-block px-1.5 py-0.5 text-xs bg-neutral-100 text-neutral-600 rounded-full">{{ roleCounts[opt.key] }}</span>
        </button>
      </div>

      <div class="px-4 py-3 border-b border-neutral-200 flex flex-col sm:flex-row sm:items-center gap-3">
        <input
          v-model="search"
          type="search"
          :placeholder="t('common.search')"
          class="flex-1 h-9 px-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
        />
        <label class="flex items-center gap-2 text-sm text-neutral-700">
          <input v-model="showArchived" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
          {{ t('client.show_archived') }}
        </label>
        <select v-model="sort" class="h-9 px-3 border border-neutral-300 rounded-md text-sm bg-white"
          :title="t('common.sort_by')">
          <option value="name">{{ t('common.sort_name') }}</option>
          <option value="revenue">{{ t('common.sort_revenue') }}</option>
          <option value="last_activity">{{ t('common.sort_last_activity') }}</option>
        </select>
      </div>

      <TableSkeleton v-if="loading" :rows="6" :cols="6" />

      <EmptyState v-else-if="!items.length"
        :title="t('client.no_data')"
        :cta="t('client.create_first')"
        to="/clients/new" />

      <!-- Desktop: tabulka -->
      <div v-else class="hidden md:block overflow-x-auto"><table class="w-full text-sm table-sticky-first">
        <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
          <tr>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.company') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.ic') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('client.email') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('nav.projects') }}</th>
            <th class="text-right px-4 py-2.5 font-medium">{{ t('common.revenue') }}</th>
            <th class="text-left px-4 py-2.5 font-medium">{{ t('common.last_activity') }}</th>
            <th class="text-center px-4 py-2.5 font-medium">{{ t('common.currency') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr
            v-for="c in filteredItems"
            :key="c.id"
            @click="openClient(c)"
            class="cursor-pointer hover:bg-neutral-50"
          >
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <div class="font-medium text-neutral-900">{{ c.company_name }}</div>
                <span v-if="c.is_customer !== false && c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-purple-100 text-purple-700 rounded font-medium uppercase tracking-wide"
                      :title="t('client.dual_role_tooltip')">K+D</span>
                <span v-else-if="c.is_vendor === true"
                      class="inline-block px-1.5 py-0 text-[10px] bg-amber-100 text-amber-700 rounded font-medium uppercase tracking-wide">{{ t('client.vendor_badge') }}</span>
              </div>
              <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
            </td>
            <td class="px-4 py-3 font-mono text-xs text-neutral-600">{{ c.ic || '—' }}</td>
            <td class="px-4 py-3 text-neutral-600">{{ c.main_email }}</td>
            <td class="px-4 py-3 text-center">
              <span v-if="c.active_projects_count" class="inline-block px-2 py-0.5 text-xs bg-primary-50 text-primary-700 rounded">
                {{ c.active_projects_count }}
              </span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-right font-mono">
              <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-neutral-600 text-xs">
              <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </td>
            <td class="px-4 py-3 text-center text-neutral-600 font-mono text-xs">{{ c.currency_default }}</td>
          </tr>
        </tbody>
      </table></div>

      <!-- Mobile: karty -->
      <div v-if="items.length" class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="c in filteredItems"
          :key="`m-${c.id}`"
          @click="openClient(c)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <div class="font-medium text-neutral-900 truncate">{{ c.company_name }}</div>
            <div class="font-mono text-sm whitespace-nowrap">
              <span v-if="c.revenue && c.revenue > 0">{{ formatMoney(c.revenue, c.currency_default) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </div>
          </div>
          <div v-if="c.archived_at" class="text-xs text-neutral-400 mt-0.5">{{ t('common.archived') }}</div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
            <div class="truncate">
              <span class="font-mono">{{ c.ic || '—' }}</span>
              <span v-if="c.main_email" class="text-neutral-400"> · </span>
              <span v-if="c.main_email" class="truncate">{{ c.main_email }}</span>
            </div>
            <span class="font-mono whitespace-nowrap">{{ c.currency_default }}</span>
          </div>
          <div class="flex items-center justify-between gap-2 mt-2 text-xs">
            <span class="text-neutral-600">
              <span v-if="c.last_invoice_date">{{ formatDate(c.last_invoice_date) }}</span>
              <span v-else class="text-neutral-300">—</span>
            </span>
            <span v-if="c.active_projects_count" class="px-2 py-0.5 bg-primary-50 text-primary-700 rounded">
              {{ t('nav.projects') }}: {{ c.active_projects_count }}
            </span>
          </div>
        </div>
      </div>

      <div v-if="items.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: filteredItems.length, total: roleCounts[roleFilter] }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>
