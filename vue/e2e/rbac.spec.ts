import { test, expect, type Auth } from './fixtures/test'
import { ALL_PERMISSIONS, effectivePermissions } from './fixtures/data'
import { uniqueName, type Seeder } from './helpers/seed'
import { openProjectTab } from './helpers/ui'

// Roles & permissions (docs/permissions.md): custom roles with the permission matrix, member
// roles, groups, project access, scoped tokens and the permission-aware console.

/**
 * Roles and groups belong to an organization. On a multi-tenant stack the platform's central
 * context has none (no Roles tab there), so the stack specs work in the first tenant of the
 * stack account. Returns the headers that address that organization in API calls.
 */
async function signInToOrganization(auth: Auth, seed: Seeder, isStack: boolean): Promise<Record<string, string>> {
  if (!isStack || (await seed.call('get', '/api/settings')).tenancy_enabled !== true) {
    await auth.signIn()
    return {}
  }
  const tenant = ((await seed.call('get', '/api/tenant/current')).tenants ?? [])[0]
  test.skip(!tenant, 'the stack account belongs to no tenant, and roles live in a tenant on a multi-tenant stack')
  await auth.signIn({ activeTenant: tenant })
  return { 'X-Tenant-ID': String(tenant.id) }
}

test.describe('Roles and permissions', () => {
  test('creates a custom role from the permission matrix', async ({ page, auth, mockApi, seed, isStack }) => {
    const name = uniqueName('Release')
    const key  = name.toLowerCase()
    const organization = await signInToOrganization(auth, seed, isStack)
    if (isStack) seed.onCleanup(() => seed.call('delete', `/api/roles/${key}`, undefined, organization))
    await page.goto('/settings?tab=roles')
    await expect(page.getByTestId('roles-manager')).toBeVisible()

    await page.getByTestId('new-role').click()
    await page.getByTestId('role-name').fill(name)
    await page.locator('[data-permission="projects.deploy"] input').check()
    // projects.view comes with projects.deploy and is locked
    await expect(page.locator('[data-permission="projects.view"] input')).toBeChecked()
    await expect(page.locator('[data-permission="projects.view"] input')).toBeDisabled()

    const sent = isStack ? null : mockApi.waitFor('POST', '/roles')
    await page.getByTestId('save-role').click()
    if (sent) expect((await sent).body).toMatchObject({ name, permissions: ['projects.deploy'] })
    await expect(page.locator(`[data-role="${key}"]`)).toBeVisible()
  })

  test('built-in roles are read-only', async ({ page, auth, seed, isStack }) => {
    await signInToOrganization(auth, seed, isStack)
    await page.goto('/settings?tab=roles')
    await page.locator('[data-role="developer"] button').first().click()
    const boxes = page.locator('[data-role="developer"] [data-testid="permission-matrix"] input')
    await expect(boxes.first()).toBeDisabled()
    await expect(page.locator('[data-role="developer"]').getByRole('button', { name: /edit|delete/i })).toHaveCount(0)
  })

  test('changes a member role on the users page', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'uses the seeded mock member')
    await auth.signIn()
    await page.goto('/settings?tab=users')

    const sent = mockApi.waitFor('PUT', '/members/:id/role')
    await page.locator('[data-user-role="bob"]').selectOption('viewer')
    expect((await sent).body).toEqual({ role: 'viewer' })
    await expect(page.getByText('Role updated.')).toBeVisible()
  })

  test('a group gives its members a role', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'uses the seeded mock member')
    await auth.signIn()
    await page.goto('/settings?tab=groups')

    await page.getByTestId('new-group').click()
    await page.getByTestId('group-name').fill('SRE')
    await page.getByTestId('group-role').selectOption('developer')
    const created = mockApi.waitFor('POST', '/groups')
    await page.getByTestId('save-group').click()
    expect((await created).body).toMatchObject({ name: 'SRE', role: 'developer' })

    await page.locator('[data-group="SRE"] button').first().click()
    await page.getByTestId('group-add-members').selectOption('2')
    const added = mockApi.waitFor('POST', '/groups/:id/members')
    await page.getByRole('button', { name: 'Add members' }).click()
    expect((await added).body).toEqual({ user_ids: [2] })
    await expect(page.locator('[data-group="SRE"]')).toContainText('Bob Builder')
  })

  test('grants a role on one project only', async ({ page, auth, mockApi, seed, isStack }) => {
    test.skip(isStack, 'uses the seeded mock member')
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    await openProjectTab(page, 'Access')
    await expect(page.getByTestId('project-access')).toBeVisible()

    await page.getByTestId('access-subject').selectOption('2')
    await page.getByTestId('access-role').selectOption('developer')
    const sent = mockApi.waitFor('POST', '/projects/:id/access')
    await page.getByTestId('grant-access').click()
    expect((await sent).body).toEqual({ subject_type: 'user', subject_id: '2', role: 'developer' })
    await expect(page.locator('[data-grant="Bob Builder"]')).toBeVisible()
  })

  test('creates a token with a subset of the permissions', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the abilities payload on the mocked API')
    await auth.signIn()
    await page.goto('/settings?tab=tokens')
    await page.getByPlaceholder('e.g. CI/CD, Mobile App').fill('logs-reader')
    await page.locator('[data-tour="tokens-custom-access"]').click()
    await page.getByTestId('token-scopes').getByText('View logs', { exact: true }).click()
    const sent = mockApi.waitFor('POST', '/tokens')
    await page.getByRole('button', { name: 'Create Token' }).click()
    expect((await sent).body).toMatchObject({ name: 'logs-reader', abilities: ['logs.view'] })
  })

  test('a viewer sees projects without the actions they cannot use', async ({ page, auth, mockApi, seed, isStack }) => {
    test.skip(isStack, 'narrows the mocked permissions')
    const project = await seed.project('xaas')
    mockApi.db.permissions = effectivePermissions('viewer', ['projects.view', 'logs.view', 'metrics.view', 'regions.view', 'members.view', 'tokens.manage', 'tickets.view', 'tickets.create'])
    await auth.signIn({ user: { ...mockApi.db.user, is_admin: false } })

    await page.goto('/projects')
    await expect(page.locator('[data-tour="new-project-btn"]')).toHaveCount(0)
    await page.goto(`/projects/${project.id}`)
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Deploy' })).toHaveCount(0)
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Delete' })).toHaveCount(0)
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Edit' })).toHaveCount(0)
  })

  test('project access unlocks deploying one project', async ({ page, auth, mockApi, seed, isStack }) => {
    test.skip(isStack, 'narrows the mocked permissions')
    const project = await seed.project('xaas')
    mockApi.db.permissions = effectivePermissions('viewer', ['projects.view'], { [project.id]: ['projects.deploy', 'code.edit'] })
    await auth.signIn({ user: { ...mockApi.db.user, is_admin: false } })

    await page.goto(`/projects/${project.id}`)
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Deploy' })).toBeVisible()
    await expect(page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Delete' })).toHaveCount(0)
  })

  test('the full catalog of an owner shows every settings tab', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'reads the mocked permissions')
    mockApi.db.permissions = effectivePermissions('owner', [...ALL_PERMISSIONS])
    await auth.signIn({ user: { ...mockApi.db.user, is_admin: false } })
    await page.goto('/settings')
    for (const tab of ['API Tokens', 'Roles', 'Groups']) {
      await expect(page.getByRole('button', { name: tab })).toBeVisible()
    }
  })
})
