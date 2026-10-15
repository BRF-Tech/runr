import { test, expect } from './fixtures/test'
import { continueButton } from './helpers/ui'
import type { Page } from '@playwright/test'
import type { Json } from './fixtures/data'
import type { MockApi } from './fixtures/mock-api'

// WebAssembly support (docs/wasm.md) with the mocked API: the runtime picker offers the Wasm
// runtimes only in regions whose WebAssembly support is ready, and the Sandbox tab installs,
// changes and uninstalls it.

const PYTHON: Json = {
  id: 'python', name: 'Python', description: '', icon: null, language: 'python', extensions: ['py'], category: 'general',
  source: 'builtin', run_mode: 'http', compiled: false, triggers: ['http', 'schedule', 'queue'], sandbox: ['runc', 'gvisor', 'kata'],
  runtime_class: null, wasm: null,
  handler: { file: 'main', function: 'handler', filename: 'main.py', pattern: 'def\\s+handler\\s*\\(' }, dependency_file: 'requirements.txt',
  versions: [{ version: '3.14', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/exec-python:3.14', built: false }],
  regions: null, is_enabled: true, projects_count: 0,
}

const WASM_RUST: Json = {
  id: 'wasm-rust', name: 'Rust (Wasm)', description: 'Rust compiled to a WebAssembly component with the Spin SDK', icon: null,
  language: 'rust', extensions: ['rs'], category: 'general', source: 'builtin', run_mode: 'http', compiled: true,
  triggers: ['http', 'schedule', 'queue'], sandbox: ['runc', 'gvisor', 'kata'], runtime_class: 'wasmtime-spin-v2', wasm: 'spin',
  handler: { file: 'lib', function: 'handle', filename: 'lib.rs', pattern: '#\\[http_component\\]' }, dependency_file: null,
  versions: [{ version: '4.1', is_default: true, eol_at: null, gpu_required: false, image: 'ghcr.io/runrsh/exec-wasm-rust:4.1', built: false }],
  regions: null, is_enabled: true, projects_count: 0,
}

const SHIMS: Json[] = [
  { id: 'spin', name: 'Spin', version: 'v0.26.0', runtime_class: 'wasmtime-spin-v2', handler: 'spin-runr', description: 'Spin apps' },
  { id: 'wasmtime', name: 'Wasmtime', version: 'v0.6.1', runtime_class: 'wasmtime-v1', handler: 'wasmtime-runr', description: 'WASI command modules' },
]

const NODES: Json[] = [
  { name: 'worker-1', arch: 'amd64', flavor: 'k3s', runtime_version: 'containerd://2.0.5-k3s1', eligible: true, reason: null, target: false, shims: [] },
  { name: 'worker-2', arch: 'amd64', flavor: 'k3s', runtime_version: 'containerd://2.0.5-k3s1', eligible: true, reason: null, target: false, shims: [] },
  { name: 'control-plane', arch: 'amd64', flavor: 'k3s', runtime_version: 'containerd://2.0.5-k3s1', eligible: false, reason: 'tainted', target: false, shims: [] },
]

function wasmStatus(extra: Json = {}): Json {
  return {
    status: 'not_installed', message: null, shims: [], nodes: null, available_shims: SHIMS, spin_runtime: '4.1.0',
    warning: 'restarts the container runtime', can_manage: true,
    cluster: { nodes: NODES, runtime_classes: { 'wasmtime-spin-v2': null, 'wasmtime-v1': null }, knative_features_to_enable: ['kubernetes.podspec-nodeselector'], installer: null },
    cluster_error: null,
    ...extra,
  }
}

function catalog(mockApi: MockApi) {
  mockApi.on('GET', '/runtimes', () => ({
    status: 200,
    body: { data: [PYTHON, WASM_RUST], abilities: { manage: true, use_custom: true }, custom_enabled: false },
  }))
}

async function openWizardStep3(page: Page, regionName: string) {
  await page.goto('/projects')
  await page.getByRole('button', { name: '+ New Project' }).click()
  await page.locator('[data-tour="create-name"] input').first().fill('wasm-e2e')
  await page.locator('[data-tour="create-region"]').getByRole('button', { name: new RegExp(regionName) }).click()
  await continueButton(page).click()
  await page.getByRole('button', { name: /Code Snippet/ }).click()
  await continueButton(page).click()
  await expect(page.getByText('Step 3 / 4')).toBeVisible()
}

async function openSandboxTab(page: Page, regionName: string) {
  await page.goto('/settings')
  await page.locator(`[data-region-row="${regionName}"]`).getByRole('button', { name: 'Edit', exact: true }).click()
  await page.getByRole('button', { name: 'Isolation', exact: true }).click()
  await expect(page.locator('[data-testid="wasm-card"]')).toBeVisible()
}

test.describe('WebAssembly', () => {
  test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs the mocked API (installing shims needs cluster nodes)')

  test('the runtime picker offers the Wasm runtimes only where WebAssembly support is ready', async ({ page, auth, mockApi }) => {
    catalog(mockApi)
    // eu-1 has WebAssembly support with the spin shim; region-b has none
    Object.assign(mockApi.db.regions[0], { sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'], has_sandbox_wasm: true })
    Object.assign(mockApi.db.regions[1], { has_serving: true, knative_serving_status: 'ready', sandbox_wasm_status: 'not_installed', sandbox_wasm_shims: [] })
    await auth.signIn()

    await openWizardStep3(page, 'eu-1')
    const wasm = page.locator('[data-runtime="wasm-rust"]')
    await expect(wasm).toBeVisible()
    await expect(wasm.locator('.wasm-badge')).toHaveText('Wasm')
    await expect(page.locator('[data-runtime="python"] .wasm-badge')).toHaveCount(0)
    await wasm.click()
    await expect(page.locator('[data-version="4.1"]')).toBeVisible()
  })

  test('a region without WebAssembly support does not offer them', async ({ page, auth, mockApi }) => {
    catalog(mockApi)
    Object.assign(mockApi.db.regions[0], { sandbox_wasm_status: 'not_installed', sandbox_wasm_shims: [] })
    await auth.signIn()

    await openWizardStep3(page, 'eu-1')
    await expect(page.locator('[data-runtime="python"]')).toBeVisible()
    await expect(page.locator('[data-runtime="wasm-rust"]')).toHaveCount(0)
  })

  test('installs WebAssembly support on the selected nodes from the Sandbox tab', async ({ page, auth, mockApi, realtime }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    let state = wasmStatus()
    mockApi.on('GET', '/knative-regions/:id/wasm', () => ({ status: 200, body: state }))
    mockApi.on('POST', '/knative-regions/:id/wasm', (req) => {
      state = wasmStatus({ status: 'installing', message: '[10:00:00] Installation queued...\n', shims: req.body.shims, nodes: req.body.nodes })
      const region = { ...mockApi.db.regions[0], sandbox_wasm_status: 'installing', sandbox_wasm_status_message: state.message, sandbox_wasm_shims: req.body.shims, sandbox_wasm_nodes: req.body.nodes }
      return { status: 202, body: { message: 'queued', warning: state.warning, region: { data: region } } }
    })
    await auth.signIn()
    await openSandboxTab(page, 'eu-1')

    const card = page.locator('[data-testid="wasm-card"]')
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Not Installed')
    // Spin is always part of it; Wasmtime is optional
    await expect(card.locator('[data-testid="wasm-shim-spin"] input')).toBeChecked()
    await expect(card.locator('[data-testid="wasm-shim-spin"] input')).toBeDisabled()
    await card.locator('[data-testid="wasm-shim-wasmtime"] input').check()
    await expect(card.getByText('Knative features still to enable')).toBeVisible()

    // a node selection instead of every eligible node; the control plane is not eligible
    await expect(card.getByText('All eligible nodes (2)')).toBeVisible()
    await card.locator('[data-testid="wasm-all-nodes"] input').uncheck()
    await expect(card.locator('[data-testid="wasm-node-control-plane"] input')).toBeDisabled()
    await expect(card.locator('[data-testid="wasm-node-control-plane"]')).toContainText('tainted / cordoned')
    await card.locator('[data-testid="wasm-node-worker-1"] input').check()

    const sent = mockApi.waitFor('POST', '/knative-regions/:id/wasm')
    await card.locator('[data-testid="wasm-install"]').click()
    // the runtime restart is announced before anything happens
    await page.getByRole('button', { name: 'Install and restart', exact: true }).click()
    expect((await sent).body).toEqual({ shims: ['spin', 'wasmtime'], nodes: ['worker-1'] })

    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Installing...')
    // live progress of the install
    await realtime.platform('install.progress', { region_id: 'r-ready', component: 'wasm', message: '[10:00:05] Deploying the WebAssembly installer\n' })
    await expect(card.locator('[data-testid="wasm-log"]')).toContainText('Deploying the WebAssembly installer')

    // done: the status endpoint reports ready with the shims
    state = wasmStatus({ status: 'ready', message: '[10:02:00] WebAssembly support installed.\n', shims: ['spin', 'wasmtime'], nodes: ['worker-1'] })
    await realtime.platform('wasm.installed', { region_id: 'r-ready', region_name: 'eu-1', shims: ['spin', 'wasmtime'] })
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Ready')
    await expect(card.locator('[data-testid="wasm-uninstall"]')).toBeVisible()
    await expect(card.locator('[data-testid="wasm-install"]')).toHaveText('Apply changes')
  })

  test('uninstall asks first and is sent as DELETE', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false, sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'] })
    mockApi.on('GET', '/knative-regions/:id/wasm', () => ({ status: 200, body: wasmStatus({ status: 'ready', shims: ['spin'] }) }))
    mockApi.on('DELETE', '/knative-regions/:id/wasm', () => ({
      status: 202,
      body: { message: 'queued', region: { data: { ...mockApi.db.regions[0], sandbox_wasm_status: 'uninstalling', sandbox_wasm_status_message: 'Removal queued...' } } },
    }))
    await auth.signIn()
    await openSandboxTab(page, 'eu-1')

    const card = page.locator('[data-testid="wasm-card"]')
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Ready')
    const sent = mockApi.waitFor('DELETE', '/knative-regions/:id/wasm')
    await card.locator('[data-testid="wasm-uninstall"]').click()
    await page.getByRole('button', { name: 'Uninstall WebAssembly', exact: true }).last().click()
    await sent
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Uninstalling...')
  })

  test('a refused install shows the reason', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false })
    mockApi.on('GET', '/knative-regions/:id/wasm', () => ({ status: 200, body: wasmStatus() }))
    mockApi.on('POST', '/knative-regions/:id/wasm', () => ({
      status: 422,
      body: { message: 'Node worker-9 cannot get WebAssembly support (the container runtime is not containerd).', errors: { nodes: ['…'] } },
    }))
    await auth.signIn()
    await openSandboxTab(page, 'eu-1')

    const card = page.locator('[data-testid="wasm-card"]')
    await card.locator('[data-testid="wasm-install"]').click()
    await page.getByRole('button', { name: 'Install and restart', exact: true }).click()
    await expect(card.locator('[data-testid="wasm-error"]')).toContainText('cannot get WebAssembly support')
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Not Installed')
  })

  test('without sandbox.manage the card shows the status only', async ({ page, auth, mockApi }) => {
    Object.assign(mockApi.db.regions[0], { is_synced: false, sandbox_wasm_status: 'ready', sandbox_wasm_shims: ['spin'] })
    mockApi.on('GET', '/knative-regions/:id/wasm', () => ({ status: 200, body: wasmStatus({ status: 'ready', shims: ['spin'], can_manage: false }) }))
    await auth.signIn()
    await openSandboxTab(page, 'eu-1')

    const card = page.locator('[data-testid="wasm-card"]')
    await expect(card.locator('[data-testid="wasm-status"]')).toHaveText('Ready')
    await expect(card.getByText('needs the sandbox.manage permission')).toBeVisible()
    await expect(card.locator('[data-testid="wasm-install"]')).toHaveCount(0)
    await expect(card.locator('[data-testid="wasm-uninstall"]')).toHaveCount(0)
  })
})
