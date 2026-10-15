import { test, expect } from './fixtures/test'
import { openEditStep, saveChangesButton } from './helpers/edit'

// Environment variables live in step 4 of the Edit Project modal as KEY=value lines.

test.describe('Environment variables', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('saves added and changed variables and shows them again', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas', { config: { env_vars: { LOG_LEVEL: 'info' } } })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)

    let modal = await openEditStep(page, 4)
    const editor = modal.locator('textarea').first()
    await expect(editor).toHaveValue(/LOG_LEVEL=info/)
    await editor.fill('LOG_LEVEL=debug\nFEATURE_FLAGS=a=1,b=2')
    const sent = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()

    if (sent) {
      const { body } = await sent
      expect(body.config.env_vars).toEqual({ LOG_LEVEL: 'debug', FEATURE_FLAGS: 'a=1,b=2' })
    }
    await expect(modal).toBeHidden()

    modal = await openEditStep(page, 4)
    await expect(modal.locator('textarea').first()).toHaveValue('LOG_LEVEL=debug\nFEATURE_FLAGS=a=1,b=2')
  })

  test('rejects invalid and platform-reserved names before saving', async ({ page, auth, seed }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 4)
    const editor = modal.locator('textarea').first()

    await editor.fill('9LIVES=x')
    await expect(modal.getByText('Invalid variable name "9LIVES"', { exact: false })).toBeVisible()
    await expect(saveChangesButton(modal)).toBeDisabled()

    await editor.fill('PORT=8080')
    await expect(modal.getByText('"PORT" is set by the platform and cannot be overridden')).toBeVisible()
    await expect(saveChangesButton(modal)).toBeDisabled()

    await editor.fill('APP_PORT=8080')
    await expect(saveChangesButton(modal)).toBeEnabled()
  })

  test('removes all variables when the editor is cleared', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the payload on the mocked API')
    await auth.signIn()
    await page.goto('/projects/p-faas')
    const modal = await openEditStep(page, 4)
    await modal.locator('textarea').first().fill('')
    const sent = mockApi.waitFor('PUT', '/projects/p-faas')
    await saveChangesButton(modal).click()
    expect((await sent).body.config.env_vars).toBeUndefined()
  })
})
