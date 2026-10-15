import { test, expect } from './fixtures/test'
import { openEditStep } from './helpers/edit'
import { confirmDialog, continueButton } from './helpers/ui'
import type { MockApi } from './fixtures/mock-api'

// Secret variables (docs/security.md): step 4 of the Edit Project modal, below the plain
// KEY=value editor. Values are write-only — set, replaced and removed, never shown back unless
// the user may view values.

interface SecretRow { name: string; value: string; updated_at: string }

/** Mock mode: an in-memory secrets endpoint for one project. */
function mockSecrets(mockApi: MockApi, projectId: string, canViewValues = false) {
  const rows: SecretRow[] = []
  const list = () => rows.map(({ name, updated_at }) => ({ name, updated_at, updated_by: { id: 1, name: 'Admin' } }))
  mockApi
    .on('GET', `/projects/${projectId}/secrets`, () => ({ body: { data: list(), can_view_values: canViewValues } }))
    .on('PUT', `/projects/${projectId}/secrets/:name`, req => {
      const existing = rows.find(r => r.name === req.params.name)
      if (existing) existing.value = req.body.value
      else rows.push({ name: req.params.name, value: req.body.value, updated_at: new Date().toISOString() })
      return { status: existing ? 200 : 201, body: { data: { name: req.params.name }, requires_deploy: true } }
    })
    .on('DELETE', `/projects/${projectId}/secrets/:name`, req => {
      const i = rows.findIndex(r => r.name === req.params.name)
      if (i === -1) return { status: 404, body: { message: 'Not found.' } }
      rows.splice(i, 1)
      return { body: { requires_deploy: true } }
    })
    .on('GET', `/projects/${projectId}/secrets/:name/value`, req => canViewValues
      ? { body: { name: req.params.name, value: rows.find(r => r.name === req.params.name)?.value } }
      : { status: 403, body: { message: 'This action is unauthorized.' } })
  return rows
}

test.describe('Secret environment variables', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('adds a secret write-only, replaces it and removes it', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    const rows = isStack ? null : mockSecrets(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)

    const modal = await openEditStep(page, 4)
    const editor = modal.locator('[data-testid="secret-env-editor"]')
    await expect(editor.getByText(/^Secret variables/)).toBeVisible()
    await expect(editor.getByText('No secret variables.')).toBeVisible()

    await editor.locator('[data-testid="secret-new-name"]').fill('DB_PASSWORD')
    await editor.locator('[data-testid="secret-new-value"]').fill('e2e-secret-value')
    const added = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}/secrets/DB_PASSWORD`)
    await editor.locator('[data-testid="secret-add"]').click()
    if (added) expect((await added).body).toEqual({ value: 'e2e-secret-value' })

    const row = editor.locator('[data-testid="secret-row-DB_PASSWORD"]')
    await expect(row).toBeVisible()
    await expect(row.locator('[data-testid="secret-value-DB_PASSWORD"]')).toHaveText('••••••••')
    await expect(page.getByText('e2e-secret-value')).toHaveCount(0)
    // The value input is a password field and empty again
    await expect(editor.locator('[data-testid="secret-new-value"]')).toHaveAttribute('type', 'password')
    await expect(editor.locator('[data-testid="secret-new-value"]')).toHaveValue('')

    await row.locator('[data-testid="secret-replace-DB_PASSWORD"]').click()
    await editor.locator('[data-testid="secret-replace-input-DB_PASSWORD"]').fill('rotated-value')
    const replaced = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}/secrets/DB_PASSWORD`)
    await editor.getByRole('button', { name: 'Save', exact: true }).click()
    if (replaced) expect((await replaced).body).toEqual({ value: 'rotated-value' })
    await expect(editor.locator('[data-testid="secret-replace-input-DB_PASSWORD"]')).toHaveCount(0)
    if (rows) expect(rows[0].value).toBe('rotated-value')

    await row.locator('[data-testid="secret-remove-DB_PASSWORD"]').click()
    await confirmDialog(page, 'Remove')
    await expect(editor.locator('[data-testid="secret-row-DB_PASSWORD"]')).toHaveCount(0)
    await expect(editor.getByText('No secret variables.')).toBeVisible()
  })

  test('a name is either plain or secret', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas', { config: { env_vars: { LOG_LEVEL: 'info' } } })
    if (!isStack) mockSecrets(mockApi, project.id)
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)

    const modal = await openEditStep(page, 4)
    const editor = modal.locator('[data-testid="secret-env-editor"]')
    await editor.locator('[data-testid="secret-new-name"]').fill('LOG_LEVEL')
    await editor.locator('[data-testid="secret-new-value"]').fill('x')
    await editor.locator('[data-testid="secret-add"]').click()
    await expect(editor.locator('[data-testid="secret-error"]')).toContainText('"LOG_LEVEL" is a plain variable above')

    await editor.locator('[data-testid="secret-new-name"]').fill('PORT')
    await editor.locator('[data-testid="secret-add"]').click()
    await expect(editor.locator('[data-testid="secret-error"]')).toContainText('"PORT" is set by the platform')
  })

  test('owners can show a value for a moment', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'needs a user with secrets.view_values on the mocked API')
    const rows = mockSecrets(mockApi, 'p-xaas', true)
    rows.push({ name: 'API_TOKEN', value: 'shown-on-request', updated_at: '2026-10-01T10:00:00Z' })
    await auth.signIn()
    await page.goto('/projects/p-xaas')

    const modal = await openEditStep(page, 4)
    const value = modal.locator('[data-testid="secret-value-API_TOKEN"]')
    await expect(value).toHaveText('••••••••')
    const revealed = mockApi.waitFor('GET', '/projects/p-xaas/secrets/API_TOKEN/value')
    await modal.locator('[data-testid="secret-reveal-API_TOKEN"]').click()
    await revealed
    await expect(value).toHaveText('shown-on-request')
    await modal.locator('[data-testid="secret-reveal-API_TOKEN"]').click()
    await expect(value).toHaveText('••••••••')
  })

  test('secrets added while creating a project are saved once it exists', async ({ page, auth, seed, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the request order on the mocked API')
    const region = await seed.readyRegion()
    await auth.signIn()
    await page.goto('/projects')
    await page.getByRole('button', { name: '+ New Project' }).click()

    // Steps 1–3 as in create-project.spec.ts
    await page.locator('[data-tour="create-name"] input').first().fill('with-secrets')
    await page.locator('[data-tour="create-region"]').getByRole('button', { name: new RegExp(region!.name) }).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: /Code Snippet/ }).click()
    await continueButton(page).click()
    await continueButton(page).click()
    await expect(page.getByText('Step 4 / 4')).toBeVisible()

    // Step 4: the secret waits in the form (no API call yet)
    const editor = page.locator('[data-testid="secret-env-editor"]')
    await editor.locator('[data-testid="secret-new-name"]').fill('PAYMENT_API_KEY')
    await editor.locator('[data-testid="secret-new-value"]').fill('sk_test_e2e')
    await editor.locator('[data-testid="secret-add"]').click()
    await expect(editor.getByText('Saved when the project is created')).toBeVisible()
    expect(mockApi.calls('PUT', /\/secrets\//)).toHaveLength(0)

    const created = mockApi.waitFor('POST', '/projects')
    const saved = mockApi.waitFor('PUT', /\/projects\/[^/]+\/secrets\/PAYMENT_API_KEY$/)
    await page.getByRole('button', { name: 'Create Project' }).click()

    const { body } = await created
    expect(JSON.stringify(body)).not.toContain('sk_test_e2e')
    const put = await saved
    expect(put.body).toEqual({ value: 'sk_test_e2e' })

    // The project lists the secret by name, write-only (the mocked API keeps the name, not the value)
    await page.goto(`/projects/${put.path.split('/')[2]}`)
    const modal = await openEditStep(page, 4)
    await expect(modal.locator('[data-testid="secret-value-PAYMENT_API_KEY"]')).toHaveText('••••••••')
    await expect(page.getByText('sk_test_e2e')).toHaveCount(0)
  })
})
