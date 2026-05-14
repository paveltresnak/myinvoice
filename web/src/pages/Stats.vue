<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { dashboardApi, type DashboardSummary } from '@/api/dashboard'
import { projectsApi, type ProjectStats } from '@/api/projects'
import { formatMoney } from '@/composables/useFormat'
import RevenueChart from '@/components/charts/RevenueChart.vue'
import CumulativeYtdChart from '@/components/charts/CumulativeYtdChart.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import TopProjectsBarChart from '@/components/charts/TopProjectsBarChart.vue'
import StatusDoughnutChart from '@/components/charts/StatusDoughnutChart.vue'
import ProjectStatusChart from '@/components/charts/ProjectStatusChart.vue'
import PaymentDaysHistogramChart from '@/components/charts/PaymentDaysHistogramChart.vue'
import VatBreakdownChart from '@/components/charts/VatBreakdownChart.vue'
import AgingChart from '@/components/charts/AgingChart.vue'
import InvoiceSizeChart from '@/components/charts/InvoiceSizeChart.vue'

const { t } = useI18n()

const summary = ref<DashboardSummary | null>(null)
const projectStats = ref<ProjectStats | null>(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    const [s, ps] = await Promise.all([
      dashboardApi.summary(),
      projectsApi.stats(),
    ])
    summary.value = s
    projectStats.value = ps
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('errors.generic')
  } finally {
    loading.value = false
  }
})

const isVatPayer = computed(() => summary.value?.is_vat_payer ?? false)

const VAT_THRESHOLD_CZK = 2_000_000

/** Pro CZK + plátce DPH zobrazujeme prahovou čáru a procentuální využití limitu. */
const rolling12mCzk = computed(() => summary.value?.rolling_12m.find(r => r.currency === 'CZK') ?? null)

const vatLimitPct = computed<number | null>(() => {
  if (!isVatPayer.value || !rolling12mCzk.value) return null
  return Math.round((rolling12mCzk.value.total / VAT_THRESHOLD_CZK) * 100)
})

const statusCountsProjects = computed<Record<string, number>>(() => {
  const m: Record<string, number> = {}
  for (const r of projectStats.value?.status_breakdown ?? []) m[r.status] = r.count
  return m
})

function topProjectChart(scope: 'this' | 'prev') {
  const ps = projectStats.value
  const block = scope === 'this' ? ps?.top_this_year : ps?.top_prev_year
  if (!block) return { labels: [], values: [], greyed: [] as number[] }
  const labels = block.top.map(p => `${p.name} — ${p.client_company_name}`)
  const values = block.top.map(p => p.revenue)
  const greyed: number[] = []
  if (block.others.count > 0) {
    labels.push(t('project.others_label', { n: block.others.count }))
    values.push(block.others.revenue)
    greyed.push(values.length - 1)
  }
  return { labels, values, greyed }
}

/**
 * Concentration risk — % obratu z TOP3 / TOP5 klientů za rolling 12 měsíců.
 * Indikátor závislosti na málo zákaznících (riziko ztráty hlavního klienta).
 */
const concentration = computed(() => {
  const items = summary.value?.top_clients_12m ?? []
  if (!items.length) return null
  // Primární měna — vezmeme tu s nejvyšším obratem v top_12m.
  const byCurrency = new Map<string, number>()
  for (const it of items) byCurrency.set(it.currency, (byCurrency.get(it.currency) ?? 0) + it.total)
  const [topCur] = Array.from(byCurrency.entries()).sort((a, b) => b[1] - a[1])
  if (!topCur) return null
  const cur = topCur[0]
  const inCur = items.filter(i => i.currency === cur)
  const total12m = summary.value?.rolling_12m.find(r => r.currency === cur)?.total ?? 0
  if (total12m <= 0) return null
  const top3 = inCur.slice(0, 3).reduce((s, i) => s + i.total, 0)
  const top5 = inCur.slice(0, 5).reduce((s, i) => s + i.total, 0)
  return {
    currency: cur,
    top3_pct: Math.round((top3 / total12m) * 100),
    top5_pct: Math.round((top5 / total12m) * 100),
    total: total12m,
    top3_total: top3,
    top5_total: top5,
  }
})

const concentrationLevel = computed(() => {
  const c = concentration.value
  if (!c) return 'ok'
  if (c.top3_pct >= 70 || c.top5_pct >= 90) return 'high'
  if (c.top3_pct >= 50 || c.top5_pct >= 70) return 'medium'
  return 'ok'
})

const primaryCurrency = computed(() => projectStats.value?.primary_currency ?? 'CZK')

/** Obrat tento rok per měna — pole pro KPI tile. */
const revenueThisYear = computed(() =>
  (summary.value?.kpi.per_currency ?? []).map(c => ({
    currency: c.currency,
    total: c.this_year,
    change_pct: c.change_pct,
    invoice_count: c.this_year_invoice_count,
    client_count: c.this_year_client_count,
    project_count: c.this_year_project_count,
  }))
)
const revenuePrevYear = computed(() =>
  (summary.value?.kpi.per_currency ?? []).map(c => ({
    currency: c.currency,
    total: c.prev_year,
    invoice_count: c.prev_year_invoice_count,
    client_count: c.prev_year_client_count,
    project_count: c.prev_year_project_count,
  }))
)

/** Měsíční breakdown pro tabulku — všechny měny do jednoho indexu YYYY-MM. */
const monthlyTable = computed(() => {
  if (!summary.value) return [] as Array<{ ym: string; perCurrency: Array<{ currency: string; total: number }> }>
  const index = new Map<string, Map<string, number>>()
  for (const rev of summary.value.revenue_by_month) {
    for (const m of rev.months) {
      if (!index.has(m.ym)) index.set(m.ym, new Map())
      index.get(m.ym)!.set(rev.currency, m.total)
    }
  }
  return Array.from(index.entries())
    .sort((a, b) => b[0].localeCompare(a[0]))
    .map(([ym, perMap]) => ({
      ym,
      perCurrency: Array.from(perMap.entries()).map(([currency, total]) => ({ currency, total })),
    }))
})

function ymLabel(ym: string): string {
  const [y, m] = ym.split('-')
  return `${m}/${y}`
}

/** Aging report jen s ne-nulovými řádky (žádná měna bez pohledávek). */
const agingRows = computed(() => {
  return (summary.value?.aging_report ?? []).filter(r =>
    r.current + r.b1_30 + r.b31_60 + r.b61_90 + r.b90_plus > 0
  )
})

const hasAnyData = computed(() =>
  !!(summary.value && (summary.value.rolling_12m.length > 0
    || summary.value.revenue_by_month.length > 0
    || summary.value.top_clients_ytd.length > 0
    || summary.value.top_clients_prev_year.length > 0
    || (projectStats.value?.top_this_year.top.length ?? 0) > 0))
)
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-2xl font-semibold mb-1">{{ t('stats.title') }}</h1>
      <p class="text-sm text-neutral-500">{{ t('stats.subtitle') }}</p>
      <div v-if="summary" class="mt-2 inline-flex items-center gap-2 text-xs px-2.5 py-1 rounded-full"
        :class="isVatPayer ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'">
        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/>
        </svg>
        {{ isVatPayer ? t('stats.vat_payer_note') : t('stats.non_vat_payer_note') }}
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('dashboard.loading_data') }}</div>

    <div v-else-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">
      {{ error }}
    </div>

    <div v-else-if="!hasAnyData" class="bg-white border border-neutral-200 rounded-lg p-8 text-center">
      <p class="text-neutral-500">{{ t('stats.no_data') }}</p>
    </div>

    <div v-else-if="summary" class="space-y-6">
      <!-- Plovoucí 12měsíční obrat — KPI tiles per měna -->
      <div v-if="summary.rolling_12m.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div v-for="r in summary.rolling_12m" :key="`r12-${r.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('stats.rolling_12m', { currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono"
            :title="t('stats.rolling_12m_tooltip', { current: formatMoney(r.total, r.currency), prev: formatMoney(r.prev_period_total, r.currency) })">
            {{ formatMoney(r.total, r.currency) }}
          </div>
          <div v-if="r.prev_period_total > 0" class="text-xs mt-1"
            :class="r.total >= r.prev_period_total ? 'text-success-600' : 'text-danger-500'">
            {{ t('stats.rolling_12m_yoy', {
              sign: r.total >= r.prev_period_total ? '▲' : '▼',
              pct: Math.abs(Math.round(((r.total - r.prev_period_total) / r.prev_period_total) * 100 * 10) / 10)
            }) }}
          </div>
          <div class="text-xs text-neutral-500 mt-1">
            {{ t('stats.rolling_12m_vs_prev', { total: formatMoney(r.prev_period_total, r.currency) }) }}
          </div>
          <!-- DPH limit indikátor (jen CZK + plátce DPH). -->
          <template v-if="r.currency === 'CZK' && isVatPayer">
            <div class="mt-3">
              <div class="h-2 bg-neutral-100 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all"
                  :class="r.total >= 2_000_000 ? 'bg-danger-500' : r.total >= 1_600_000 ? 'bg-warning-500' : 'bg-success-600'"
                  :style="{ width: Math.min(100, (r.total / 2_000_000) * 100) + '%' }"></div>
              </div>
              <div class="text-xs mt-1.5"
                :class="r.total >= 2_000_000 ? 'text-danger-500 font-medium' : r.total >= 1_600_000 ? 'text-warning-600' : 'text-neutral-500'">
                <span v-if="r.total >= 2_000_000">{{ t('stats.rolling_12m_over_threshold') }}</span>
                <span v-else-if="r.total >= 1_600_000">{{ t('stats.rolling_12m_near_threshold', { pct: vatLimitPct }) }}</span>
                <span v-else>{{ t('stats.rolling_12m_ok', { pct: vatLimitPct }) }}</span>
              </div>
              <div class="text-[10px] text-neutral-400 mt-0.5">{{ t('stats.rolling_12m_vat_threshold') }}</div>
            </div>
          </template>
          <div v-else class="text-[11px] text-neutral-400 mt-2">{{ t('stats.rolling_12m_hint') }}</div>
        </div>

        <!-- Obrat tento rok per měna -->
        <div v-for="r in revenueThisYear" :key="`ty-${r.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('stats.revenue_this_year', { year: summary.year, currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div v-if="r.change_pct !== null" class="text-xs mt-1"
            :class="r.change_pct >= 0 ? 'text-success-600' : 'text-danger-500'">
            {{ r.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(r.change_pct) }} % {{ t('dashboard.vs_prev_ytd', { year: summary.prev_year }) }}
          </div>
          <div v-else class="text-xs text-neutral-400 mt-1">{{ t('dashboard.no_prev_year', { year: summary.prev_year }) }}</div>
          <div class="text-[11px] text-neutral-500 mt-2 flex flex-wrap gap-x-3 gap-y-0.5">
            <span>{{ t('stats.year_invoices_n', { n: r.invoice_count }) }}</span>
            <span>{{ t('stats.year_clients_n', { n: r.client_count }) }}</span>
            <span>{{ t('stats.year_projects_n', { n: r.project_count }) }}</span>
          </div>
        </div>

        <!-- Forecast aktuálního roku per měna — growth-adjusted seasonality -->
        <div v-for="f in summary.revenue_forecast" :key="`fc-${f.currency}`"
          class="bg-white border border-primary-200 rounded-lg p-5 shadow-sm bg-primary-50/30">
          <div class="text-xs uppercase tracking-wide text-primary-700 mb-1">
            {{ t('stats.forecast_year', { year: summary.year, currency: f.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-primary-700 font-mono"
            :title="t('stats.forecast_tooltip', {
              ytd: formatMoney(f.ytd, f.currency),
              growth: Math.round((f.growth_ratio - 1) * 100 * 10) / 10,
              remainder: formatMoney(f.prev_year_remainder, f.currency)
            })">{{ formatMoney(f.forecast, f.currency) }}</div>
          <div v-if="f.prev_year_full > 0" class="text-xs mt-1"
            :class="f.forecast >= f.prev_year_full ? 'text-success-600' : 'text-danger-500'">
            {{ f.forecast >= f.prev_year_full ? '▲' : '▼' }}
            {{ Math.abs(Math.round(((f.forecast - f.prev_year_full) / f.prev_year_full) * 100 * 10) / 10) }} %
            {{ t('stats.vs_prev_year_full', { year: summary.prev_year }) }}
          </div>
          <div class="text-[11px] text-neutral-500 mt-2">
            {{ t('stats.forecast_growth_hint', { growth: ((f.growth_ratio - 1) * 100).toFixed(1) }) }}
          </div>
        </div>

        <!-- Obrat minulý rok per měna -->
        <div v-for="r in revenuePrevYear" :key="`py-${r.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('stats.revenue_prev_year', { year: summary.prev_year, currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-700 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div class="text-[11px] text-neutral-500 mt-2 flex flex-wrap gap-x-3 gap-y-0.5">
            <span>{{ t('stats.year_invoices_n', { n: r.invoice_count }) }}</span>
            <span>{{ t('stats.year_clients_n', { n: r.client_count }) }}</span>
            <span>{{ t('stats.year_projects_n', { n: r.project_count }) }}</span>
          </div>
        </div>

        <!-- Počet vystavených faktur YTD -->
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('stats.invoices_count_ytd', { year: summary.year }) }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.kpi.issued_count_ytd }}</div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.invoices_unit') }}</div>
        </div>

        <!-- Počet aktivních klientů -->
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('stats.active_clients') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.active_clients_count }}</div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('stats.active_clients_hint') }}</div>
        </div>

        <!-- Ø doba úhrady -->
        <div class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('dashboard.avg_payment') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">
            {{ summary.kpi.avg_payment_days !== null ? summary.kpi.avg_payment_days + ' ' + t('dashboard.days') : '—' }}
          </div>
          <div class="text-xs text-neutral-400 mt-1">{{ t('dashboard.this_year_paid') }}</div>
        </div>

        <!-- Obrat posledních 30 dní per měna -->
        <div v-for="r in summary.revenue_last_30d" :key="`r30-${r.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">
            {{ t('stats.revenue_last_30d', { currency: r.currency }) }}
          </div>
          <div class="text-2xl font-semibold text-neutral-900 font-mono">{{ formatMoney(r.total, r.currency) }}</div>
          <div class="text-xs text-neutral-500 mt-1">{{ r.invoice_count }} {{ t('dashboard.invoices_unit') }}</div>
          <div class="text-[11px] text-neutral-400 mt-2">{{ t('stats.last_30d_hint') }}</div>
        </div>

        <!-- Aktivní pravidelné fakturace -->
        <RouterLink to="/recurring"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm hover:bg-neutral-50 transition cursor-pointer block">
          <div class="text-xs uppercase tracking-wide text-neutral-500 mb-1">{{ t('stats.active_recurring') }}</div>
          <div class="text-2xl font-semibold text-neutral-900">{{ summary.active_recurring_count }}</div>
          <div class="text-[11px] text-neutral-400 mt-2">{{ t('stats.active_recurring_hint') }}</div>
        </RouterLink>
      </div>

      <!-- Měsíční obrat — bar + prev-year linka -->
      <div v-if="summary.revenue_by_month.length" class="space-y-4">
        <div v-for="rev in summary.revenue_by_month" :key="`m-${rev.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('stats.revenue_last_12_months', { currency: rev.currency }) }}
          </h3>
          <RevenueChart :months="rev.months" :prev-year="rev.prev_year" :currency="rev.currency" />
        </div>
      </div>

      <!-- Kumulativní YTD vs loni — per měna -->
      <div v-if="summary.revenue_by_month.length" class="space-y-4">
        <div v-for="rev in summary.revenue_by_month" :key="`c-${rev.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('stats.cumulative_ytd', { currency: rev.currency }) }}
            </h3>
            <span class="text-xs text-neutral-400">{{ t('stats.cumulative_ytd_hint') }}</span>
          </div>
          <CumulativeYtdChart :months="rev.months" :prev-year="rev.prev_year" :currency="rev.currency" />
        </div>
      </div>

      <!-- Top klienti pie YTD + loni -->
      <div v-if="(summary.top_clients_ytd.length + summary.top_clients_prev_year.length) > 0"
        class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.top_clients_ytd.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('stats.top_clients_year', { year: summary.year }) }}
          </h3>
          <TopClientsPieChart :clients="summary.top_clients_ytd" :currency="primaryCurrency" />
        </div>
        <div v-if="summary.top_clients_prev_year.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('stats.top_clients_year', { year: summary.prev_year }) }}
          </h3>
          <TopClientsPieChart :clients="summary.top_clients_prev_year" :currency="primaryCurrency" />
        </div>
      </div>

      <!-- Top zakázky bar YTD + loni -->
      <div v-if="projectStats && (projectStats.top_this_year.top.length + projectStats.top_prev_year.top.length) > 0"
        class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="projectStats.top_this_year.top.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('stats.top_projects_year', { year: projectStats.this_year }) }}
            </h3>
            <span class="text-xs font-mono text-neutral-500">{{ projectStats.primary_currency }}</span>
          </div>
          <TopProjectsBarChart
            :labels="topProjectChart('this').labels"
            :values="topProjectChart('this').values"
            :greyed-indexes="topProjectChart('this').greyed"
            :currency="projectStats.primary_currency" />
        </div>
        <div v-if="projectStats.top_prev_year.top.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">
              {{ t('stats.top_projects_year', { year: projectStats.prev_year }) }}
            </h3>
            <span class="text-xs font-mono text-neutral-500">{{ projectStats.primary_currency }}</span>
          </div>
          <TopProjectsBarChart
            :labels="topProjectChart('prev').labels"
            :values="topProjectChart('prev').values"
            :greyed-indexes="topProjectChart('prev').greyed"
            :currency="projectStats.primary_currency" />
        </div>
      </div>

      <!-- Status donuty (faktury + zakázky) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.kpi.status_counts_ytd && Object.keys(summary.kpi.status_counts_ytd).length"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('stats.status_invoices', { year: summary.year }) }}
          </h3>
          <StatusDoughnutChart :counts="summary.kpi.status_counts_ytd" />
        </div>
        <div v-if="projectStats && projectStats.status_breakdown.length"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500 mb-4">
            {{ t('stats.status_projects') }}
          </h3>
          <ProjectStatusChart :counts="statusCountsProjects" />
        </div>
      </div>

      <!-- Číselné tabulky pod grafy -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Obrat po rocích -->
        <div v-if="summary.revenue_by_year.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('stats.revenue_by_year_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.year') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.revenue') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('client.invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <!-- Predikce aktuálního roku — per měna, nad letošní YTD řádkem -->
                <tr v-for="f in summary.revenue_forecast" :key="`fc-row-${f.currency}`"
                    class="bg-primary-50/40">
                  <td class="px-4 py-2 font-medium text-primary-700">
                    {{ summary.year }}
                    <span class="ml-1 text-[10px] font-normal text-primary-600 uppercase tracking-wide">{{ t('stats.forecast_label') }}</span>
                  </td>
                  <td class="px-4 py-2 text-right font-mono text-primary-700">
                    {{ formatMoney(f.forecast, f.currency) }}
                  </td>
                  <td class="px-4 py-2 text-right text-xs text-primary-600">—</td>
                </tr>
                <tr v-for="r in summary.revenue_by_year" :key="`y-${r.year}-${r.currency}`">
                  <td class="px-4 py-2 font-medium">{{ r.year }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatMoney(r.total, r.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ r.invoice_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Obrat po měsících (12 měsíců) -->
        <div v-if="monthlyTable.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('stats.revenue_by_month_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium">{{ t('common.month') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.revenue') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in monthlyTable" :key="`mt-${row.ym}`">
                  <td class="px-4 py-2 font-mono text-neutral-700">{{ ymLabel(row.ym) }}</td>
                  <td class="px-4 py-2 text-right font-mono space-x-3">
                    <span v-for="c in row.perCurrency" :key="`${row.ym}-${c.currency}`">
                      {{ formatMoney(c.total, c.currency) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Top 12 klientů + Top 12 zakázek za rolling 12 měsíců (smart mix — pie nahoře YTD/loni, tabulky 12m) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.top_clients_12m.length" class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('stats.top_clients_12m_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium w-8">#</th>
                  <th class="text-left px-4 py-2 font-medium">{{ t('nav.clients') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.revenue') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('client.invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(c, i) in summary.top_clients_12m" :key="`tc12-${c.client_id}-${c.currency}`"
                    class="hover:bg-neutral-50 cursor-pointer"
                    @click="$router.push(`/clients/${c.client_id}`)">
                  <td class="px-4 py-2 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
                  <td class="px-4 py-2 truncate max-w-[260px]">{{ c.company_name }}</td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatMoney(c.total, c.currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ c.invoice_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <div v-if="projectStats && projectStats.top_12m.top.length"
          class="bg-white border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <header class="px-5 py-3 border-b border-neutral-200">
            <h3 class="font-semibold">{{ t('stats.top_projects_12m_table') }}</h3>
          </header>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
                <tr>
                  <th class="text-left px-4 py-2 font-medium w-8">#</th>
                  <th class="text-left px-4 py-2 font-medium">{{ t('project.name') }}</th>
                  <th class="text-right px-4 py-2 font-medium">{{ t('common.revenue') }}</th>
                  <th class="text-right px-4 py-2 font-medium whitespace-nowrap">{{ t('client.invoices_short') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(p, i) in projectStats.top_12m.top" :key="`tp12-${p.id}`"
                    class="hover:bg-neutral-50 cursor-pointer"
                    @click="$router.push(`/projects/${p.id}`)">
                  <td class="px-4 py-2 text-neutral-400 font-mono text-xs">{{ i + 1 }}</td>
                  <td class="px-4 py-2 truncate max-w-[260px]">
                    <div>{{ p.name }}</div>
                    <div class="text-xs text-neutral-500 truncate">{{ p.client_company_name }}</div>
                  </td>
                  <td class="px-4 py-2 text-right font-mono">{{ formatMoney(p.revenue, projectStats.primary_currency) }}</td>
                  <td class="px-4 py-2 text-right text-xs text-neutral-500">{{ p.invoice_count }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Concentration risk — % obratu z TOP3/TOP5 klientů (rolling 12m) -->
      <div v-if="concentration" class="bg-white border rounded-lg p-5 shadow-sm"
        :class="concentrationLevel === 'high' ? 'border-danger-500/40 bg-danger-50/30'
              : concentrationLevel === 'medium' ? 'border-warning-500/50 bg-warning-50/30'
              : 'border-neutral-200'">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.concentration_title') }}</h3>
          <span class="text-xs font-mono text-neutral-500">{{ concentration.currency }} · {{ t('stats.last_12_months') }}</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
          <div>
            <div class="text-xs text-neutral-500 mb-1">{{ t('stats.concentration_top3') }}</div>
            <div class="flex items-baseline gap-2">
              <span class="text-3xl font-semibold font-mono"
                :class="concentration.top3_pct >= 70 ? 'text-danger-500'
                       : concentration.top3_pct >= 50 ? 'text-warning-600'
                       : 'text-neutral-900'">{{ concentration.top3_pct }} %</span>
              <span class="text-xs text-neutral-500">{{ formatMoney(concentration.top3_total, concentration.currency) }}</span>
            </div>
            <div class="h-1.5 mt-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full"
                :class="concentration.top3_pct >= 70 ? 'bg-danger-500'
                       : concentration.top3_pct >= 50 ? 'bg-warning-500'
                       : 'bg-success-600'"
                :style="{ width: Math.min(100, concentration.top3_pct) + '%' }"></div>
            </div>
          </div>
          <div>
            <div class="text-xs text-neutral-500 mb-1">{{ t('stats.concentration_top5') }}</div>
            <div class="flex items-baseline gap-2">
              <span class="text-3xl font-semibold font-mono"
                :class="concentration.top5_pct >= 90 ? 'text-danger-500'
                       : concentration.top5_pct >= 70 ? 'text-warning-600'
                       : 'text-neutral-900'">{{ concentration.top5_pct }} %</span>
              <span class="text-xs text-neutral-500">{{ formatMoney(concentration.top5_total, concentration.currency) }}</span>
            </div>
            <div class="h-1.5 mt-2 bg-neutral-100 rounded-full overflow-hidden">
              <div class="h-full rounded-full"
                :class="concentration.top5_pct >= 90 ? 'bg-danger-500'
                       : concentration.top5_pct >= 70 ? 'bg-warning-500'
                       : 'bg-success-600'"
                :style="{ width: Math.min(100, concentration.top5_pct) + '%' }"></div>
            </div>
          </div>
        </div>
        <div class="text-xs mt-3"
          :class="concentrationLevel === 'high' ? 'text-danger-500 font-medium'
                : concentrationLevel === 'medium' ? 'text-warning-600' : 'text-neutral-500'">
          <span v-if="concentrationLevel === 'high'">{{ t('stats.concentration_high') }}</span>
          <span v-else-if="concentrationLevel === 'medium'">{{ t('stats.concentration_medium') }}</span>
          <span v-else>{{ t('stats.concentration_ok') }}</span>
        </div>
      </div>

      <!-- Histogram doby úhrady + DPH rozpad (vedle sebe) -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-if="summary.payment_days_histogram.total > 0" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.payment_histogram_title') }}</h3>
            <span class="text-xs text-neutral-500">
              {{ t('stats.payment_histogram_avg', { days: summary.payment_days_histogram.avg_days, n: summary.payment_days_histogram.total }) }}
            </span>
          </div>
          <PaymentDaysHistogramChart :buckets="summary.payment_days_histogram.buckets" />
        </div>

        <div v-if="isVatPayer && summary.vat_breakdown_12m.length"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.vat_breakdown_title') }}</h3>
            <span class="text-xs font-mono text-neutral-500">{{ primaryCurrency }} · {{ t('stats.last_12_months') }}</span>
          </div>
          <VatBreakdownChart :items="summary.vat_breakdown_12m" :currency="primaryCurrency" />
        </div>
      </div>

      <!-- Cash-flow YTD — kumulativní křivka inkasovaných plateb -->
      <div v-if="summary.cashflow_ytd.length" class="space-y-4">
        <div v-for="cf in summary.cashflow_ytd" :key="`cf-${cf.currency}`"
          class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.cashflow_title', { currency: cf.currency }) }}</h3>
            <span class="text-xs text-neutral-400">{{ t('stats.cashflow_hint') }}</span>
          </div>
          <CumulativeYtdChart :months="cf.months" :prev-year="cf.prev_year" :currency="cf.currency" />
        </div>
      </div>

      <!-- Aging report — stáří pohledávek -->
      <div v-if="agingRows.length" class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.aging_title') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('stats.aging_hint') }}</span>
        </div>
        <AgingChart :rows="agingRows" :format="(v, c) => formatMoney(v, c)" />
        <!-- Numerická tabulka pod grafem -->
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="text-left px-3 py-1.5 font-medium">{{ t('common.currency') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('stats.aging_current') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('stats.aging_1_30') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('stats.aging_31_60') }}</th>
                <th class="text-right px-3 py-1.5 font-medium">{{ t('stats.aging_61_90') }}</th>
                <th class="text-right px-3 py-1.5 font-medium text-danger-500">{{ t('stats.aging_90_plus') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in agingRows" :key="`ag-${r.currency}`">
                <td class="px-3 py-1.5 font-mono">{{ r.currency }}</td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.current, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.current_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b1_30, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b1_30_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b31_60, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b31_60_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono">
                  <div>{{ formatMoney(r.b61_90, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b61_90_n }}×</div>
                </td>
                <td class="px-3 py-1.5 text-right font-mono"
                    :class="r.b90_plus > 0 ? 'text-danger-500 font-semibold' : ''">
                  <div>{{ formatMoney(r.b90_plus, r.currency) }}</div>
                  <div class="text-neutral-400">{{ r.b90_plus_n }}×</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Distribuce velikosti faktur -->
      <div v-if="summary.invoice_size_histogram.total > 0"
        class="bg-white border border-neutral-200 rounded-lg p-5 shadow-sm">
        <div class="flex items-baseline justify-between mb-3">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stats.invoice_size_title') }}</h3>
          <span class="text-xs text-neutral-400">{{ t('stats.invoice_size_hint', { n: summary.invoice_size_histogram.total }) }}</span>
        </div>
        <InvoiceSizeChart :buckets="summary.invoice_size_histogram.buckets" />
      </div>
    </div>
  </div>
</template>
