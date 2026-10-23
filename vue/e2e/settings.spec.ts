import { test, expect } from './fixtures/test'

// Administration → Regions & settings: base domain + registration toggle.

test.describe('Platform settings', () => {
  test('saves the base domain', async ({ page, auth, seed, mockApi, isStack }) => {
    if (isStack) {
      const original = await seed.call('get', '/api/settings')
      seed.onCleanup(() => seed.call('patch', '/api/settings', { base_domain: original.base_domain ?? null }))
    }
    await auth.signIn()
    await page.goto('/settings')
    await expect(page.getByRole('heading', { name: 'Platform Management' })).toBeVisible()

    const domain = page.getByPlaceholder('runr.example.com')
    await domain.fill('apps.e2e.test')
    await expect(page.getByText('https://function.knative-region.apps.e2e.test')).toBeVisible()

    const sent = isStack ? null : mockApi.waitFor('PATCH', '/settings')
    await page.locator('[data-tour="settings-save"]').getByRole('button').click()
    if (sent) expect((await sent).body).toMatchObject({ base_domain: 'apps.e2e.test' })
    await expect(page.getByRole('button', { name: '✓ Saved' })).toBeVisible()

    await page.reload()
    await expect(page.getByPlaceholder('runr.example.com')).toHaveValue('apps.e2e.test')
  })

  test('toggles public registration', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'changes who may register; asserted on the mocked API')
    await auth.signIn()
    await page.goto('/settings')
    const card = page.locator('[data-tour="settings-registration"]')
    await expect(card.getByText('Registration Closed')).toBeVisible()
    await card.getByRole('button').click()
    await expect(card.getByText('Registration Open')).toBeVisible()

    const sent = mockApi.waitFor('PATCH', '/settings')
    await page.locator('[data-tour="settings-save"]').getByRole('button').click()
    expect((await sent).body.registration_enabled).toBe(true)
  })

  test('lists the regions with their state', async ({ page, auth, isStack }) => {
    test.skip(isStack, 'region names differ per stack')
    await auth.signIn()
    await page.goto('/settings')
    await expect(page.getByRole('heading', { name: 'Knative Regions' })).toBeVisible()
    await expect(page.getByText('eu-1').first()).toBeVisible()
    await expect(page.getByText('region-b').first()).toBeVisible()
  })
})
