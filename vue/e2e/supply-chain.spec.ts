import { test, expect, type Page, type Route } from '@playwright/test'

/**
 * Software supply chain in the UI — self-contained: every /api/* call is answered by an
 * in-memory fake of the RunR API (no backend). The base URL comes from the Playwright config.
 *
 *  - Project → Security: versions with SBOM / severity badges / signature, the findings of a
 *    version with advisory links, SBOM download, re-scan
 *  - Deploy blocked by the policy gate → override with a reason
 *  - Region → Supply chain: gate settings saved, signing key generated, admission installed
 */

type Json = Record<string, any>

const ADMIN = { id: 1, name: 'Admin', username: 'admin', email: 'admin@example.com', is_admin: true, tutorial_completed_at: '2026-01-01T00:00:00Z' }
const DIGEST = 'sha256:' + 'ab'.repeat(32)
const REGIONS = [
  { id: 'r1', name: 'eu-1', is_active: true, is_synced: false, has_serving: true, has_redis: true, has_kubeconfig: true, has_registry: true,
    cluster_status: 'online', region_status: 'active', total_cpu_m: 4000, total_memory_mb: 8192, used_cpu_m: 0, used_memory_mb: 0,
    project_namespace: 'fn', registry_mode: 'internal', registry_status: 'ready', registry_address: '10.43.0.5:5000' },
]

const FINDINGS = [
  { id: 'CVE-2024-45491', severity: 'CRITICAL', package: 'libexpat1', installed: '2.5.0-1', fixed: '2.5.0-1+deb12u1', title: 'libexpat: Integer Overflow', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-45491' },
  { id: 'CVE-2024-6345', severity: 'HIGH', package: 'setuptools', installed: '68.0.0', fixed: '70.0.0', title: 'setuptools: remote code execution', url: 'https://nvd.nist.gov/vuln/detail/CVE-2024-6345' },
]

function report(extra: Json = {}): Json {
  return {
    id: 'rep1', subject_type: 'version', version_id: 'v1', image_kind: 'built', image_ref: '10.43.0.5:5000/runr-builds/orders-api:v1',
    image_digest: DIGEST, status: 'completed', sbom_status: 'ok', scan_status: 'ok', signature_status: 'ok', attestation_status: 'ok',
    referrer_status: 'ok', counts: { critical: 1, high: 1, medium: 0, low: 0, unknown: 0 }, fixable: { critical: 1, high: 1 },
    findings_total: 2, packages: 42, scanned_at: '2026-10-10T08:15:00Z', signed_at: '2026-10-10T08:16:00Z',
    signature_key_fingerprint: 'f'.repeat(64), finished_at: '2026-10-10T08:16:00Z', error: null, ...extra,
  }
}

const PROJECT = {
  id: 'p1', name: 'orders-api', slug: 'orders-api', service_type: 'faas', runtime: 'python', runtime_version: '3.14', description: null,
  knative_region_id: 'r1', region: { id: 'r1', name: 'eu-1' },
  config: { handler: 'main.handler', trigger_type: 'http', cpu: '250m', memory_mb: 256, timeout_seconds: 30, scale_to_zero: true, min_scale: 0, max_scale: 1, env_vars: null },
  endpoint_type: 'subdomain', endpoint_url: null, gateway_route: null,
  latest_version: { id: 'v1', number: 1, label: 'v1', build_status: 'ready' }, latest_deployment: null,
  created_by: { id: 1, name: 'Admin' }, runtime_eol: null, created_at: '2026-10-10T08:00:00Z',
}

class FakeApi {
  deploys: Json[] = []
  rescans: string[] = []
  sbomDownloads: string[] = []
  regionUpdates: Json[] = []
  keyRequests = 0
  admissionRequests: Json[] = []
  settings: Json = {
    knative_region_id: 'r1', sbom_enabled: true, scan_enabled: true, scan_external_images: false, rescan_interval_hours: 24,
    scanner_db_mode: 'online', scanner_db_source: null, scanner_db_status: 'none', gate_mode: 'off', gate_max_critical: 0,
    gate_max_high: null, gate_only_fixable: false, gate_require_scan: false, signing_enabled: false, verify_on_deploy: true,
    attest_enabled: true, signing_status: 'none', signing_public_key: null, signing_key_fingerprint: null, signing_retired_keys: [],
    admission_mode: 'off', admission_status: 'not_installed', admission_require_sbom: false, admission_exempt_images: null,
    caas_policy: 'any', caas_allowed_registries: null, caas_trusted_keys: null, data_location: null,
    region: { id: 'r1', name: 'eu-1', has_registry: true, registry_mode: 'internal', project_namespace: 'fn' },
    scanner_db_repository: '10.43.0.5:5000/runr-supply-chain/trivy-db:2', scanner_db_default_source: 'mirror.gcr.io/aquasec/trivy-db:2',
    supply_chain_namespace: 'runr-supply-chain', can_manage: true,
  }

  async install(page: Page) {
    await page.route((url) => url.pathname.startsWith('/api/'), (route) => this.handle(route))
  }

  private json(route: Route, body: unknown, status = 200) {
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
  }

  private versionRow(): Json {
    return { id: 'v1', number: 1, label: 'v1', build_status: 'ready', applicable: true, image_kind: 'built', image: report().image_ref, report: report() }
  }

  private async handle(route: Route) {
    const req    = route.request()
    const url    = new URL(req.url())
    const path   = url.pathname.replace(/^\/api/, '')
    const method = req.method()
    const body   = (() => { try { return req.postDataJSON() ?? {} } catch { return {} } })()

    if (path === '/version') return this.json(route, { version: 'e2e' })
    if (path === '/auth/me') return this.json(route, ADMIN)
    if (path === '/notifications/unread-count') return this.json(route, { count: 0 })
    if (path === '/notifications') return this.json(route, { data: [], meta: { total: 0 } })
    if (path === '/settings') {
      return this.json(route, {
        base_domain: 'runr.test', registration_enabled: true, gpu_demo_enabled: false, custom_runtimes_enabled: true,
        total_cpu_m: 4000, total_memory_mb: 8192, regions: REGIONS, demo_status: 'idle',
        executor_images: [{ id: 'e1', runtime: 'python', version: '3.14', is_default: true, sort_order: 1, eol_at: null }],
      })
    }
    if (path === '/residency') return this.json(route, { data: { restricted: false, allowed_region_ids: null, default_region_id: null, regions: [] } })
    if (path === '/runtimes') return this.json(route, { data: [], abilities: { manage: true, use_custom: true }, custom_enabled: true })
    if (path === '/knative-regions') return this.json(route, { data: REGIONS })

    // ── Project ──
    if (path === '/projects') return this.json(route, { data: [PROJECT] })
    if (path === '/projects/p1') return this.json(route, { data: PROJECT })
    if (path === '/projects/p1/versions') {
      return this.json(route, { data: [{ id: 'v1', number: 1, label: 'v1', build_status: 'ready', commit_message: 'first', created_at: '2026-10-10T08:00:00Z', changed_files: ['main.py'] }] })
    }
    if (path === '/projects/p1/files' || path.startsWith('/projects/p1/versions/v1/files')) {
      return this.json(route, { files: { 'main.py': 'def handler(event, context):\n    return {"statusCode": 200}\n', 'requirements.txt': 'redis>=5.0\n' } })
    }
    if (path === '/projects/p1/deploy' && method === 'POST') {
      this.deploys.push(body)
      if (!body.override_policy) {
        return this.json(route, {
          message: 'Deploy blocked by the supply-chain policy of region eu-1: 1 CRITICAL vulnerabilities (allowed: 0). Fix the findings and build again, or deploy with a policy override and a reason.',
          code: 'supply_chain_policy',
          violations: [{ rule: 'critical', message: '1 CRITICAL vulnerabilities (allowed: 0)', enforced: true, count: 1, limit: 0 }],
          warnings: [], can_override: true, report: report(),
        }, 422)
      }
      return this.json(route, { id: 'd1', status: 'pending', version: { id: 'v1', label: 'v1' } }, 202)
    }

    // ── Security ──
    if (path === '/projects/p1/security') {
      return this.json(route, { data: {
        region: { id: 'r1', name: 'eu-1', data_location: 'TR' },
        policy: { mode: 'enforce', max_critical: 0, max_high: null, only_fixable: false, require_scan: false, require_signature: true },
        pipeline: { sbom: true, scan: true, signing: true, admission: 'off' },
        versions: [this.versionRow()], deployments: [], overrides: [],
      } })
    }
    if (path === '/projects/p1/versions/v1/security') {
      return this.json(route, { data: {
        version: this.versionRow(),
        report: { ...report(), findings: FINDINGS, sbom_summary: { packages: 42, by_type: { deb: 30, pypi: 12 }, licenses: { MIT: 5 }, generator: 'syft-1.18.1' }, scanner: { version: '0.58.1', db_updated_at: '2026-10-10T06:00:00Z' }, sbom_formats: ['spdx-json', 'cyclonedx-json'] },
        decision: { applicable: true, blocked: true, violations: [{ rule: 'critical', message: '1 CRITICAL vulnerabilities (allowed: 0)', enforced: true }] },
      } })
    }
    if (path === '/projects/p1/versions/v1/security/scan') {
      this.rescans.push('v1')
      return this.json(route, { data: report({ status: 'pending', scan_status: 'pending' }) }, 202)
    }
    if (path === '/projects/p1/versions/v1/sbom') {
      this.sbomDownloads.push(url.searchParams.get('format') ?? '')
      return route.fulfill({ status: 200, contentType: 'application/spdx+json', headers: { 'Content-Disposition': 'attachment; filename="orders-api-v1.spdx.json"' }, body: '{"spdxVersion":"SPDX-2.3"}' })
    }

    // ── Region supply chain ──
    if (path === '/knative-regions/r1/supply-chain' && method === 'GET') return this.json(route, { data: this.settings })
    if (path === '/knative-regions/r1/supply-chain' && method === 'PUT') {
      this.regionUpdates.push(body)
      Object.assign(this.settings, body)
      return this.json(route, { data: this.settings })
    }
    if (path === '/knative-regions/r1/supply-chain/signing-key') {
      this.keyRequests++
      Object.assign(this.settings, { signing_status: 'ready', signing_public_key: '-----BEGIN PUBLIC KEY-----\nX\n-----END PUBLIC KEY-----\n', signing_key_fingerprint: 'cd'.repeat(32), signing_key_created_at: '2026-10-10T08:00:00Z' })
      return this.json(route, { data: this.settings }, 202)
    }
    if (path === '/knative-regions/r1/supply-chain/admission' && method === 'POST') {
      this.admissionRequests.push(body)
      Object.assign(this.settings, { admission_status: 'ready', admission_mode: body.mode ?? 'enforce' })
      return this.json(route, { data: this.settings }, 202)
    }
    if (path === '/knative-regions/r1/supply-chain/runtime-images') return this.json(route, { data: [] })

    // Everything else (deployments, nodes, metrics, statuses …): an empty list
    return this.json(route, { data: [] })
  }
}

test.beforeEach(async ({ page }) => {
  await page.addInitScript((user) => {
    localStorage.setItem('token', 'e2e-token')
    localStorage.setItem('user', JSON.stringify(user))
    localStorage.setItem('locale', 'en')
  }, ADMIN)
})

const modal = (page: Page, text: string | RegExp) => page.locator('div.fixed.inset-0').filter({ hasText: text }).last()

test('the Security tab shows the report of a version: badges, findings, SBOM and re-scan', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/projects/p1#security')
  await page.getByRole('button', { name: 'Security', exact: true }).click()

  const security = page.locator('[data-project-security]')
  await expect(security.locator('[data-policy-summary]')).toContainText('Region eu-1')
  await expect(security.locator('[data-policy-summary]')).toContainText('data location TR')
  await expect(security.locator('[data-policy-summary]')).toContainText('signature verified on deploy')

  const row = security.locator('[data-version-row="v1"]')
  await expect(row).toContainText('42 packages')
  await expect(row.locator('[data-severity="critical"]')).toHaveText('C1')
  await expect(row).toContainText('signed')

  // Details: findings with advisory links, SBOM summary, deploy decision
  await row.getByRole('button', { name: 'Details' }).click()
  const detail = security.locator('[data-version-detail]')
  await expect(detail).toContainText(DIGEST)
  await expect(detail.locator('[data-decision]')).toContainText('1 CRITICAL vulnerabilities (allowed: 0)')
  await expect(detail.locator('[data-sbom-summary]')).toContainText('deb 30')
  const link = detail.locator('[data-finding="CVE-2024-45491"] a')
  await expect(link).toHaveAttribute('href', 'https://nvd.nist.gov/vuln/detail/CVE-2024-45491')
  await expect(link).toHaveAttribute('target', '_blank')

  // Filter the findings
  await detail.locator('[data-filter-severity]').selectOption('HIGH')
  await expect(detail.locator('[data-finding]')).toHaveCount(1)
  await expect(detail.locator('[data-finding="CVE-2024-6345"]')).toContainText('70.0.0')

  // SBOM download
  const download = page.waitForEvent('download')
  await detail.locator('[data-download="spdx-json"]').click()
  expect((await download).suggestedFilename()).toBe('orders-api-v1.spdx.json')
  expect(api.sbomDownloads).toEqual(['spdx-json'])

  // Re-scan
  await row.getByTitle('Scan again').click()
  await expect(page.getByText('Scan queued.')).toBeVisible()
  expect(api.rescans).toEqual(['v1'])
})

test('a deploy the policy gate blocks goes through with an override and a reason', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/projects/p1')
  await page.getByRole('button', { name: 'Deploy', exact: true }).first().click()
  const deployModal = modal(page, 'Which version do you want to deploy?')
  await deployModal.getByRole('button', { name: 'Deploy', exact: true }).click()

  const block = page.locator('[data-policy-block]')
  await expect(block).toContainText('Deploy blocked')
  await expect(block.locator('[data-violation="critical"]')).toHaveText('1 CRITICAL vulnerabilities (allowed: 0)')
  await expect(block.locator('[data-override]')).toBeDisabled()

  await block.locator('[data-override-reason]').fill('CVE fixed upstream, rollout tonight')
  await block.locator('[data-override]').click()

  await expect(block).toHaveCount(0)
  expect(api.deploys).toEqual([
    { version_id: 'v1' },
    { version_id: 'v1', override_policy: true, override_reason: 'CVE fixed upstream, rollout tonight' },
  ])
})

test('Region → Supply chain: gate saved, key generated, admission installed', async ({ page }) => {
  const api = new FakeApi()
  await api.install(page)

  await page.goto('/settings')
  await page.locator('[data-region-row="eu-1"]').getByRole('button', { name: 'Edit', exact: true }).click()

  const regionModal = modal(page, 'Edit Region')
  await regionModal.getByRole('button', { name: 'Supply chain', exact: true }).click()
  const tab = regionModal.locator('[data-region-supply-chain]')

  // Policy gate
  await tab.locator('[data-field="gate_mode"]').selectOption('enforce')
  await tab.locator('[data-field="gate_max_high"]').fill('5')
  await tab.locator('[data-field="data_location"]').fill('TR')
  await tab.locator('[data-save]').click()
  await expect(page.getByText('Supply chain settings saved.')).toBeVisible()
  expect(api.regionUpdates).toEqual([{ gate_mode: 'enforce', gate_max_high: 5, data_location: 'TR' }])

  // Signing key → admission
  await expect(tab.locator('[data-install-admission]')).toBeDisabled()
  await tab.locator('[data-generate-key]').click()
  await expect(tab.locator('[data-fingerprint]')).toContainText('cd'.repeat(32))
  expect(api.keyRequests).toBe(1)

  await tab.locator('[data-install-admission]').click()
  await expect(tab.locator('[data-install-admission]')).toHaveText('Update policy')
  expect(api.admissionRequests).toEqual([{ mode: 'enforce' }])
  await expect(tab).not.toContainText('PRIVATE KEY')
})
