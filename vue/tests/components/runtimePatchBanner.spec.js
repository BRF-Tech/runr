import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import { runtimePatchesApi } from '@/api/runtimePatches'
import { useAuthStore } from '@/stores/auth'
import { useNotificationStore } from '@/stores/notifications'
import RuntimePatchBanner from '@/components/RuntimePatchBanner.vue'
import { withPlugins } from '../helpers'

const push = vi.fn()
vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push }), useRoute: () => ({ query: {} }) }))

const find = (w, id) => w.find(`[data-test="${id}"]`)
const flush = async () => { for (let i = 0; i < 4; i++) await new Promise(r => setTimeout(r, 0)) }
const ok = (data) => Promise.resolve({ data })

const ZERO_DAY = {
  kind: 'runtime_patch', level: 'zero-day', overdue: false, project_count: 2,
  patch: { id: 'php-8.3-8.3.36-r2', runtime: 'php', version: '8.3', tag: '8.3.36-r2', reason: 'Remote code execution.', reasons: { en: 'Remote code execution.' }, deadline_at: '2026-10-23T08:00:00Z' },
  projects: [{ id: 'p1', name: 'shop' }, { id: 'p2', name: 'billing' }],
}
const ADVISORY = { kind: 'platform_advisory', level: 'security', running: '0.5.10', version: '0.5.11', reason: 'Fixes a session bug.', url: 'https://example.test/0.5.11' }

function mountBanner() {
  const plugins = withPlugins()
  useAuthStore().token = 'token'
  return mount(RuntimePatchBanner, { global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
}

describe('RuntimePatchBanner', () => {
  beforeEach(() => {
    push.mockReset()
    sessionStorage.clear()
  })
  afterEach(() => {
    vi.restoreAllMocks()
    vi.useRealTimers()
  })

  it('shows a zero-day patch the user\'s projects run behind; it cannot be dismissed', async () => {
    vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [ZERO_DAY] }))
    const w = mountBanner()
    await flush()

    const banner = find(w, 'runtime-patch-banner-runtime_patch')
    expect(banner.text()).toContain('Zero-day runtime patch 8.3.36-r2 for php 8.3: Remote code execution.')
    expect(banner.text()).toContain('Projects behind it (2): shop, billing')
    expect(find(w, 'runtime-patch-banner-dismiss-runtime_patch').exists()).toBe(false)

    // dismiss() refuses as well
    w.vm.dismiss(ZERO_DAY)
    await flush()
    expect(find(w, 'runtime-patch-banner-runtime_patch').exists()).toBe(true)

    await find(w, 'runtime-patch-banner-open').trigger('click')
    expect(push).toHaveBeenCalledWith('/projects')
  })

  it('a platform advisory can be dismissed for the session', async () => {
    vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [ADVISORY] }))
    const w = mountBanner()
    await flush()

    expect(find(w, 'runtime-patch-banner-platform_advisory').text()).toContain('RunR 0.5.11 available (Security): Fixes a session bug.')
    await find(w, 'runtime-patch-banner-dismiss-platform_advisory').trigger('click')
    expect(find(w, 'runtime-patch-banner-platform_advisory').exists()).toBe(false)
    expect(JSON.parse(sessionStorage.getItem('runr.runtimePatchBanners.dismissed'))).toEqual(['platform_advisory:0.5.11'])
  })

  it('reads the banners again when a runtime patch notification arrives and every five minutes', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    const banners = vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [] }))
    const w = mountBanner()
    await flush()
    expect(banners).toHaveBeenCalledTimes(1)
    expect(find(w, 'runtime-patch-banners').exists()).toBe(false)

    // Another notification type changes nothing
    useNotificationStore().addNotification({ id: 'n1', type: 'build.completed', data: {} })
    await flush()
    expect(banners).toHaveBeenCalledTimes(1)

    banners.mockReturnValue(ok({ data: [ZERO_DAY] }))
    useNotificationStore().addNotification({ id: 'n2', type: 'runtime_patch', data: { event: 'patch' } })
    await flush()
    expect(banners).toHaveBeenCalledTimes(2)
    expect(find(w, 'runtime-patch-banner-runtime_patch').exists()).toBe(true)

    // Resolved: the projects were migrated, the API stops returning it
    banners.mockReturnValue(ok({ data: [] }))
    vi.advanceTimersByTime(5 * 60 * 1000)
    await flush()
    expect(banners).toHaveBeenCalledTimes(3)
    expect(find(w, 'runtime-patch-banners').exists()).toBe(false)
    w.unmount()
  })

  it('signed out: asks for nothing', async () => {
    const banners = vi.spyOn(runtimePatchesApi, 'banners')
    const plugins = withPlugins()
    useAuthStore().token = null
    mount(RuntimePatchBanner, { global: { ...plugins.global, stubs: { RouterLink: RouterLinkStub } } })
    await flush()
    expect(banners).not.toHaveBeenCalled()
  })
})
