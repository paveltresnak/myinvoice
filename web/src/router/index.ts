import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: () => import('@/components/layout/AppLayout.vue'),
    meta: { requiresAuth: true },
    children: [
      { path: '',                       name: 'home',           component: () => import('@/pages/Dashboard.vue') },
      { path: 'clients',                name: 'clients',        component: () => import('@/pages/clients/ClientList.vue') },
      { path: 'clients/new',            name: 'client-new',     component: () => import('@/pages/clients/ClientForm.vue') },
      { path: 'clients/:id(\\d+)',      name: 'client-detail',  component: () => import('@/pages/clients/ClientDetail.vue') },
      { path: 'clients/:id(\\d+)/edit', name: 'client-edit',    component: () => import('@/pages/clients/ClientForm.vue') },
      { path: 'projects',               name: 'projects',       component: () => import('@/pages/projects/ProjectList.vue') },
      { path: 'projects/new',           name: 'project-new',    component: () => import('@/pages/projects/ProjectForm.vue') },
      { path: 'projects/:id(\\d+)',     name: 'project-detail', component: () => import('@/pages/projects/ProjectDetail.vue') },
      { path: 'projects/:id(\\d+)/edit', name: 'project-edit',  component: () => import('@/pages/projects/ProjectForm.vue') },
      { path: 'invoices',               name: 'invoices',       component: () => import('@/pages/invoices/InvoiceList.vue') },
      { path: 'invoices/new',           name: 'invoice-new',    component: () => import('@/pages/invoices/InvoiceEditor.vue') },
      { path: 'invoices/:id(\\d+)',     name: 'invoice-detail', component: () => import('@/pages/invoices/InvoiceDetail.vue') },
      { path: 'invoices/:id(\\d+)/edit', name: 'invoice-edit',  component: () => import('@/pages/invoices/InvoiceEditor.vue') },
      { path: 'stats',                  name: 'stats',           component: () => import('@/pages/Stats.vue') },
      { path: 'bank',                   name: 'bank-statements', component: () => import('@/pages/bank/StatementList.vue') },
      { path: 'bank/:id(\\d+)',         name: 'bank-detail',     component: () => import('@/pages/bank/StatementDetail.vue') },
      // Admin (M6)
      { path: 'admin/activity-log',     name: 'activity-log',   component: () => import('@/pages/admin/ActivityLog.vue'), meta: { adminOnly: true } },
      { path: 'admin/users',            name: 'admin-users',    component: () => import('@/pages/admin/Users.vue'),       meta: { adminOnly: true } },
      { path: 'admin/settings',         name: 'admin-settings', component: () => import('@/pages/admin/Settings.vue'),    meta: { adminOnly: true } },
      { path: 'admin/suppliers',        name: 'admin-suppliers', component: () => import('@/pages/admin/Suppliers.vue'), meta: { adminOnly: true } },
      { path: 'admin/codebooks',        name: 'admin-codebooks', component: () => import('@/pages/admin/Codebooks.vue'),  meta: { adminOnly: true } },
      { path: 'admin/export',           name: 'admin-export',    component: () => import('@/pages/admin/Export.vue'),     meta: { adminOnly: true } },
      { path: 'admin/import',           name: 'admin-import',    component: () => import('@/pages/admin/Imports.vue'),    meta: { adminOnly: true } },
      { path: 'admin/email-templates',  name: 'admin-email-templates', component: () => import('@/pages/admin/EmailTemplates.vue'), meta: { adminOnly: true } },
      { path: 'admin/approvals',        name: 'admin-approvals', component: () => import('@/pages/admin/Approvals.vue'), meta: { adminOnly: true } },
      { path: 'recurring',              name: 'recurring',        component: () => import('@/pages/recurring/RecurringList.vue') },
      { path: 'recurring/new',          name: 'recurring-new',    component: () => import('@/pages/recurring/RecurringForm.vue') },
      { path: 'recurring/:id(\\d+)',    name: 'recurring-detail', component: () => import('@/pages/recurring/RecurringDetail.vue') },
      { path: 'recurring/:id(\\d+)/edit', name: 'recurring-edit', component: () => import('@/pages/recurring/RecurringForm.vue') },
      { path: 'admin/update',           name: 'admin-update',    component: () => import('@/pages/admin/Update.vue'),    meta: { adminOnly: true } },
      { path: 'profile/totp',           name: 'profile-totp',          component: () => import('@/pages/TotpSetup.vue') },
      { path: 'profile/api-tokens',     name: 'profile-api-tokens',    component: () => import('@/pages/ApiTokens.vue') },
    ],
  },
  { path: '/login',  name: 'login',  component: () => import('@/pages/Login.vue'),          meta: { public: true } },
  { path: '/setup',  name: 'setup',  component: () => import('@/pages/Setup.vue'),          meta: { public: true } },
  { path: '/setup-totp', name: 'setup-totp', component: () => import('@/pages/ForcedTotpSetup.vue'), meta: { requiresAuth: true, totpSetupOnly: true } },
  { path: '/forgot', name: 'forgot', component: () => import('@/pages/ForgotPassword.vue'), meta: { public: true } },
  { path: '/reset',  name: 'reset',  component: () => import('@/pages/ResetPassword.vue'),  meta: { public: true } },
  { path: '/approval/:token([a-f0-9]{32,128})', name: 'approval',
    component: () => import('@/pages/ApprovalPublic.vue'), meta: { public: true } },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/pages/NotFound.vue'),
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.setupStatus === null) {
    try {
      await auth.fetchSetupStatus()
    } catch {
      // ignore
    }
  }

  if (auth.needsSetup && to.name !== 'setup') {
    return { name: 'setup' }
  }
  if (!auth.needsSetup && to.name === 'setup') {
    return { name: 'login' }
  }

  const requiresAuth = to.matched.some((r) => r.meta.requiresAuth)
  if (requiresAuth && !auth.isAuthenticated) {
    const ok = await auth.refresh()
    if (!ok) return { name: 'login' }
  }

  // Vynucení 2FA: pokud cfg.auth.require_totp = true a uživatel nemá TOTP,
  // přesměruj všechny privátní routes na /setup-totp (kromě samotné /setup-totp).
  if (auth.isAuthenticated && auth.mustSetupTotp && to.name !== 'setup-totp' && requiresAuth) {
    return { name: 'setup-totp' }
  }
  // Naopak když TOTP aktivovaný, /setup-totp už nedává smysl.
  if (auth.isAuthenticated && !auth.mustSetupTotp && to.name === 'setup-totp') {
    return { name: 'home' }
  }

  // Admin-only stránky
  const adminOnly = to.matched.some((r) => r.meta.adminOnly)
  if (adminOnly && auth.user?.role !== 'admin') {
    return { name: 'home' }
  }

  return true
})
