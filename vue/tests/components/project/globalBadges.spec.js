import { describe, expect, it, vi } from 'vitest'
import { mount, RouterLinkStub } from '@vue/test-utils'
import ProjectHeader from '@/components/ProjectHeader.vue'
import ProjectCard from '@/components/ProjectCard.vue'
import { withPlugins } from '../../helpers'

vi.mock('vue-router', async (orig) => ({ ...(await orig()), useRouter: () => ({ push: vi.fn() }), useRoute: () => ({ query: {} }) }))

// A global project (docs/global-projects.md) is marked in the project header and on its card,
// with its regions on hover; its endpoint is the global host name the API returns.
const globalProject = {
  id: 'p-shop', name: 'shop', slug: 'shop', service_type: 'faas', runtime: 'python', runtime_version: '3.12', config: {},
  placement: 'global', endpoint_url: 'https://shop.g.runr.test',
  global: {
    mode: 'ha', hostname: 'shop.g.runr.test', primary_region_id: 'r-eu',
    regions: [{ region_id: 'r-eu', region_name: 'eu-1' }, { region_id: 'r-us', region_name: 'us-1' }],
  },
}
const regional = { ...globalProject, id: 'p-api', placement: 'regional', global: null, endpoint_url: 'https://api.eu-1.runr.test' }

const mountOpts = () => ({ global: { ...withPlugins('en').global, stubs: { RouterLink: RouterLinkStub } } })

describe('Global project badges', () => {
  it('the header shows the Global badge with the number of regions and the global endpoint', () => {
    const w = mount(ProjectHeader, { props: { project: globalProject, tabs: [], endpointUrl: globalProject.endpoint_url }, ...mountOpts() })
    const badge = w.find('[data-test="project-global-badge"]')
    expect(badge.text()).toContain('Global')
    expect(badge.text()).toContain('2 regions')
    expect(badge.attributes('title')).toBe('eu-1, us-1')
    expect(w.text()).toContain('https://shop.g.runr.test')
  })

  it('a regional project has no Global badge', () => {
    const w = mount(ProjectHeader, { props: { project: regional, tabs: [] }, ...mountOpts() })
    expect(w.find('[data-test="project-global-badge"]').exists()).toBe(false)
  })

  it('the project card shows the Global badge', () => {
    const w = mount(ProjectCard, { props: { project: globalProject, endpointUrl: globalProject.endpoint_url }, ...mountOpts() })
    expect(w.find('[data-test="project-card-global"]').attributes('title')).toBe('eu-1, us-1')
    const card = mount(ProjectCard, { props: { project: regional }, ...mountOpts() })
    expect(card.find('[data-test="project-card-global"]').exists()).toBe(false)
  })
})
