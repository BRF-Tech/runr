import { test, expect } from './fixtures/test'
import type { Page } from '@playwright/test'
import { continueButton } from './helpers/ui'

// Administration → Regions & settings → "+ Add Region" wizard (7 steps). Every cluster call is answered by the
// mocked API; the registry/region creation result arrives as a platform event, sent
// through the fake realtime socket. Never run against a real cluster from here.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'the region wizard talks to a Kubernetes cluster; mocked API only')

const KUBECONFIG = [
  'apiVersion: v1',
  'kind: Config',
  'clusters:',
  '  - cluster:',
  '      server: https://10.0.0.20:6443',
  '    name: e2e',
].join('\n')

async function startWizard(page: Page) {
  await page.goto('/settings')
  await page.getByRole('button', { name: '+ Add Region' }).click()
  await expect(page.getByRole('heading', { name: 'Add Region' })).toBeVisible()
  await expect(page.getByText(/Step 1 \/ 7/)).toBeVisible()
}

async function fillConnection(page: Page, name: string) {
  await page.getByPlaceholder('istanbul-1a').fill(name)
  await page.getByRole('button', { name: 'Paste Content' }).click()
  await page.locator('textarea').fill(KUBECONFIG)
}

test.describe('Region wizard', () => {
  test('connects a cluster, picks workers and creates the region', async ({ page, auth, mockApi, realtime }) => {
    await auth.signIn()
    await startWizard(page)

    // Step 1: connection
    await expect(continueButton(page)).toBeDisabled()
    await fillConnection(page, 'e2e-region')
    const validated = mockApi.waitFor('POST', '/knative-regions/validate-kubeconfig')
    const nsChecked = mockApi.waitFor('POST', '/knative-regions/check-namespace-preview')
    await continueButton(page).click()
    expect((await validated).body.kubeconfig).toBe(KUBECONFIG)
    expect((await nsChecked).body).toMatchObject({ name: 'e2e-region' })

    // Step 2: workers + registry (nodes and storage classes from the cluster preview)
    await expect(page.getByText(/Step 2 \/ 7/)).toBeVisible()
    await expect(page.getByText('worker-1', { exact: true })).toBeVisible()
    await expect(page.getByText('worker-2', { exact: true })).toBeVisible()
    // worker-1 already carries the worker role and is preselected; add worker-2
    await page.getByText('worker-2', { exact: true }).click()

    const started = mockApi.waitFor('POST', '/registry-setup/start')
    await page.getByRole('button', { name: 'Save & Next' }).click()
    const { body } = await started
    expect(body).toMatchObject({
      name: 'e2e-region',
      kubeconfig: KUBECONFIG,
      nodes: ['worker-1', 'worker-2'],
      registry_mode: 'internal',
      storage_type: 'pvc',
      pvc_config: { storageClass: 'longhorn' },
    })

    // The API reports the finished setup over the websocket — once the UI holds the setup id
    // of the answer (it shows the queued phase then); earlier events belong to another setup
    await expect(page.getByText('Queued...')).toBeVisible()
    const created = mockApi.db.regions.find(r => r.name === 'e2e-region')!
    await realtime.platform('region.created', { setup_id: `sid-${created.id}`, region_id: created.id, region_name: created.name })
    await expect(page.getByText(/Step 3 \/ 7/)).toBeVisible()
    await expect(page.getByText('Operator').first()).toBeVisible()
  })

  test('stops when the region namespace already exists on the cluster', async ({ page, auth, mockApi }) => {
    mockApi.db.namespaceTaken.push('taken-ns')
    await auth.signIn()
    await startWizard(page)
    await fillConnection(page, 'taken-ns')
    await continueButton(page).click()

    await expect(page.getByText('A namespace named "taken-ns" already exists on the cluster.', { exact: false })).toBeVisible()
    await expect(page.getByText(/Step 1 \/ 7/)).toBeVisible()
  })

  test('shows why a kubeconfig is rejected', async ({ page, auth }) => {
    await auth.signIn()
    await startWizard(page)
    await page.getByPlaceholder('istanbul-1a').fill('bad-config')
    await page.getByRole('button', { name: 'Paste Content' }).click()
    await page.locator('textarea').fill('this is not yaml')
    await continueButton(page).click()

    await expect(page.getByText('The kubeconfig is not valid YAML.')).toBeVisible()
  })

  test('reports a failed registry setup on the workers step', async ({ page, auth, mockApi, realtime }) => {
    await auth.signIn()
    await startWizard(page)
    await fillConnection(page, 'e2e-fail')
    await continueButton(page).click()
    await expect(page.getByText(/Step 2 \/ 7/)).toBeVisible()
    const started = mockApi.waitFor('POST', '/registry-setup/start')
    await page.getByRole('button', { name: 'Save & Next' }).click()
    await started

    // The setup id is what the fake API answered: sid-<region id> (held once the queued phase shows)
    await expect(page.getByText('Queued...')).toBeVisible()
    const region = mockApi.db.regions.find(r => r.name === 'e2e-fail')!
    await realtime.platform('region.create_failed', { setup_id: `sid-${region.id}`, error: 'PVC could not be bound' })
    await expect(page.getByText('PVC could not be bound')).toBeVisible()
    await expect(page.getByText(/Step 2 \/ 7/)).toBeVisible()
  })
})
