import { test, expect } from './fixtures/test'
import { appendInEditor, replaceInEditor } from './helpers/ui'

// Versions tab: Monaco editor + commit bar. Saving creates a new version.

test.describe('Edit code and save a version', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('saves an edited snippet as a new latest version', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const versionList = page.locator('[data-tour="version-list"]')
    await expect(versionList.getByText('latest')).toBeVisible()
    const before = await versionList.locator('.font-mono.text-sm').count()

    await appendInEditor(page, '\n# edited by e2e')
    await page.getByPlaceholder('Commit message (optional)').fill('e2e: comment added')
    const sent = isStack ? null : mockApi.waitFor('POST', `/projects/${project.id}/code`)
    await page.locator('[data-tour="commit-bar"]').getByRole('button', { name: 'Save' }).click()

    if (sent) {
      const { body } = await sent
      expect(body.message).toBe('e2e: comment added')
      expect(body.code).toContain('# edited by e2e')
      expect(body.code).toContain('def handler(')
    }
    await expect(versionList.getByText('e2e: comment added')).toBeVisible()
    await expect(versionList.locator('.font-mono.text-sm')).toHaveCount(before + 1)
    await expect(page.getByPlaceholder('Commit message (optional)')).toHaveValue('')
  })

  test('refuses to save code without the handler function', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)

    await replaceInEditor(page, 'print("no handler here")')
    await page.locator('[data-tour="commit-bar"]').getByRole('button', { name: 'Save' }).click()

    await expect(page.getByText(/must contain a handler function/)).toBeVisible()
    if (!isStack) expect(mockApi.calls('POST', `/projects/${project.id}/code`)).toHaveLength(0)
  })

  test('saves every file of a multi-file project', async ({ page, auth, mockApi, isStack }) => {
    test.skip(isStack, 'asserts the saved file list on the mocked API')
    await auth.signIn()
    await page.goto('/projects/p-faas')
    await expect(page.getByText('index.js').first()).toBeVisible()

    await page.getByPlaceholder('file.py').fill('util.js')
    await page.getByPlaceholder('file.py').press('Enter')
    await appendInEditor(page, 'module.exports = 42')
    const sent = mockApi.waitFor('POST', '/projects/p-faas/files')
    await page.locator('[data-tour="commit-bar"]').getByRole('button', { name: 'Save' }).click()

    const { body } = await sent
    const names = body.files.map((f: { name: string }) => f.name).sort()
    expect(names).toEqual(['index.js', 'package.json', 'util.js'])
    expect(body.files.find((f: { name: string }) => f.name === 'util.js').content).toContain('module.exports = 42')
  })

  test('shows older versions read-only', async ({ page, auth, isStack }) => {
    test.skip(isStack, 'relies on the two seeded versions of the mocked snippet')
    await auth.signIn()
    await page.goto('/projects/p-xaas')
    const versionList = page.locator('[data-tour="version-list"]')
    await expect(page.locator('[data-tour="commit-bar"]')).toBeVisible()

    await versionList.getByText('Initial version').click()
    await expect(page.locator('[data-tour="commit-bar"]')).toBeHidden()
    await expect(page.locator('.monaco-editor').first()).toContainText('"hello"')
  })
})
