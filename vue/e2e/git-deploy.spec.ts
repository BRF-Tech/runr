import { test, expect, type Page } from './fixtures/test'
import type { MockApi } from './fixtures/mock-api'

// Git deploy (docs/git-deploy.md): the project's Git tab — remote URL, settings, connecting a
// Forgejo repository, previews, deploy history — and Settings → Git providers. Provider calls,
// webhooks and clusters are not part of a UI run: the API is mocked.

test.skip(({ e2eMode }) => e2eMode === 'stack', 'needs provider repositories and pull requests; covered with the mocked API (the Kind suite runs it for real: ci.sh test-git-deploy)')

type Json = Record<string, any>

const SETTINGS: Json = {
  deploy_branch: 'main', root_dir: null, auto_deploy: true, allow_force_push: false,
  previews_enabled: false, preview_limit: 3, preview_ttl_hours: 72, preview_env: {},
  fork_previews: false, fork_secrets: false,
}

/** In-memory git deploy API of one test, on top of the shared mock. */
function installGitApi(api: MockApi, projectId = 'p-faas') {
  const state: Json = {
    settings: { ...SETTINGS },
    connection: null as Json | null,
    providers: [
      { id: 'fj', type: 'forgejo', name: 'Code', base_url: 'https://code.example.com', web_url: 'https://code.example.com', api_url: null,
        is_enabled: true, configured: true, connect_modes: ['pat'], has_client_secret: false, has_private_key: false, has_webhook_secret: false, links_count: 0, urls: {} },
    ],
    previews: [
      { id: 'pv12', number: 12, provider: 'forgejo', title: 'Add order lookup', branch: 'feature/lookup', base_branch: 'main', short_sha: 'b20867b',
        pr_url: 'https://code.example.com/acme/orders/pulls/12', is_fork: false, url: 'https://orders-api-pr-12.eu-1.runr.test',
        status: 'ready', status_message: null, expires_at: '2026-10-13T12:00:00Z' },
    ],
    deploys: [
      { id: 'd1', kind: 'production', trigger: 'push', provider: 'runr', sha: 'a10867b14bb761a232cd80139fbd4c0d33264240', short_sha: 'a10867b',
        branch: 'main', author_name: 'Ada Lovelace', message: 'fix: answer with the order id', url: null, pr_number: null, status: 'active',
        status_message: null, warnings: [], skipped: [{ path: '.github/ci.yml', reason: 'hidden' }], applied: {}, version: { id: 'v3', number: 3, label: 'v3' },
        created_at: '2026-10-10T12:00:00Z' },
      { id: 'd2', kind: 'preview', trigger: 'pull_request', provider: 'forgejo', sha: 'b20867b14bb761a232cd80139fbd4c0d33264241', short_sha: 'b20867b',
        branch: 'feature/lookup', author_name: 'grace', message: 'Add order lookup', url: 'https://code.example.com/acme/orders/pulls/12', pr_number: 12,
        status: 'failed', status_message: 'The handler file index.js is not in the tree', warnings: [], skipped: [], applied: {}, version: null,
        created_at: '2026-10-10T13:00:00Z' },
    ],
  }

  const info = () => ({
    remote_url: 'http://127.0.0.1/git/runr/orders-api.git',
    remote_ready: true,
    remote_head: 'a10867b14bb761a232cd80139fbd4c0d33264240',
    read_only: state.connection !== null,
    settings: state.settings,
    runr_yaml: null,
    connection: state.connection,
    providers: state.providers.filter((p: Json) => p.is_enabled && p.configured),
    limits: { preview_limit_max: 20, preview_ttl_max: 720, max_push_mb: 50 },
    abilities: { pull: true, push: true, manage: true, manage_previews: true },
    username_hint: 'admin',
  })

  api.on('GET', `/projects/${projectId}/git`, () => ({ body: { data: info() } }))
  api.on('PUT', `/projects/${projectId}/git`, (req) => {
    if (req.body.deploy_branch?.includes('..')) {
      return { status: 422, body: { message: 'The deploy branch field format is invalid.', errors: { deploy_branch: ['The deploy branch field format is invalid.'] } } }
    }
    Object.assign(state.settings, req.body)
    return { body: { data: info() } }
  })
  api.on('GET', `/projects/${projectId}/git/deploys`, () => ({ body: { data: state.deploys, meta: { total: state.deploys.length } } }))
  api.on('GET', `/projects/${projectId}/previews`, () => ({ body: { data: state.previews } }))
  api.on('DELETE', new RegExp(`^/projects/${projectId}/previews/([^/]+)$`), (req) => {
    const id = req.path.split('/').pop()
    state.previews = state.previews.filter((p: Json) => p.id !== id)
    return { body: { data: { id, status: 'deleted' } } }
  })
  api.on('POST', `/projects/${projectId}/git/repos`, (req) => (req.body.access_token === 'pat-secret'
    ? { body: { data: [{ id: '4242', full_name: 'acme/orders', url: 'https://code.example.com/acme/orders', default_branch: 'main', private: true }] } }
    : { status: 422, body: { message: 'The provider did not answer.', errors: { provider_id: ['The provider did not answer: unauthorized'] } } }))
  api.on('POST', `/projects/${projectId}/git/connect`, (req) => {
    state.connection = {
      id: 'l1', provider_id: req.body.provider_id, provider_type: 'forgejo', provider_name: 'Code', repo_id: '4242',
      repo_full_name: req.body.repository, repo_url: 'https://code.example.com/acme/orders', default_branch: 'main',
      auth_mode: 'pat', webhook_registered: true, last_event_at: null,
    }
    return { status: 201, body: { data: info() } }
  })
  api.on('DELETE', `/projects/${projectId}/git/connect`, () => {
    state.connection = null
    return { body: { data: info() } }
  })

  // Settings → Git providers
  api.on('GET', '/git-providers', () => ({ body: { data: state.providers } }))
  api.on('POST', '/git-providers', (req) => {
    const provider = {
      id: `gp-${state.providers.length + 1}`, ...req.body, web_url: req.body.base_url ?? 'https://github.com', configured: true,
      connect_modes: req.body.type === 'github' ? ['app'] : ['pat'], has_private_key: !!req.body.private_key,
      has_webhook_secret: !!req.body.webhook_secret, has_client_secret: !!req.body.client_secret, links_count: 0,
      urls: req.body.type === 'github'
        ? { webhook: 'http://127.0.0.1/api/git/webhooks/github/gp-2', setup: 'http://127.0.0.1/api/git/github/setup', callback: 'http://127.0.0.1/api/git/github/setup' }
        : {},
    }
    state.providers.push(provider)
    return { status: 201, body: { data: provider } }
  })

  return state
}

async function openGitTab(page: Page) {
  await page.goto('/projects/p-faas#git')
  await expect(page.getByTestId('git-tab')).toBeVisible()
}

test.describe('Git deploy', () => {
  test('shows the remote, saves the deploy settings and turns previews on', async ({ page, auth, mockApi }) => {
    const state = installGitApi(mockApi)
    await auth.signIn()
    await openGitTab(page)

    await expect(page.getByTestId('git-remote-url')).toHaveValue('http://127.0.0.1/git/runr/orders-api.git')
    await expect(page.getByTestId('git-remote-commands')).toContainText('git push runr main')
    await expect(page.getByTestId('git-history')).toContainText('a10867b')
    await expect(page.getByTestId('git-history')).toContainText('Ada Lovelace')
    await expect(page.getByTestId('git-history')).toContainText('Preview #12')

    // An invalid branch is refused by the API and shown
    await page.getByTestId('git-deploy-branch').fill('bad..branch')
    await page.getByTestId('git-save').click()
    await expect(page.getByTestId('git-save-error')).toContainText('format is invalid')

    await page.getByTestId('git-deploy-branch').fill('release')
    await page.getByTestId('git-root-dir').fill('services/orders')
    await page.getByTestId('git-previews-enabled').click()
    await page.getByTestId('git-preview-limit').fill('5')
    await page.getByTestId('git-preview-env').fill('API_URL=https://staging.example.com')

    const sent = mockApi.waitFor('PUT', '/projects/p-faas/git')
    await page.getByTestId('git-save').click()
    expect((await sent).body).toMatchObject({
      deploy_branch: 'release', root_dir: 'services/orders', previews_enabled: true, preview_limit: 5,
      preview_env: { API_URL: 'https://staging.example.com' },
    })
    await expect(page.getByText('Git settings saved.')).toBeVisible()
    expect(state.settings.deploy_branch).toBe('release')
    await expect(page.getByTestId('git-remote-commands')).toContainText('git push runr release')
  })

  test('connects a Forgejo repository with an access token and disconnects it', async ({ page, auth, mockApi }) => {
    installGitApi(mockApi)
    await auth.signIn()
    await openGitTab(page)

    await page.getByTestId('git-access-token').fill('wrong')
    await page.getByTestId('git-load-repos').click()
    await expect(page.getByTestId('git-connect-error')).toContainText('unauthorized')

    await page.getByTestId('git-access-token').fill('pat-secret')
    await page.getByTestId('git-load-repos').click()
    await expect(page.getByTestId('git-repo-list')).toContainText('acme/orders')

    const sent = mockApi.waitFor('POST', '/projects/p-faas/git/connect')
    await page.getByTestId('git-connect-submit').click()
    expect((await sent).body).toEqual({ provider_id: 'fj', repository: 'acme/orders', access_token: 'pat-secret' })

    await expect(page.getByTestId('git-connection-repo')).toContainText('acme/orders')
    await expect(page.getByTestId('git-read-only')).toBeVisible()
    await expect(page.getByTestId('git-remote-commands')).toHaveCount(0)

    await page.getByTestId('git-disconnect').click()
    await page.locator('div.fixed.inset-0').getByRole('button', { name: 'Disconnect' }).click()
    await expect(page.getByTestId('git-connect-form')).toBeVisible()
  })

  test('lists previews with their URL and deletes one', async ({ page, auth, mockApi }) => {
    installGitApi(mockApi)
    await auth.signIn()
    await openGitTab(page)

    const row = page.locator('[data-preview="12"]')
    await expect(row).toContainText('#12')
    await expect(row).toContainText('Ready')
    await expect(row.getByTestId('git-preview-url')).toHaveAttribute('href', 'https://orders-api-pr-12.eu-1.runr.test')

    const deleted = mockApi.waitFor('DELETE', '/projects/p-faas/previews/:id')
    await row.getByTestId('git-preview-delete').click()
    await page.locator('div.fixed.inset-0').getByRole('button', { name: 'Delete' }).click()
    expect((await deleted).path).toBe('/projects/p-faas/previews/pv12')
    await expect(page.getByTestId('git-previews-empty')).toBeVisible()
  })

  test('shows the reason of a failed preview deploy in the history', async ({ page, auth, mockApi }) => {
    installGitApi(mockApi)
    await auth.signIn()
    await openGitTab(page)

    const failed = page.locator('[data-deploy="b20867b"]')
    await expect(failed).toContainText('Failed')
    await failed.getByRole('button', { name: 'Details' }).click()
    await expect(page.getByText('The handler file index.js is not in the tree')).toBeVisible()
  })

  test('an admin adds a GitHub App in Settings → Git providers', async ({ page, auth, mockApi }) => {
    installGitApi(mockApi)
    await auth.signIn()
    await page.goto('/settings?tab=git-providers')

    await expect(page.getByTestId('git-providers')).toContainText('Code')
    await page.getByTestId('git-provider-add').click()
    const modal = page.getByTestId('git-provider-modal')
    await modal.getByTestId('gp-name').fill('GitHub')
    await modal.getByTestId('gp-app-id').fill('12345')
    await modal.getByTestId('gp-app-slug').fill('runr-e2e')
    await modal.getByTestId('gp-private-key').fill('-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----')
    await modal.getByTestId('gp-client-id').fill('Iv1.e2e')
    await modal.getByTestId('gp-client-secret').fill('client-secret')
    await modal.getByRole('button', { name: 'Generate' }).click()

    const sent = mockApi.waitFor('POST', '/git-providers')
    await modal.getByTestId('gp-submit').click()
    const body = (await sent).body
    expect(body).toMatchObject({ type: 'github', name: 'GitHub', app_id: '12345', app_slug: 'runr-e2e', client_id: 'Iv1.e2e' })
    expect(body.webhook_secret).toMatch(/^[0-9a-f]{48}$/)

    const card = page.locator('[data-provider="GitHub"]')
    await expect(card).toContainText('http://127.0.0.1/api/git/webhooks/github/gp-2')
    await expect(card).toContainText('Setup URL')
  })
})
