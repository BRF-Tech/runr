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
// The console certificate as GET /runtime-patches/banners returns it to platform administrators (ConsoleCertificate::banner)
const CONSOLE_TLS = {
  kind: 'console_tls', level: 'critical', minutes: null,
  mode: 'letsencrypt', issuer: 'letsencrypt-prod', secret: 'runr-tls', namespace: 'runr',
  state: 'failed', reason: 'issuer_not_found',
  message: 'Referenced "ClusterIssuer" not found: clusterissuer.cert-manager.io "letsencrypt-prod" not found',
  since: '2026-11-06T22:47:36+00:00', renewal: false, default_certificate: true, not_after: null,
  checked_at: '2026-11-07T08:00:00+00:00',
}

function mountBanner(locale = 'en') {
  const plugins = withPlugins(locale)
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

  it('a console certificate without its ClusterIssuer: why, the default certificate, what to do; dismissible for the session', async () => {
    vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [CONSOLE_TLS] }))
    const w = mountBanner()
    await flush()

    const banner = find(w, 'runtime-patch-banner-console_tls')
    expect(banner.classes()).toContain('bg-red-900')
    expect(find(w, 'console-tls-banner-text').text()).toBe(
      "The console certificate cannot be issued: ClusterIssuer letsencrypt-prod was not found. The console serves the ingress controller's default certificate.",
    )
    expect(find(w, 'console-tls-banner-fix').text()).toBe(
      'Create the ClusterIssuer letsencrypt-prod, set ingress.tls.clusterIssuer to an existing one, or use ingress.tls.mode custom or none.',
    )
    expect(banner.text()).toContain('kubectl get certificate,certificaterequest -n runr')

    await find(w, 'runtime-patch-banner-dismiss-console_tls').trigger('click')
    expect(find(w, 'runtime-patch-banner-console_tls').exists()).toBe(false)
    expect(JSON.parse(sessionStorage.getItem('runr.runtimePatchBanners.dismissed'))).toEqual(['console_tls:failed:issuer_not_found:letsencrypt-prod'])
  })

  it('the console certificate warning in Turkish', async () => {
    vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [CONSOLE_TLS] }))
    const w = mountBanner('tr')
    await flush()

    expect(find(w, 'console-tls-banner-text').text()).toBe(
      'Konsol sertifikası verilemiyor: ClusterIssuer letsencrypt-prod bulunamadı. Konsol ingress denetleyicisinin varsayılan sertifikasını sunuyor.',
    )
    expect(find(w, 'console-tls-banner-fix').text()).toBe(
      'ClusterIssuer letsencrypt-prod oluşturun, ingress.tls.clusterIssuer değerini var olan bir ClusterIssuer yapın ya da ingress.tls.mode için custom veya none kullanın.',
    )
    expect(find(w, 'runtime-patch-banner-console_tls').text()).toContain('Denetim: kubectl get certificate,certificaterequest -n runr')
  })

  it('a console certificate stuck pending, and a failed renewal, take the message of cert-manager', async () => {
    const pending = {
      ...CONSOLE_TLS, state: 'pending', reason: 'issuing', minutes: 42,
      message: 'Waiting on certificate issuance from order runr/runr-tls-1-123: "pending".',
    }
    const renewal = {
      ...CONSOLE_TLS, level: 'security', reason: 'request_failed', issuer: 'acme', renewal: true, default_certificate: false,
      message: 'Failed to wait for order resource "runr-tls-2-456" to become ready: order is in "invalid" state.',
      not_after: '2026-12-01T10:00:00Z',
    }
    vi.spyOn(runtimePatchesApi, 'banners').mockReturnValue(ok({ data: [pending] }))
    const w = mountBanner()
    await flush()
    expect(find(w, 'console-tls-banner-text').text()).toBe(
      'The console certificate has not been issued for 42 minutes: Waiting on certificate issuance from order runr/runr-tls-1-123: "pending". '
      + "The console serves the ingress controller's default certificate.",
    )
    expect(find(w, 'console-tls-banner-fix').text()).toBe('Set ingress.tls.clusterIssuer to another ClusterIssuer, or use ingress.tls.mode custom or none.')

    runtimePatchesApi.banners.mockReturnValue(ok({ data: [renewal] }))
    await w.vm.load()
    await flush()
    const text = find(w, 'console-tls-banner-text').text()
    expect(text).toContain('The console certificate cannot be renewed: Failed to wait for order resource "runr-tls-2-456" to become ready: order is in "invalid" state. The current one is valid until ')
    expect(text).toContain(new Date('2026-12-01T10:00:00Z').toLocaleString())
    expect(text).not.toContain('default certificate')
    expect(find(w, 'runtime-patch-banner-console_tls').classes()).toContain('bg-yellow-500')
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
