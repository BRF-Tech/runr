import { test, expect } from './fixtures/test'
import { continueButton, confirmDialog } from './helpers/ui'
import { globalObject } from './fixtures/mock-api'
import type { MockApi } from './fixtures/mock-api'

// Global projects (docs/global-projects.md): the create wizard's region choice, the Regions tab
// of a global project, the region's global DNS server and the platform's zone. Global DNS needs
// PowerDNS servers on public port 53 in several regions and a delegated zone, so the flows run
// against the mocked API.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'global projects need regions with global DNS servers and a delegated zone; covered with the mocked API')

/** eu-1, us-1 and ap-1 with a ready global DNS server; returns the region ids. */
function globalRegions(mockApi: MockApi) {
  const [eu] = mockApi.db.regions
  Object.assign(eu, { global_dns_ready: true, external_ip: '203.0.113.10' })
  mockApi.db.regions.push(
    { ...eu, id: 'r-us', name: 'us-1', project_namespace: 'us-1', external_ip: '198.51.100.20' },
    { ...eu, id: 'r-ap', name: 'ap-1', project_namespace: 'ap-1', external_ip: '192.0.2.30' },
  )
  for (const [id, name, address] of [['r-ready', 'eu-1', '203.0.113.53'], ['r-us', 'us-1', '198.51.100.53']]) {
    mockApi.db.globalDnsServers[id] = {
      region_id: id, region_name: name, status: 'ready', status_message: null, address,
      ns_name: `ns-${name}.g.runr.test`, reachable: true, sync_status: 'in_sync', sync_message: null,
      synced_at: new Date().toISOString(), tls_status: 'ready', tls_message: null, tls_expires_at: null,
      eligible: true, reason: null,
      geoip: {
        source: 'default', url: null, effective_url: 'https://download.db-ip.com/free/dbip-city-lite-{yyyy}-{mm}.mmdb.gz',
        status: 'loaded', message: 'GeoIP database loaded (130.2 MiB).', checked_at: new Date().toISOString(),
      },
    }
  }
  return { eu: eu.id, us: 'r-us', ap: 'r-ap' }
}

/** A global project "shop" in eu-1 (primary) and us-1, high availability. */
function globalProject(mockApi: MockApi) {
  const ids = globalRegions(mockApi)
  const p = {
    ...mockApi.db.projects[0],
    id: 'p-global',
    name: 'shop',
    slug: 'shop',
    knative_region_id: ids.eu,
    placement: 'global',
    endpoint_url: 'https://shop.g.runr.test',
  }
  mockApi.db.projects.unshift(p)
  mockApi.db.versions['p-global'] = [{ id: 'v-g-1', label: 'v1', commit_message: 'Initial version', build_status: 'ready', created_at: new Date().toISOString() }]
  mockApi.db.deployments['p-global'] = []
  mockApi.db.domains['p-global'] = []
  mockApi.db.code['p-global'] = 'def handler(event, context):\n    return {"ok": True}\n'
  mockApi.db.versionFiles['v-g-1'] = { 'main.py': mockApi.db.code['p-global'] }
  const g = globalObject(mockApi.db, p, 'ha', [ids.eu, ids.us])
  g.warnings = ['Only external bindings reach every region: bindings of a region stay in the primary region.']
  mockApi.db.globalProjects['p-global'] = g
  p.global = g
  return { project: p, ...ids }
}

test.describe('Global projects', () => {
  test('creates a global project in two regions through the wizard', async ({ page, auth, mockApi }) => {
    const ids = globalRegions(mockApi)
    // region-b has no global DNS server: it is listed but cannot be picked
    await auth.signIn()
    await page.goto('/projects')
    await page.getByRole('button', { name: '+ New Project' }).click()
    await expect(page.getByRole('heading', { name: 'Give your project a name' })).toBeVisible()

    await page.locator('[data-placement="global"]').click()
    await page.locator('[data-tour="create-name"] input').first().fill('shop')
    await expect(page.locator('[data-global-region="region-b"]')).toBeDisabled()
    await expect(continueButton(page)).toBeDisabled()
    await page.locator('[data-global-region="eu-1"]').click()
    await page.locator('[data-global-region="us-1"]').click()
    await page.getByTestId('global-primary').selectOption(ids.us)
    await page.locator('[data-global-mode="proximity"]').click()
    await continueButton(page).click()

    await expect(page.getByText('Step 2 / 4')).toBeVisible()
    await page.getByRole('button', { name: /Container/ }).click()
    await continueButton(page).click()

    // every trigger (the non-HTTP ones run in the primary), an image of a registry or an upload
    await expect(page.getByText('Step 3 / 4')).toBeVisible()
    await expect(page.getByTestId('global-trigger')).toContainText('run in the primary region only')
    await expect(page.getByTestId('global-image-hint')).toContainText('copied into every region')
    await page.getByTestId('image-ref-input').fill('nginx:1.27-alpine')
    await continueButton(page).click()

    await expect(page.getByText('Step 4 / 4')).toBeVisible()
    const sent = mockApi.waitFor('POST', '/projects')
    await page.getByRole('button', { name: 'Create Project' }).click()
    const { body } = await sent
    expect(body).toMatchObject({
      name: 'shop',
      service_type: 'caas',
      placement: 'global',
      global_mode: 'proximity',
      region_ids: [ids.us, ids.eu],
      knative_region_id: ids.us,
      config: { image: 'nginx:1.27-alpine', trigger_type: 'http' },
    })
    expect(body).not.toHaveProperty('endpoint_type')
    await expect(page.getByRole('heading', { name: 'Give your project a name' })).toBeHidden()
  })

  test('shows the API error of the region choice on the first step', async ({ page, auth, mockApi }) => {
    globalRegions(mockApi)
    mockApi.on('POST', '/projects', () => ({
      status: 422,
      body: { message: 'invalid', errors: { 'region_ids.1': ['us-1 has no ready global DNS server.'] } },
    }))
    await auth.signIn()
    await page.goto('/projects')
    await page.getByRole('button', { name: '+ New Project' }).click()
    await page.locator('[data-placement="global"]').click()
    await page.locator('[data-tour="create-name"] input').first().fill('shop')
    await page.locator('[data-global-region="eu-1"]').click()
    await page.locator('[data-global-region="us-1"]').click()
    await continueButton(page).click()
    await page.getByRole('button', { name: /Code Snippet/ }).click()
    await continueButton(page).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: 'Create Project' }).click()

    await expect(page.getByText('Step 1 / 4')).toBeVisible()
    await expect(page.getByTestId('create-region-error')).toHaveText('us-1 has no ready global DNS server.')
  })

  test('the Regions tab shows every region and changes mode, regions and primary', async ({ page, auth, mockApi }) => {
    const { eu, us, ap } = globalProject(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-global#regions')

    await expect(page.locator('[data-test="project-global-badge"]')).toBeVisible()
    await expect(page.getByText('https://shop.g.runr.test').first()).toBeVisible()
    await expect(page.getByTestId('global-hostname')).toContainText('shop.g.runr.test')
    await expect(page.locator('[data-global-row="eu-1"]')).toContainText('Primary')
    await expect(page.locator('[data-global-row="us-1"]')).toContainText('Ready')
    await expect(page.getByTestId('global-warning')).toContainText('Only external bindings')

    // DNS mode
    const mode = mockApi.waitFor('PUT', '/projects/:id/global')
    await page.locator('[data-global-mode="proximity"]').click()
    expect((await mode).body).toEqual({ mode: 'proximity' })
    await expect(page.locator('[data-global-mode="proximity"]')).toHaveAttribute('aria-pressed', 'true')

    // add ap-1: it rolls out
    const added = mockApi.waitFor('PUT', '/projects/:id/global')
    await page.getByTestId('global-add-region').selectOption(ap)
    await page.getByTestId('global-add').click()
    expect((await added).body).toEqual({ region_ids: [eu, us, ap] })
    await expect(page.locator('[data-global-row="ap-1"]')).toContainText('Deploying')

    // remove us-1 after confirmation
    const removed = mockApi.waitFor('PUT', '/projects/:id/global')
    await page.locator('[data-global-row="us-1"]').getByTestId('global-remove').click()
    await confirmDialog(page, 'Remove')
    expect((await removed).body).toEqual({ region_ids: [eu, ap] })
    await expect(page.locator('[data-global-row="us-1"]')).toContainText('Removing')
  })

  test('makes a secondary region the primary and syncs', async ({ page, auth, mockApi }) => {
    const { us } = globalProject(mockApi)
    await auth.signIn()
    await page.goto('/projects/p-global#regions')

    const primary = mockApi.waitFor('PUT', '/projects/:id/global')
    await page.locator('[data-global-row="us-1"]').getByTestId('global-make-primary').click()
    await confirmDialog(page, 'Make primary')
    expect((await primary).body).toEqual({ primary_region_id: us })
    await expect(page.locator('[data-global-row="us-1"]')).toContainText('Primary')

    const sync = mockApi.waitFor('POST', '/projects/:id/global/sync')
    await page.getByTestId('global-sync').click()
    await sync
    await expect(page.getByText('Sync started.')).toBeVisible()
  })

  test('enables the global DNS server of a region', async ({ page, auth, mockApi }) => {
    // a region of this installation (a synced copy has no Edit button)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()

    const section = page.getByTestId('global-dns-section')
    await expect(section.getByTestId('global-dns-status')).toHaveText('Off')
    const enabled = mockApi.waitFor('POST', '/knative-regions/:id/global-dns')
    await section.getByTestId('global-dns-enable').click()
    expect((await enabled).path).toBe('/knative-regions/r-ready/global-dns')
    await expect(section.getByTestId('global-dns-status')).toHaveText('Installing')
  })

  test('refuses to disable a region global projects use', async ({ page, auth, mockApi }) => {
    globalProject(mockApi)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()

    const section = page.getByTestId('global-dns-section')
    await expect(section.getByTestId('global-dns-address')).toHaveText('203.0.113.53')
    await section.getByTestId('global-dns-disable').click()
    await confirmDialog(page, 'Disable')
    await expect(section.getByTestId('global-dns-error')).toHaveText('Global projects still use eu-1: shop.')
  })

  test('the platform settings show the zone, the delegation records and the servers', async ({ page, auth, mockApi }) => {
    globalRegions(mockApi)
    Object.assign(mockApi.db.globalDns, {
      update_region_id: 'r-ready',
      delegation: {
        records: [
          { name: 'g.runr.test', type: 'NS', value: 'ns-eu-1.g.runr.test' },
          { name: 'g.runr.test', type: 'NS', value: 'ns-us-1.g.runr.test' },
          { name: 'ns-eu-1.g.runr.test', type: 'A', value: '203.0.113.53' },
          { name: 'ns-us-1.g.runr.test', type: 'A', value: '198.51.100.53' },
        ],
        status: 'ok',
        message: null,
      },
    })
    await auth.signIn()
    await page.goto('/settings')

    const panel = page.getByTestId('global-dns-panel')
    await expect(panel.getByTestId('global-dns-zone')).toHaveText('g.runr.test')
    await expect(panel.getByTestId('global-dns-delegation')).toHaveText('Delegated')
    await expect(panel.getByTestId('global-dns-record')).toHaveCount(4)
    await expect(panel.locator('[data-server="us-1"]')).toContainText('198.51.100.53')

    const sync = mockApi.waitFor('POST', '/global-dns/sync')
    await panel.getByTestId('global-dns-sync').click()
    await sync
    await expect(page.getByText('Zone sync started.')).toBeVisible()
  })

  test('fails over to a secondary region and shows the fenced primary, the triggers and the data', async ({ page, auth, mockApi }) => {
    const { project, us } = globalProject(mockApi)
    project.global.bindings = [
      {
        binding_id: 'b-orders', name: 'orders-db', type: 'postgres', plan: 'dedicated', home_region_id: 'r-ready', home_region_name: 'eu-1',
        access: 'replicas',
        regions: [
          { region_id: 'r-ready', region_name: 'eu-1', kind: 'home', status: 'ready', status_message: null, lag_seconds: null },
          { region_id: 'r-us', region_name: 'us-1', kind: 'replica', status: 'ready', status_message: null, lag_seconds: 2 },
        ],
      },
    ]
    await auth.signIn()
    await page.goto('/projects/p-global#regions')

    await expect(page.locator('[data-global-row="eu-1"]').getByTestId('global-triggers-badge')).toBeVisible()
    await expect(page.locator('[data-global-failover="automatic"]')).toHaveAttribute('aria-pressed', 'true')
    const data = page.locator('[data-global-binding="orders-db"]')
    await expect(data.getByTestId('global-data-access')).toHaveText('Replica per region')
    await expect(data.locator('[data-global-binding-region="us-1"]')).toContainText('2 s')

    // manual failover
    const setting = mockApi.waitFor('PUT', '/projects/:id/global')
    await page.locator('[data-global-failover="manual"]').click()
    expect((await setting).body).toEqual({ failover: 'manual' })

    const failover = mockApi.waitFor('POST', '/projects/:id/global/failover')
    await page.locator('[data-global-row="us-1"]').getByTestId('global-failover').click()
    await confirmDialog(page, 'Fail over')
    expect((await failover).body).toEqual({ region_id: us })
    await expect(page.locator('[data-global-row="us-1"]')).toContainText('Primary')
    await expect(page.locator('[data-global-row="eu-1"]').getByTestId('global-region-status')).toHaveText('Fenced')
    await expect(page.locator('[data-global-row="eu-1"]')).toContainText('Failed over to us-1')
    await expect(page.locator('[data-global-row="us-1"]').getByTestId('global-triggers-badge')).toBeVisible()
  })

  test('sets the GeoIP source of a region server for a cluster without internet access', async ({ page, auth, mockApi }) => {
    globalRegions(mockApi)
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    await auth.signIn()
    await page.goto('/settings')
    await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()

    const geoip = page.getByTestId('global-dns-geoip')
    await expect(geoip.getByTestId('global-dns-geoip-status')).toHaveText('Loaded')

    // the operator's file on the server's volume: the copy command, then a reload
    await geoip.locator('[data-geoip-source="file"]').click()
    await expect(geoip.getByTestId('global-dns-geoip-file')).toContainText('kubectl -n runr-global-dns cp geoip.mmdb')
    const saved = mockApi.waitFor('PUT', '/knative-regions/:id/global-dns/geoip')
    await geoip.getByTestId('global-dns-geoip-save').click()
    expect((await saved).body).toEqual({ source: 'file' })
    await expect(geoip.getByTestId('global-dns-geoip-status')).toHaveText('Not checked')

    const reload = mockApi.waitFor('POST', '/knative-regions/:id/global-dns/geoip/reload')
    await geoip.getByTestId('global-dns-geoip-reload').click()
    await reload
    await expect(geoip.getByTestId('global-dns-geoip-message')).toContainText('reads its GeoIP source again')

    // a URL into the region's object store
    await geoip.locator('[data-geoip-source="url"]').click()
    await geoip.getByTestId('global-dns-geoip-url').fill('http://runr-objects.runr-data.svc.cluster.local:3900/geoip/city.mmdb.gz')
    const url = mockApi.waitFor('PUT', '/knative-regions/:id/global-dns/geoip')
    await geoip.getByTestId('global-dns-geoip-save').click()
    expect((await url).body).toEqual({ source: 'url', url: 'http://runr-objects.runr-data.svc.cluster.local:3900/geoip/city.mmdb.gz' })
  })
})
