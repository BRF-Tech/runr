import { describe, it, expect, beforeEach, vi } from 'vitest'

// The guard is what is under test, not the pages: replace the lazy views with stubs.
vi.mock('@/views/LoginView.vue', () => ({ default: { name: 'LoginView', render: () => null } }))
vi.mock('@/views/ProjectsView.vue', () => ({ default: { name: 'ProjectsView', render: () => null } }))
vi.mock('@/views/ProjectDetailView.vue', () => ({ default: { name: 'ProjectDetailView', render: () => null } }))
vi.mock('@/views/SettingsView.vue', () => ({ default: { name: 'SettingsView', render: () => null } }))
vi.mock('@/views/AccountSettingsView.vue', () => ({ default: { name: 'AccountSettingsView', render: () => null } }))

import router from '@/router'

async function go(path) {
  await router.push(path).catch(() => {})
  return router.currentRoute.value
}

beforeEach(async () => {
  // Park on the public login page with no session, so every test starts from the same state.
  localStorage.clear()
  await go('/login')
})

describe('navigation guard', () => {
  it.each(['/projects', '/projects/7', '/settings', '/account'])('sends anonymous visitors from %s to login', async (path) => {
    localStorage.clear()
    const route = await go(path)
    expect(route.name).toBe('login')
  })

  it('remembers the requested page so sign-in can return to it', async () => {
    localStorage.clear()
    const route = await go('/settings?tab=tokens')
    expect(route.name).toBe('login')
    expect(route.query.redirect).toBe('/settings?tab=tokens')
  })

  it('needs no redirect back to the default page', async () => {
    localStorage.clear()
    const route = await go('/projects')
    expect(route.query.redirect).toBeUndefined()
  })

  it('lets a signed-in user open protected pages', async () => {
    localStorage.setItem('token', 'tok')
    const route = await go('/projects/7')
    expect(route.name).toBe('project-detail')
    expect(route.params.id).toBe('7')
  })

  it('moves a signed-in user away from the login page', async () => {
    localStorage.setItem('token', 'tok')
    await go('/settings')
    const route = await go('/login')
    expect(route.name).toBe('projects')
  })

  it('keeps the login page public', async () => {
    await go('/projects')
    const route = await go('/login')
    expect(route.name).toBe('login')
    expect(route.meta.public).toBe(true)
  })

  it('redirects / and unknown paths to the project list', async () => {
    localStorage.setItem('token', 'tok')
    expect((await go('/')).name).toBe('projects')
    await go('/settings')
    expect((await go('/no/such/page')).name).toBe('projects')
  })
})
