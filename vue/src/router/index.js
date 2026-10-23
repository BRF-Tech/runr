import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true },
  },
  {
    path: '/',
    redirect: '/projects',
  },
  {
    path: '/projects',
    name: 'projects',
    component: () => import('@/views/ProjectsView.vue'),
  },
  {
    path: '/projects/:id',
    name: 'project-detail',
    component: () => import('@/views/ProjectDetailView.vue'),
  },
  {
    path: '/models',
    name: 'models',
    component: () => import('@/views/ModelsView.vue'),
  },
  // The approvals inbox: promotions waiting for an approver (docs/environments.md#approvals)
  {
    path: '/approvals',
    name: 'approvals',
    component: () => import('@/views/ApprovalsView.vue'),
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
  },
  {
    path: '/account',
    name: 'account',
    component: () => import('@/views/AccountSettingsView.vue'),
  },
  { path: '/sandboxes', name: 'sandboxes', component: () => import('@/modules/sandboxes/views/SandboxesView.vue') },
  // The organization's rightsizing recommendations (docs/monitoring.md#rightsizing)
  { path: '/rightsizing', name: 'rightsizing', component: () => import('@/views/RightsizingView.vue') },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/projects',
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) return savedPosition
    // A hash names an anchor on the page, or a tab (#git, #domains …): only anchors scroll
    if (to.hash) return document.getElementById(decodeURIComponent(to.hash.slice(1))) ? { el: to.hash, behavior: 'smooth', top: 80 } : false
    return { top: 0 }
  },
})

// Navigation guard
router.beforeEach((to) => {
  const token = localStorage.getItem('token')
  if (!to.meta.public && !token) {
    // Back to the requested page after sign-in (LoginView reads ?redirect), e.g. the OAuth
    // consent page an MCP client opened
    return to.fullPath && to.fullPath !== '/projects'
      ? { name: 'login', query: { redirect: to.fullPath } }
      : { name: 'login' }
  }
  if (to.name === 'login' && token) {
    return { name: 'projects' }
  }
})

export default router
