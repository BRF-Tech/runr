import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ProjectDomains from '@/components/project/ProjectDomains.vue'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import { withPlugins } from '../../helpers'

// A custom domain of a global project (docs/global-projects.md, "Custom domains"): routed to the
// global host name, its certificate issued in every region through the CNAME of its ACME
// challenge into the global zone, and its state in each region.

vi.mock('@/api/projects', () => ({
  projectsApi: {
    domains: vi.fn(), addDomain: vi.fn(), verifyDomain: vi.fn(), setDomainTls: vi.fn(), deleteDomain: vi.fn(), domainsCaCertificate: vi.fn(),
  },
}))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: vi.fn(() => Promise.resolve(true)) }) }))

function domain(extra = {}) {
  return {
    id: 'd1', project_id: 'p-shop', domain: 'shop.example.com', status: 'pending', status_message: null,
    tls_mode: 'cert-manager', tls_expires_at: null, https_certificate: 'cert-manager', tls_note: 'cert-manager',
    url: 'https://shop.example.com',
    verification: { type: 'TXT', name: '_runr-challenge.shop.example.com', value: 'runr-verify=abc' },
    dns_targets: [
      { type: 'CNAME', name: 'shop.example.com', value: 'shop.g.runr.test' },
      { type: 'A', name: 'shop.example.com', value: '203.0.113.10' },
      { type: 'A', name: 'shop.example.com', value: '198.51.100.10' },
    ],
    acme_challenge: { type: 'CNAME', name: '_acme-challenge.shop.example.com', value: '_acme-challenge.d-0123456789abcdef0123.g.runr.test' },
    regions: {},
    ...extra,
  }
}

async function mountDomains(d = domain()) {
  projectsApi.domains.mockResolvedValue({ data: { data: [d], meta: { eligible: true } } })
  const w = mount(ProjectDomains, { props: { projectId: 'p-shop' }, ...withPlugins() })
  await flushPromises()
  return w
}

beforeEach(() => {
  vi.clearAllMocks()
})

describe('Project → Domains: a global project', () => {
  it('asks for the CNAME of the ACME challenge next to the routing records', async () => {
    const w = await mountDomains()
    // pending: the DNS records are open
    expect(w.text()).toContain('Certificate in every region')
    expect(w.text()).toContain('_acme-challenge.shop.example.com')
    expect(w.text()).toContain('_acme-challenge.d-0123456789abcdef0123.g.runr.test')
    expect(w.text()).toContain('global host name with the CNAME record')
    expect(w.text()).toContain('shop.g.runr.test')
  })

  it('shows the domain in each region', async () => {
    const plugins = withPlugins()
    useSettingsStore().data.regions = [{ id: 'r-eu', name: 'eu-1' }, { id: 'r-us', name: 'us-1' }]
    projectsApi.domains.mockResolvedValue({
      data: {
        data: [domain({
          status: 'active',
          regions: {
            'r-eu': { status: 'active', message: null, tls_expires_at: '2027-01-20T00:00:00Z' },
            'r-us': { status: 'active', message: 'The certificate is not issued yet: waiting for the DNS-01 challenge', tls_expires_at: null },
          },
        })],
        meta: { eligible: true },
      },
    })
    const w = mount(ProjectDomains, { props: { projectId: 'p-shop' }, global: plugins.global })
    await flushPromises()

    const chips = w.find('[data-testid="domain-regions"]')
    expect(chips.exists()).toBe(true)
    expect(chips.find('[data-domain-region="eu-1"]').classes().join(' ')).toContain('emerald')
    const us = chips.find('[data-domain-region="us-1"]')
    expect(us.classes().join(' ')).not.toContain('emerald')
    expect(us.attributes('title')).toContain('DNS-01')
  })

  it('has no region list nor ACME CNAME for a regional project', async () => {
    const w = await mountDomains(domain({ acme_challenge: null, regions: null }))
    expect(w.find('[data-testid="domain-regions"]').exists()).toBe(false)
    expect(w.text()).not.toContain('Certificate in every region')
    expect(w.text()).toContain('ONE of these records')
  })
})
