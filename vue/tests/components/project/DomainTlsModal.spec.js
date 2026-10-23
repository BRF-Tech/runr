import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@/api/projects', () => ({
  projectsApi: {
    updateDomainTls:      vi.fn(async () => ({ data: { data: { id: 'd1', tls_mode: 'cert-manager' }, message: 'TLS setting updated.' } })),
    domainsCaCertificate: vi.fn(async () => ({ data: new Blob(['pem']), headers: {} })),
  },
}))
const saveBlobMock = vi.fn()
vi.mock('@/modules/supply-chain/api', () => ({ saveBlob: (...args) => saveBlobMock(...args) }))

import { projectsApi } from '@/api/projects'
import DomainTlsModal from '@/components/project/DomainTlsModal.vue'
import { makeI18n, freshPinia } from '@/test-support/helpers'

const i18n = makeI18n()
const mounted = []
const domain = { id: 'd1', domain: 'shop.example.org', tls_mode: 'none', tls_note: 'Served over HTTP.' }

function mountIt(certManager, extra = {}) {
  const w = mount(DomainTlsModal, {
    props: { projectId: 'p1', domain, certManager, ...extra },
    global: { plugins: [freshPinia(), i18n] },
  })
  mounted.push(w)
  return w
}

const option = (w) => w.findAll('label').find(l => l.text().includes('cert-manager'))

beforeEach(() => {
  projectsApi.updateDomainTls.mockClear()
  projectsApi.domainsCaCertificate.mockClear()
  saveBlobMock.mockClear()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Domain → HTTPS certificate → cert-manager', () => {
  it('is not offered while the region cannot issue, and says why and what to do', () => {
    const reason = 'cert-manager is being installed in this region; TLS mode cert-manager is offered as soon as it runs (usually a few minutes).'
    const w = mountIt({ available: false, status: 'installing', issuer: null, kind: null, reason, ca_download: false })

    const opt = option(w)
    expect(opt.text()).toContain(reason)
    expect(opt.find('input').attributes('disabled')).toBeDefined()
  })

  it('a publicly trusted certificate from Let\'s Encrypt', async () => {
    const w = mountIt({ available: true, status: 'ready', issuer: 'runr-acme', kind: 'acme', reason: null, ca_download: false })

    expect(option(w).text()).toContain('publicly trusted certificate from Let\'s Encrypt')
    await option(w).find('input').setValue(true)
    expect(w.find('[data-testid="domain-download-ca"]').exists()).toBe(false)

    await w.findAll('button').find(b => b.text() === 'Save').trigger('click')
    await flushPromises()
    expect(projectsApi.updateDomainTls).toHaveBeenCalledWith('p1', 'd1', { mode: 'cert-manager' })
  })

  it('the region CA: offers its download', async () => {
    const w = mountIt({ available: true, status: 'ready', issuer: 'runr-ca', kind: 'ca', reason: null, ca_download: true })

    expect(option(w).text()).toContain('region\'s own CA')
    await option(w).find('input').setValue(true)
    await w.find('[data-testid="domain-download-ca"]').trigger('click')
    await flushPromises()

    expect(projectsApi.domainsCaCertificate).toHaveBeenCalledWith('p1')
    expect(saveBlobMock).toHaveBeenCalledWith(expect.anything(), 'runr-region-ca.crt')
  })

  it('works with the issuer alone (an older API answer)', () => {
    const w = mountIt(null, { certManagerIssuer: 'letsencrypt-prod' })

    expect(option(w).text()).toContain('letsencrypt-prod')
    expect(option(w).find('input').attributes('disabled')).toBeUndefined()
  })
})
