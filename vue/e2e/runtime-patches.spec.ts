import { test, expect } from './fixtures/test'
import { confirmDialog } from './helpers/ui'
import type { Json } from './fixtures/data'

// Runtime patches (docs/runtimes.md#runtime-patches): the project badge and its dialog (why the
// patch came, apply, pin, unpin), the zero-day banner that stays until the projects are moved, and
// the platform administrators' Patches page (feed, policy, remind, force, extend the deadline).

test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs a signed patch feed; covered with the mocked API')

const ISO = (d: string) => new Date(d).toISOString()
const DIGEST = (c: string) => 'sha256:' + c.repeat(64)

function patch(tag: string, level: string, extra: Json = {}): Json {
  return {
    id: `python-3.12-${tag}`, runtime: 'python', version: '3.12', tag, image: 'ghcr.io/brf-tech/runr/exec-python',
    digest: DIGEST(tag.endsWith('r2') ? 'b' : 'a'), level,
    reason: level === 'zero-day' ? 'Remote code execution in the HTTP parser.' : 'OpenSSL security update.',
    reasons: { en: level === 'zero-day' ? 'Remote code execution in the HTTP parser.' : 'OpenSSL security update.', tr: 'Güvenlik güncellemesi.' },
    cves: level === 'routine' ? [] : ['CVE-2026-1234'], url: 'https://example.test/advisory', upstream: 'Python 3.12.9',
    published_at: ISO('2026-10-20T08:00:00Z'), deadline_at: null, deadline_extended: false, in_feed: true, ...extra,
  }
}

const R1 = patch('3.12.9-r1', 'routine', { published_at: ISO('2026-10-01T08:00:00Z') })
const R2 = patch('3.12.9-r2', 'security')
const summary = (p: Json) => ({ id: p.id, tag: p.tag, level: p.level, digest: p.digest, published_at: p.published_at })

function state(extra: Json = {}): Json {
  return {
    state: 'patch_available', level: 'security', deadline_at: null, deployed: true,
    current: summary(R1), latest: summary(R2), pending: [R2.id], pin: null, ...extra,
  }
}

function withPatchState(db: Json, patchState: Json) {
  const project = db.projects.find((p: Json) => p.id === 'p-xaas')
  project.runtime_patch = patchState
}

test.describe('Runtime patches of a project', () => {
  test('the card badge opens the dialog, which says why and applies the newest patch', async ({ page, auth, mockApi }) => {
    withPatchState(mockApi.db, state())
    mockApi.on('GET', '/projects/:id/runtime-patches', () => ({ body: { data: { state: state(), patches: [R2, R1], keep: 5, auto_migrate: true, migrations: [] } } }))
    mockApi.on('POST', '/projects/:id/runtime-patches/apply', () => ({ status: 202, body: {
      data: { id: 'm1', patch_id: R2.id, trigger: 'apply', status: 'started', deployment_id: 'd-new' }, message: 'Redeploying the live version onto 3.12.9-r2.',
    } }))

    await auth.signIn()
    await page.goto('/projects')
    const card = page.locator('#p-hello-snippet')
    const badge = card.locator('[data-test="runtime-patch-badge-available"]')
    await expect(badge).toHaveText(/Security patch/)
    await badge.click()

    // The badge opens the dialog, not the project page
    await expect(page).toHaveURL(/\/projects$/)
    const dialog = page.locator('[data-test="runtime-patch-dialog"]')
    await expect(dialog.locator('[data-test="runtime-patch-current"]')).toHaveText('3.12.9-r1')
    await expect(dialog.locator('[data-test="runtime-patch-latest"]')).toHaveText('3.12.9-r2')
    const row = dialog.locator('[data-test="runtime-patch-row-3.12.9-r2"]')
    await expect(row.locator('[data-test="runtime-patch-level"]')).toHaveText('Security')
    await expect(row.locator('[data-test="runtime-patch-reason"]')).toHaveText('OpenSSL security update.')
    await expect(row.getByRole('link', { name: 'CVE-2026-1234' })).toHaveAttribute('href', 'https://www.cve.org/CVERecord?id=CVE-2026-1234')

    const sent = mockApi.waitFor('POST', '/projects/p-xaas/runtime-patches/apply')
    await dialog.locator('[data-test="runtime-patch-apply"]').click()
    await confirmDialog(page, 'Apply newest patch')
    await sent
    await expect(page.getByText('Redeploying onto the newest patch.')).toBeVisible()
  })

  test('pins the project to an older patch and unpins it again', async ({ page, auth, mockApi }) => {
    let current = state()
    withPatchState(mockApi.db, current)
    mockApi.on('GET', '/projects/:id/runtime-patches', () => ({ body: { data: { state: current, patches: [R2, R1], keep: 5, auto_migrate: true, migrations: [] } } }))
    mockApi.on('PUT', '/projects/:id/runtime-patches/pin', (req) => {
      current = state({ state: 'pinned', pin: { id: req.body.patch_id, tag: R1.tag, digest: R1.digest, available: true, by: '1' } })
      return { status: 200, body: { data: current, migration: null } }
    })
    mockApi.on('DELETE', '/projects/:id/runtime-patches/pin', () => {
      current = state()
      return { body: { data: current } }
    })

    await auth.signIn()
    await page.goto('/projects/p-xaas')
    await page.locator('[data-test="runtime-patch-badge-available"]').click()
    const dialog = page.locator('[data-test="runtime-patch-dialog"]')

    const pinned = mockApi.waitFor('PUT', '/projects/p-xaas/runtime-patches/pin')
    await dialog.locator('[data-test="runtime-patch-pin-3.12.9-r1"]').click()
    await confirmDialog(page, 'Pin')
    expect((await pinned).body).toEqual({ patch_id: R1.id, deploy: true })
    await expect(dialog.locator('[data-test="runtime-patch-pin"]')).toContainText('Pinned 3.12.9-r1')

    const unpinned = mockApi.waitFor('DELETE', '/projects/p-xaas/runtime-patches/pin')
    await dialog.locator('[data-test="runtime-patch-unpin"]').click()
    await unpinned
    await expect(dialog.locator('[data-test="runtime-patch-pin"]')).toHaveCount(0)
  })

  test('a zero-day banner stays until the projects are moved', async ({ page, auth, mockApi }) => {
    const zeroDay = patch('3.12.9-r3', 'zero-day', { deadline_at: ISO('2026-10-23T08:00:00Z') })
    let banners: Json[] = [{ kind: 'runtime_patch', level: 'zero-day', overdue: false, patch: zeroDay, project_count: 1, projects: [{ id: 'p-xaas', name: 'Hello Snippet' }] }]
    mockApi.on('GET', '/runtime-patches/banners', () => ({ body: { data: banners } }))

    await auth.signIn()
    await page.goto('/projects')
    const banner = page.locator('[data-test="runtime-patch-banner-runtime_patch"]')
    await expect(banner).toContainText('Zero-day runtime patch 3.12.9-r3 for python 3.12: Remote code execution in the HTTP parser.')
    await expect(banner).toContainText('Projects behind it (1): Hello Snippet')
    // Not dismissible
    await expect(banner.locator('[data-test="runtime-patch-banner-dismiss-runtime_patch"]')).toHaveCount(0)

    await banner.locator('[data-test="runtime-patch-banner-open"]').click()
    await expect(page).toHaveURL(/\/projects\/p-xaas/)

    // Migrated: the next read no longer returns it (a reload reads the banners again)
    banners = []
    await page.reload()
    await expect(page.locator('[data-test="runtime-patch-banners"]')).toHaveCount(0)
  })
})

test.describe('Patches page (platform administrators)', () => {
  const ZERO = patch('3.12.9-r3', 'zero-day', { deadline_at: ISO('2026-10-23T08:00:00Z') })

  function adminPayload(feed: Json = {}): Json {
    return { data: {
      feed: {
        reference: 'ghcr.io/brf-tech/runr/runtime-patches:feed', enabled: true, status: 'ok', error: null,
        manifest_digest: DIGEST('c'), signature_verified: true, generated_at: ISO('2026-10-21T06:00:00Z'),
        checked_at: ISO('2026-10-21T08:00:00Z'), fetched_at: ISO('2026-10-21T06:05:00Z'), via_region: null, auto_migrate: true, ...feed,
      },
      key_configured: true,
      running_version: '0.5.10',
      patches: [{ ...ZERO, announced_at: ISO('2026-10-21T06:06:00Z'), removed_at: null, overdue: false, affected: 3,
        organizations: [{ tenant_id: null, name: null, affected: 3, migrations: { failed: 1 } }] }],
      advisories: [{ version: '0.5.11', level: 'zero-day', reason: 'Fixes a sign-in bypass.', url: 'https://example.test/0.5.11', published_at: ISO('2026-10-21T06:00:00Z'), newer: true }],
    } }
  }

  test('shows the feed and runs the actions', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/admin/runtime-patches', () => ({ body: adminPayload() }))
    mockApi.on('POST', '/admin/runtime-patches/check', () => ({ body: { data: adminPayload().data.feed } }))
    mockApi.on('PUT', '/admin/runtime-patches/policy', (req) => ({ body: { data: adminPayload({ auto_migrate: req.body.auto_migrate }).data.feed } }))
    mockApi.on('POST', /^\/admin\/runtime-patches\/[^/]+\/remind$/, () => ({ body: { data: { told: 4 } } }))
    mockApi.on('POST', /^\/admin\/runtime-patches\/[^/]+\/force$/, () => ({ status: 202, body: { data: { started: 3 } } }))
    mockApi.on('PUT', /^\/admin\/runtime-patches\/[^/]+\/deadline$/, (req) => ({ body: { data: { ...ZERO, deadline_at: req.body.deadline_at, deadline_extended: true } } }))

    await auth.signIn()
    await page.goto('/settings?tab=patches')
    const manager = page.locator('[data-test="runtime-patches-manager"]')
    await expect(manager.locator('[data-test="runtime-patches-feed-status"]')).toHaveText('OK')
    await expect(manager.locator(`[data-test="runtime-patches-affected-${ZERO.id}"]`)).toHaveText('3')
    await expect(manager.locator('[data-test="runtime-patches-advisories"]')).toContainText('RunR 0.5.11')

    const checked = mockApi.waitFor('POST', '/admin/runtime-patches/check')
    await manager.locator('[data-test="runtime-patches-check"]').click()
    await checked

    const policy = mockApi.waitFor('PUT', '/admin/runtime-patches/policy')
    await manager.locator('[data-test="runtime-patches-policy-toggle"]').click()
    expect((await policy).body).toEqual({ auto_migrate: false })

    const reminded = mockApi.waitFor('POST', /^\/admin\/runtime-patches\/[^/]+\/remind$/)
    await manager.locator(`[data-test="runtime-patches-remind-${ZERO.id}"]`).click()
    expect((await reminded).path).toBe(`/admin/runtime-patches/${ZERO.id}/remind`)
    await expect(page.getByText('4 user(s) notified again.')).toBeVisible()

    const forced = mockApi.waitFor('POST', /^\/admin\/runtime-patches\/[^/]+\/force$/)
    await manager.locator(`[data-test="runtime-patches-force-${ZERO.id}"]`).click()
    await confirmDialog(page, 'Force now')
    await forced
    await expect(page.getByText('3 migration(s) started.')).toBeVisible()

    const extended = mockApi.waitFor('PUT', /^\/admin\/runtime-patches\/[^/]+\/deadline$/)
    await manager.locator(`[data-test="runtime-patches-extend-${ZERO.id}"]`).click()
    await page.locator('[data-test="runtime-patches-extend-input"]').fill('2026-10-30T12:00')
    await page.locator('[data-test="runtime-patches-extend-save"]').click()
    expect((await extended).body.deadline_at).toBe(new Date('2026-10-30T12:00').toISOString())
    await expect(manager.locator(`[data-test="runtime-patches-deadline-${ZERO.id}"]`)).toContainText('extended')
  })

  test('a refused feed shows its error and a banner for platform administrators', async ({ page, auth, mockApi }) => {
    mockApi.on('GET', '/admin/runtime-patches', () => ({ body: adminPayload({ status: 'invalid_signature', error: 'No signature verifies with the configured public key', signature_verified: false }) }))
    mockApi.on('GET', '/runtime-patches/banners', () => ({ body: { data: [{ kind: 'feed_invalid', level: 'critical', status: 'invalid_signature', error: 'No signature verifies with the configured public key' }] } }))

    await auth.signIn()
    await page.goto('/settings?tab=patches')
    await expect(page.locator('[data-test="runtime-patch-banner-feed_invalid"]')).toContainText('The runtime patch feed was refused (invalid_signature)')
    const manager = page.locator('[data-test="runtime-patches-manager"]')
    await expect(manager.locator('[data-test="runtime-patches-feed-status"]')).toHaveText('Invalid signature')
    await expect(manager.locator('[data-test="runtime-patches-feed-error"]')).toHaveText('No signature verifies with the configured public key')
  })
})
