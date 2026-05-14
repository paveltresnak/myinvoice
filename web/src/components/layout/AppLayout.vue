<script setup lang="ts">
import { ref, watch, computed, onMounted } from 'vue'
import { RouterLink, RouterView, useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { updateApi, type PublicVersion } from '@/api/update'
import SupplierSwitcher from './SupplierSwitcher.vue'

const { t, locale } = useI18n()
function setLocale(l: 'cs' | 'en') {
  locale.value = l
  localStorage.setItem('locale', l)
}

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const supplierStore = useSupplierStore()

const mobileOpen = ref(false)
const settingsOpen = ref(false)
const mobileSubOpen = ref<Record<string, boolean>>({})

function toggleMobileSub(key: string) {
  mobileSubOpen.value[key] = !mobileSubOpen.value[key]
}

async function logout() {
  await auth.logout()
  router.push('/login')
}

interface NavItem {
  to: string
  label: string
  icon: string  // SVG path 'd' attribute
  children?: NavItem[]
}

const navItems = computed<NavItem[]>(() => {
  const items: NavItem[] = [
    { to: '/',         label: t('nav.dashboard'),  icon: 'M3 12l9-9 9 9M5 10v10h14V10' },
    { to: '/invoices', label: t('nav.invoices'),   icon: 'M9 12h6m-6 4h6m2 5H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5.586a1 1 0 0 1 .707.293l5.414 5.414a1 1 0 0 1 .293.707V19a2 2 0 0 1-2 2z' },
    { to: '/clients',  label: t('nav.clients'),    icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z' },
    { to: '/projects', label: t('nav.projects'),   icon: 'M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4' },
    { to: '/stats',    label: t('nav.stats'),      icon: 'M3 3v18h18M7 14l4-4 4 4 5-5' },
  ]
  if (auth.user?.role === 'admin') {
    items.push({
      to: '/admin', label: t('nav.system'),
      icon: 'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
      children: [
        { to: '/admin/settings',     label: t('nav.settings'),   icon: 'M10.325 4.317a1 1 0 0 1 1.94 0l.31 1.241a7.5 7.5 0 0 1 2.106.873l1.097-.633a1 1 0 0 1 1.371.366l.97 1.683a1 1 0 0 1-.366 1.366l-1.094.632a7.5 7.5 0 0 1 0 2.428l1.094.632a1 1 0 0 1 .366 1.366l-.97 1.683a1 1 0 0 1-1.371.366l-1.097-.633a7.5 7.5 0 0 1-2.106.873l-.31 1.241a1 1 0 0 1-1.94 0l-.31-1.241a7.5 7.5 0 0 1-2.106-.873l-1.097.633a1 1 0 0 1-1.371-.366l-.97-1.683a1 1 0 0 1 .366-1.366l1.094-.632a7.5 7.5 0 0 1 0-2.428l-1.094-.632a1 1 0 0 1-.366-1.366l.97-1.683a1 1 0 0 1 1.371-.366l1.097.633a7.5 7.5 0 0 1 2.106-.873l.31-1.241zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z' },
        { to: '/admin/suppliers',    label: t('nav.suppliers'),  icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0zM23 11a4 4 0 1 1-8 0 4 4 0 0 1 8 0z' },
        { to: '/bank',               label: t('nav.bank'),       icon: 'M3 9l9-7 9 7m-2 0v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V9m4 11V13h4v7' },
        { to: '/admin/codebooks',    label: t('nav.codebooks'),  icon: 'M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10' },
        { to: '/recurring',          label: t('nav.recurring'),  icon: 'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06' },
        { to: '/admin/export',       label: t('nav.exports'),    icon: 'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4' },
        { to: '/admin/import',       label: t('nav.imports'),    icon: 'M4 16v1a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3v-1m-4-8l-4-4m0 0l-4 4m4-4v12' },
        { to: '/admin/users',        label: t('nav.users'),      icon: 'M17 20h5v-2a4 4 0 0 0-3-3.87M9 20H4v-2a3 3 0 0 1 5.356-1.857M15 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0z' },
        { to: '/admin/email-templates', label: t('nav.email_templates'), icon: 'M3 8l7.89 5.26a2 2 0 0 0 2.22 0L21 8M5 19h14a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2z' },
        { to: '/admin/approvals',    label: t('nav.approvals'),  icon: 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0 1 12 2.944a11.955 11.955 0 0 1-8.618 3.04A12.02 12.02 0 0 0 3 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z' },
        { to: '/admin/activity-log', label: t('nav.log'),        icon: 'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6m-6 4h4' },
        { to: '/admin/update',       label: t('nav.updates'),    icon: 'M4 4v5h5M4 9a8 8 0 0 1 14.13-4.06M20 20v-5h-5M20 15a8 8 0 0 1-14.13 4.06' },
        { to: '/profile/api-tokens', label: t('nav.api_tokens'), icon: 'M15 7a2 2 0 0 1 2 2m4 0a6 6 0 0 1-7.743 5.743L11 17H9v2H7v2H4a1 1 0 0 1-1-1v-2.586a1 1 0 0 1 .293-.707l5.964-5.964A6 6 0 1 1 21 9z' },
      ],
    })
  }
  return items
})

function isActive(to: string) {
  if (to === '/') return route.path === '/'
  // Systém top-level zahrnuje i Banku (přesunutá do admin submenu).
  if (to === '/admin') return route.path.startsWith('/admin') || route.path.startsWith('/bank')
  return route.path.startsWith(to)
}

// Při změně route zavři mobile menu + dropdown
watch(() => route.path, () => {
  mobileOpen.value = false
  settingsOpen.value = false
})

// Klik mimo dropdown ho zavře
function onClickOutside() { settingsOpen.value = false }

// Verze aplikace + indikátor dostupné aktualizace ve footru.
// /api/version je public a cached (žádný blocking GitHub call per request).
const versionInfo = ref<PublicVersion | null>(null)
onMounted(async () => {
  try {
    versionInfo.value = await updateApi.publicVersion()
  } catch {
    // Bez verze se obejde — nezablokujeme layout, jen footer ukáže "v?".
  }
})
</script>

<template>
  <div class="min-h-screen flex flex-col bg-neutral-50">
    <header class="border-b border-neutral-200 bg-white sticky top-0 z-20">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
        <div class="flex items-center gap-6 min-w-0">
          <RouterLink to="/" class="flex items-center gap-3 shrink-0" @click="mobileOpen = false">
            <img src="/styles/logo.svg" alt="MyInvoice" class="w-9 h-9" />
            <div>
              <div class="text-base font-semibold leading-tight">
                My<span class="text-primary-600">Invoice</span><span class="text-neutral-400 font-normal">.cz</span>
              </div>
            </div>
          </RouterLink>

          <!-- Desktop nav (skryté pod 1120px, jinak by se slévalo s user info / odhlásit) -->
          <nav class="hidden min-[1120px]:flex items-center gap-0.5">
            <template v-for="item in navItems" :key="item.to">
              <!-- Top-level link bez submenu -->
              <RouterLink v-if="!item.children" :to="item.to"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md transition"
                :class="isActive(item.to)
                  ? 'bg-primary-50 text-primary-700 font-medium'
                  : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
              </RouterLink>
              <!-- Top-level s dropdown -->
              <div v-else class="relative">
                <button type="button" @click="settingsOpen = !settingsOpen"
                  class="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm rounded-md transition cursor-pointer"
                  :class="isActive(item.to)
                    ? 'bg-primary-50 text-primary-700 font-medium'
                    : 'text-neutral-600 hover:text-neutral-900 hover:bg-neutral-100'">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                  </svg>
                  {{ item.label }}
                  <svg class="w-3 h-3 ml-0.5 transition" :class="{ 'rotate-180': settingsOpen }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
                  </svg>
                </button>
                <transition
                  enter-active-class="transition duration-100 ease-out"
                  enter-from-class="opacity-0 scale-95"
                  enter-to-class="opacity-100 scale-100"
                  leave-active-class="transition duration-75 ease-in"
                  leave-from-class="opacity-100 scale-100"
                  leave-to-class="opacity-0 scale-95"
                >
                  <div v-if="settingsOpen" class="absolute right-0 mt-1 w-52 bg-white border border-neutral-200 rounded-lg shadow-lg py-1 z-40">
                    <RouterLink v-for="child in item.children" :key="child.to" :to="child.to"
                      class="flex items-center gap-2 px-3 py-2 text-sm transition"
                      :class="isActive(child.to)
                        ? 'bg-primary-50 text-primary-700 font-medium'
                        : 'text-neutral-700 hover:bg-neutral-50'">
                      <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="child.icon" />
                      </svg>
                      {{ child.label }}
                    </RouterLink>
                  </div>
                </transition>
              </div>
            </template>
          </nav>
        </div>

        <div class="flex items-center gap-3 text-sm">
          <!-- Desktop user info — pouze jméno (role je viditelná v Systém menu / mobile drawer) -->
          <RouterLink to="/profile/totp" class="text-neutral-700 hover:text-primary-700 hover:underline hidden lg:inline" :title="t('auth.totp_2fa')">{{ auth.user?.name }}</RouterLink>
          <!-- Locale switcher s SVG vlajkami CZ / UK (emoji vlajky nejsou na Windows) -->
          <div class="hidden sm:inline-flex items-center border border-neutral-200 rounded-md overflow-hidden">
            <button @click="setLocale('cs')" :title="'Čeština'" :aria-label="'Čeština'"
              class="cursor-pointer h-8 px-2 inline-flex items-center"
              :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'">
              <!-- Vlajka ČR -->
              <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                <rect width="6" height="2" fill="#ffffff"/>
                <rect y="2" width="6" height="2" fill="#d7141a"/>
                <polygon points="0,0 3,2 0,4" fill="#11457e"/>
              </svg>
            </button>
            <button @click="setLocale('en')" :title="'English'" aria-label="English"
              class="cursor-pointer h-8 px-2 inline-flex items-center border-l border-neutral-200"
              :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60 hover:grayscale-0 hover:opacity-100'">
              <!-- Vlajka UK (Union Jack) -->
              <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                <clipPath id="t"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#t)" stroke="#C8102E" stroke-width="4"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
              </svg>
            </button>
          </div>
          <!-- Nápověda — odkaz na HTML manuál (nový tab) -->
          <a
            href="/manual"
            target="_blank"
            rel="noopener"
            class="hidden sm:inline-flex w-8 h-8 items-center justify-center rounded-md text-neutral-700 hover:bg-neutral-100 hover:text-primary-700"
            :title="t('nav.help')"
            :aria-label="t('nav.help')"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </a>
          <button
            @click="logout"
            class="cursor-pointer hidden sm:inline-flex px-3 h-8 items-center text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-neutral-50"
          >
            {{ t('nav.logout') }}
          </button>

          <!-- Mobile hamburger -->
          <button
            type="button"
            @click="mobileOpen = !mobileOpen"
            :aria-expanded="mobileOpen"
            aria-label="Menu"
            class="min-[1120px]:hidden inline-flex items-center justify-center w-10 h-10 rounded-md text-neutral-700 hover:bg-neutral-100 focus:outline-none focus:ring-2 focus:ring-primary-500/30"
          >
            <svg v-if="!mobileOpen" xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>

      <!-- Mobile drawer -->
      <transition
        enter-active-class="transition duration-150 ease-out"
        enter-from-class="opacity-0 -translate-y-2"
        enter-to-class="opacity-100 translate-y-0"
        leave-active-class="transition duration-100 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
      >
        <div v-if="mobileOpen" class="min-[1120px]:hidden absolute left-0 right-0 top-full border-t border-neutral-200 bg-white shadow-lg z-30 max-h-[calc(100vh-4rem)] overflow-y-auto">
          <nav class="px-3 py-2 flex flex-col">
            <template v-for="item in navItems" :key="item.to">
              <RouterLink v-if="!item.children" :to="item.to"
                class="inline-flex items-center gap-2 px-3 py-2.5 text-base rounded-md transition"
                :class="isActive(item.to)
                  ? 'bg-primary-50 text-primary-700 font-medium'
                  : 'text-neutral-700 hover:bg-neutral-100'">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                </svg>
                {{ item.label }}
              </RouterLink>
              <!-- Mobile: collapsible sub-menu -->
              <template v-else>
                <button type="button" @click="toggleMobileSub(item.to)"
                  class="cursor-pointer w-full inline-flex items-center justify-between gap-2 px-3 py-2.5 text-base rounded-md transition"
                  :class="isActive(item.to)
                    ? 'bg-primary-50 text-primary-700 font-medium'
                    : 'text-neutral-700 hover:bg-neutral-100'">
                  <span class="inline-flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" />
                    </svg>
                    {{ item.label }}
                  </span>
                  <svg class="w-4 h-4 transition" :class="{ 'rotate-180': mobileSubOpen[item.to] }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                </button>
                <div v-show="mobileSubOpen[item.to]" class="ml-4 border-l border-neutral-200 pl-2">
                  <RouterLink v-for="child in item.children" :key="child.to" :to="child.to"
                    class="inline-flex items-center gap-2 px-3 py-2 text-sm rounded-md transition w-full"
                    :class="isActive(child.to)
                      ? 'bg-primary-50 text-primary-700 font-medium'
                      : 'text-neutral-700 hover:bg-neutral-100'">
                    <svg class="w-4 h-4 text-neutral-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                      <path stroke-linecap="round" stroke-linejoin="round" :d="child.icon" />
                    </svg>
                    {{ child.label }}
                  </RouterLink>
                </div>
              </template>
            </template>
          </nav>
          <!-- Mobile: jazyk + nápověda + odhlásit (desktop verze je v topbar, ale ta je <1120px schovaná) -->
          <div class="border-t border-neutral-100 px-4 py-3 bg-neutral-50 space-y-3">
            <div class="flex items-center justify-between">
              <div class="text-sm">
                <div class="font-medium text-neutral-900">{{ auth.user?.name }}</div>
                <div class="text-xs text-neutral-500">{{ auth.user?.email }} · {{ auth.user?.role }}</div>
              </div>
              <a href="/manual" target="_blank" rel="noopener"
                class="inline-flex w-9 h-9 items-center justify-center rounded-md text-neutral-700 hover:bg-white"
                :title="t('nav.help')" :aria-label="t('nav.help')">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827V14m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </a>
            </div>
            <div class="flex items-center justify-between gap-3">
              <div class="inline-flex items-center border border-neutral-200 bg-white rounded-md overflow-hidden">
                <button @click="setLocale('cs')" :title="'Čeština'" :aria-label="'Čeština'"
                  class="cursor-pointer h-9 px-3 inline-flex items-center"
                  :class="locale === 'cs' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'">
                  <svg width="22" height="15" viewBox="0 0 6 4" xmlns="http://www.w3.org/2000/svg">
                    <rect width="6" height="2" fill="#ffffff"/>
                    <rect y="2" width="6" height="2" fill="#d7141a"/>
                    <polygon points="0,0 3,2 0,4" fill="#11457e"/>
                  </svg>
                </button>
                <button @click="setLocale('en')" :title="'English'" aria-label="English"
                  class="cursor-pointer h-9 px-3 inline-flex items-center border-l border-neutral-200"
                  :class="locale === 'en' ? 'bg-primary-50' : 'hover:bg-neutral-50 grayscale opacity-60'">
                  <svg width="22" height="15" viewBox="0 0 60 30" xmlns="http://www.w3.org/2000/svg">
                    <clipPath id="t-mob"><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z"/></clipPath>
                    <path d="M0,0 v30 h60 v-30 z" fill="#012169"/>
                    <path d="M0,0 L60,30 M60,0 L0,30" stroke="#fff" stroke-width="6"/>
                    <path d="M0,0 L60,30 M60,0 L0,30" clip-path="url(#t-mob)" stroke="#C8102E" stroke-width="4"/>
                    <path d="M30,0 v30 M0,15 h60" stroke="#fff" stroke-width="10"/>
                    <path d="M30,0 v30 M0,15 h60" stroke="#C8102E" stroke-width="6"/>
                  </svg>
                </button>
              </div>
              <button
                @click="logout"
                class="cursor-pointer px-4 h-9 text-sm border border-neutral-300 rounded-md text-neutral-700 hover:bg-white"
              >
                {{ t('nav.logout') }}
              </button>
            </div>
          </div>
        </div>
      </transition>
    </header>

    <!-- Klik mimo dropdown / mobile menu zavře -->
    <!-- Backdrop pro desktop dropdown — z-10 (pod headerem z-20), aby neblokoval dropdown items -->
    <div v-if="settingsOpen" @click="onClickOutside" class="fixed inset-0 z-10 hidden md:block" aria-hidden="true"></div>
    <div v-if="mobileOpen" @click="mobileOpen = false" class="fixed inset-0 bg-neutral-900/20 z-10 min-[1120px]:hidden" aria-hidden="true"></div>

    <!-- Active supplier banner (jen když máme víc supplierů) -->
    <div v-if="supplierStore.hasMultiple && supplierStore.currentSupplier" class="bg-primary-50 border-b border-primary-100">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 py-1.5 text-xs text-primary-700 flex items-center gap-2">
        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 0 0-2-2H7a2 2 0 0 0-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v5m-4 0h4"/></svg>
        <span class="flex-1 min-w-0 truncate">{{ t('supplier.active_label') }}: <strong class="font-semibold">{{ supplierStore.currentSupplier.company_name }}</strong><span v-if="supplierStore.currentSupplier.ic" class="font-mono text-primary-600 ml-1">({{ t('common.ic') }} {{ supplierStore.currentSupplier.ic }})</span></span>
        <SupplierSwitcher />
      </div>
    </div>

    <main class="flex-1 max-w-6xl mx-auto px-4 sm:px-6 py-6 w-full">
      <RouterView />
      <footer class="mt-12 pt-6 border-t border-neutral-200 text-xs text-neutral-500 flex flex-wrap items-center justify-center gap-x-1.5 gap-y-1 leading-none">
        <span>Developed by</span>
        <a href="https://mywebdesign.cz" target="_blank" rel="noopener" class="hover:text-neutral-700">MyWebdesign.cz s.r.o.</a>
        <span aria-hidden="true">·</span>
        <a href="https://github.com/radekhulan/myinvoice" target="_blank" rel="noopener"
          class="inline-flex items-center gap-1 hover:text-neutral-700">
          <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z"/>
          </svg>
          <span>GitHub</span>
        </a>
        <span v-if="versionInfo" aria-hidden="true">·</span>
        <RouterLink v-if="versionInfo && auth.user?.role === 'admin'"
          to="/admin/update"
          class="inline-flex items-center gap-1 hover:text-neutral-700"
          :title="t('updates.title')">
          <span>v{{ versionInfo.current }}</span>
          <span v-if="versionInfo.has_update"
            class="inline-flex items-center gap-1 rounded-full bg-primary-100 text-primary-700 px-1.5 py-0.5 text-[10px] font-semibold leading-none">
            <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6"/></svg>
            v{{ versionInfo.latest }}
          </span>
        </RouterLink>
        <span v-else-if="versionInfo" class="text-neutral-400">v{{ versionInfo.current }}</span>
      </footer>
    </main>
  </div>
</template>
