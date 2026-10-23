import { test, expect } from './fixtures/test'
import { settle } from './helpers/timing'

// Multi-tenant mode: the tenant switcher in the header scopes every API call with
// X-Tenant-ID. Runs in the mock-modules project (tenancy module built in) and in
// stack mode with E2E_TENANCY=true.

test.describe('Tenant switcher', { tag: '@modules' }, () => {
  test('switches from Central to a tenant and scopes later requests', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the X-Tenant-ID header on the mocked API')
    await auth.signIn()
    await page.goto('/projects')
    const switcher = page.locator('header').getByRole('button', { name: /Central/ })
    await expect(switcher).toBeVisible()

    await switcher.click()
    await expect(page.getByText('Active Tenant')).toBeVisible()
    await expect(page.getByText('Platform').first()).toBeVisible()
    const checked = mockApi.waitFor('GET', '/tenant/check/:slug')
    const reloaded = mockApi.waitFor('GET', '/projects')
    await page.getByRole('button', { name: /Acme Corp/ }).click()

    expect((await checked).path).toBe('/tenant/check/acme')
    expect((await reloaded).headers['x-tenant-id']).toBe('t-acme')
    await expect(page.locator('header').getByRole('button', { name: /Acme Corp/ })).toBeVisible()
    expect(await page.evaluate(() => JSON.parse(localStorage.getItem('active_tenant') || 'null')?.slug)).toBe('acme')
  })

  test('returns to Central and drops the tenant header', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the X-Tenant-ID header on the mocked API')
    mockApi.db.activeTenant = mockApi.db.tenants[0]
    await auth.signIn()
    await page.goto('/projects')
    await page.locator('header').getByRole('button', { name: /Acme Corp/ }).click()

    const reloaded = mockApi.waitFor('GET', '/projects')
    await page.getByRole('button', { name: /^Central/ }).last().click()
    expect((await reloaded).headers['x-tenant-id']).toBeUndefined()
    await expect(page.locator('header').getByRole('button', { name: /Central/ })).toBeVisible()
  })

  // A switch changes the active tenant and then the view key, one after the other: the
  // onboarding wizard reads the new organization's onboarding once, not once per change
  test('loads the onboarding of the organization switched to once', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'counts the requests on the mocked API')
    await auth.signIn()
    await page.goto('/projects')
    await page.locator('header').getByRole('button', { name: /Central/ }).click()
    const before = mockApi.calls('GET', '/onboarding').length
    const reloaded = mockApi.waitFor('GET', '/projects')
    await page.getByRole('button', { name: /Acme Corp/ }).click()
    await reloaded

    await expect.poll(() => mockApi.calls('GET', '/onboarding').length).toBe(before + 1)
    await settle(page)
    expect(mockApi.calls('GET', '/onboarding')).toHaveLength(before + 1)
  })

  test('finds a tenant by search', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'relies on the seeded mock tenants')
    mockApi.db.tenants.push(
      { id: 't-initech', name: 'Initech', slug: 'initech', role: 'admin' },
      { id: 't-umbrella', name: 'Umbrella', slug: 'umbrella', role: 'member' },
    )
    await auth.signIn()
    await page.goto('/projects')
    await page.locator('header').getByRole('button', { name: /Central/ }).click()
    await page.getByPlaceholder('Search tenants...').fill('umb')
    await expect(page.getByRole('button', { name: /Umbrella/ })).toBeVisible()
    await expect(page.getByRole('button', { name: /Globex/ })).toBeHidden()
  })

  test('shows the tenants of the signed-in account', async ({ page, auth, seed, isStack }) => {
    test.skip(!isStack, 'stack-mode smoke check; the mock variants above cover the details')
    const tenants = (await seed.call('get', '/api/tenant/current')).tenants ?? []
    test.skip(tenants.length === 0, 'the stack account belongs to no tenant')
    const session = await auth.signIn()
    await page.goto('/projects')
    const label = session.activeTenant?.name ?? (session.user.is_admin ? 'Central' : 'No tenant')
    await page.locator('header').getByRole('button', { name: new RegExp(label) }).first().click()
    await expect(page.getByText('Active Tenant')).toBeVisible()
  })
})
