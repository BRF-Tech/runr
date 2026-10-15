import { test, expect } from './fixtures/test'
import { uniqueName } from './helpers/seed'
import { continueButton } from './helpers/ui'
import type { Page } from '@playwright/test'

// The 4-step create wizard: name + region → type → runtime/container + trigger → resources.

const TYPES = [
  { type: 'xaas', card: 'Code Snippet' },
  { type: 'faas', card: 'Code Project' },
  { type: 'caas', card: 'Container' },
] as const

async function openWizard(page: Page) {
  await page.goto('/projects')
  await page.getByRole('button', { name: '+ New Project' }).click()
  await expect(page.getByRole('heading', { name: 'Give your project a name' })).toBeVisible()
}

test.describe('Create project', () => {
  for (const { type, card } of TYPES) {
    test(`creates a ${type} project through the wizard`, async ({ page, auth, seed, mockApi, isStack }) => {
      const region = await seed.readyRegion()
      test.skip(!region, 'the stack has no ready region to create projects in')
      const name = uniqueName(`e2e-${type}`)
      seed.removeProjectAfterTest(name)
      await auth.signIn()
      await openWizard(page)

      // Step 1: name + region
      await page.locator('[data-tour="create-name"] input').first().fill(name)
      await expect(page.getByText(`slug: ${name}`)).toBeVisible()
      await page.locator('[data-tour="create-region"]').getByRole('button', { name: new RegExp(region!.name) }).click()
      await continueButton(page).click()

      // Step 2: type
      await expect(page.getByText('Step 2 / 4')).toBeVisible()
      await page.getByRole('button', { name: new RegExp(card) }).click()
      await continueButton(page).click()

      // Step 3: runtime or container image, trigger
      await expect(page.getByText('Step 3 / 4')).toBeVisible()
      if (type === 'caas') {
        await expect(continueButton(page)).toBeDisabled()
        await page.locator('[data-tour="create-container"] input').first().fill('nginx:1.27-alpine')
      } else {
        await expect(page.locator('[data-tour="create-lang"]')).toBeVisible()
      }
      await expect(page.locator('[data-tour="create-trigger"]')).toBeVisible()
      await continueButton(page).click()

      // Step 4: resources, scale, env vars → submit
      await expect(page.getByText('Step 4 / 4')).toBeVisible()
      const sent = isStack ? null : mockApi.waitFor('POST', '/projects')
      // Stack: the API stored it (and the cleanup after the test finds it)
      const created = isStack
        ? page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/projects')
        : null
      await page.getByRole('button', { name: 'Create Project' }).click()

      if (created) expect((await created).status()).toBe(201)
      if (sent) {
        const { body } = await sent
        expect(body).toMatchObject({ name, service_type: type, knative_region_id: region!.id, config: { trigger_type: 'http' } })
        if (type === 'caas') {
          expect(body.config).toMatchObject({ image: 'nginx:1.27-alpine', ports: [80] })
          expect(body).not.toHaveProperty('runtime')
        } else {
          expect(body.runtime).toBeTruthy()
        }
      }
      await expect(page.getByRole('heading', { name: 'Give your project a name' })).toBeHidden()
      await expect(page.getByText(name).first()).toBeVisible()
    })
  }

  test('blocks names that already exist or do not make a Kubernetes name', async ({ page, auth, seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
    const existing = await seed.project('xaas')
    await auth.signIn()
    await openWizard(page)
    const nameInput = page.locator('[data-tour="create-name"] input').first()

    await nameInput.fill(existing.name)
    await expect(page.getByText('A project with this name already exists.')).toBeVisible()
    await expect(continueButton(page)).toBeDisabled()

    await nameInput.fill('123 starts with digits')
    await expect(page.getByText(/must give a Kubernetes name/)).toBeVisible()
    await expect(continueButton(page)).toBeDisabled()
  })

  test('shows the API validation error on the name step', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'forces an API error with the mock')
    mockApi.on('POST', '/projects', () => ({
      status: 422,
      body: { message: 'The name has already been taken.', errors: { name: ['The name has already been taken.'] } },
    }))
    await auth.signIn()
    await openWizard(page)
    await page.locator('[data-tour="create-name"] input').first().fill('taken-elsewhere')
    await page.locator('[data-tour="create-region"]').getByRole('button', { name: /eu-1/ }).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: /Code Snippet/ }).click()
    await continueButton(page).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: 'Create Project' }).click()

    await expect(page.getByText('Step 1 / 4')).toBeVisible()
    await expect(page.getByText('The name has already been taken.')).toBeVisible()
  })
})
