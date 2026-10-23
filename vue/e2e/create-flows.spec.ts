import { test, expect } from './fixtures/test'
import type { Json } from './fixtures/data'
import { uniqueName } from './helpers/seed'
import { confirmDialog, continueButton, field } from './helpers/ui'
import { recordBroadcasts, settle } from './helpers/timing'

// The main create flows with the API's answers held back (the `network` fixture), in both modes:
// the orderings a slow API, a busy network or the websocket produce — the project.created
// broadcast before the answer that created the project, a second click before the first answer,
// a slow answer for something the user already left behind. A fast local run never shows them.
//
//   mock  → the fake API; the spec sends the broadcasts the real API would send (realtime fixture)
//   stack → the real API and database (npm run test:e2e:create-flows); the broadcasts come from the
//           API over the stack's websocket (Reverb), so the stack must run the reverb service

test.describe('Create flows with late answers', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to create projects in')
  })

  test('new project: the broadcast that lists it before the answer gives one card and no error', async ({ page, auth, seed, mockApi, realtime, network, isStack }) => {
    const region = (await seed.readyRegion())!
    const name = uniqueName('e2e-race')
    seed.removeProjectAfterTest(name)
    await auth.signIn()
    await page.goto('/projects')
    await page.getByRole('button', { name: '+ New Project' }).click()

    await page.locator('[data-tour="create-name"] input').first().fill(name)
    await page.locator('[data-tour="create-region"]').getByRole('button', { name: new RegExp(region.name) }).click()
    await continueButton(page).click()
    await page.getByRole('button', { name: /Container/ }).click()
    await continueButton(page).click()
    await page.locator('[data-tour="create-container"] input').first().fill('nginx:1.27-alpine')
    await continueButton(page).click()

    const created = await network.hold('POST', '/projects')
    const submit = page.getByRole('button', { name: 'Create Project' })
    await submit.click()
    await created.arrived
    // A second click while the first is in flight creates nothing more
    await expect(page.getByRole('button', { name: /Creating/ })).toBeDisabled()

    // The broadcast lists the project while the wizard still waits for its answer
    if (!isStack) {
      const row = mockApi.db.projects.find((p: Json) => p.name === name)
      await realtime.platform('project.created', { project: { ...row, created_by: { id: mockApi.db.user.id } } })
    }
    const card = page.locator(`#p-${name}`)
    await expect(card).toBeAttached()

    created.release()
    await expect(page.getByRole('heading', { name: 'Give your project a name' })).toBeHidden()
    await expect(page.getByText('Step 1 / 4')).toBeHidden()
    await settle(page)
    await expect(card).toHaveCount(1)
    await expect(page.getByText('A project with this name already exists.')).toHaveCount(0)
    if (!isStack) expect(mockApi.calls('POST', '/projects')).toHaveLength(1)
  })

  test('new MCP server from an image: the broadcast during the setup shows no error, the MCP tab opens', async ({ page, auth, seed, mockApi, realtime, network, isStack }) => {
    const region = (await seed.readyRegion())!
    const name = uniqueName('e2e-mcp')
    seed.removeProjectAfterTest(name)
    await auth.signIn()
    await page.goto('/projects')
    await page.locator('[data-testid="new-mcp-server-btn"]').click()

    const modal = page.locator('[data-testid="create-mcp-server"]')
    await modal.locator('[data-testid="mcp-create-name"]').fill(name)
    await modal.getByRole('button', { name: new RegExp(region.name) }).first().click()
    await modal.locator('[data-testid="mcp-template-image"]').click()
    await modal.locator('[data-testid="mcp-create-image"]').fill('ghcr.io/acme/mcp-server:1.0')
    await modal.locator('[data-testid="mcp-create-port"]').fill('8080')

    // The project exists, the gateway setup is held: the broadcast lists the project meanwhile
    const served = await network.hold('PUT', '/projects/:id/mcp-server')
    await modal.locator('[data-testid="mcp-create-submit"]').click()
    const { params } = await served.arrived
    if (!isStack) {
      const row = mockApi.db.projects.find((p: Json) => p.id === params.id)
      await realtime.platform('project.created', { project: { ...row, created_by: { id: mockApi.db.user.id } } })
    }
    await expect(page.locator(`#p-${name}`)).toBeAttached()
    await settle(page)
    await expect(modal.getByText('A project with this name already exists.')).toHaveCount(0)
    await expect(modal.locator('[data-testid="mcp-create-error"]')).toHaveCount(0)

    served.release()
    await expect(page).toHaveURL(new RegExp(`/projects/${params.id}#mcp`))
    await expect(page.locator('[data-testid="mcp-server-panel"]')).toBeVisible()
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible()
  })

  test('clone: the project page shows the clone, not the project it was cloned from', async ({ page, auth, seed }) => {
    const source = await seed.project('caas')
    const cloneName = uniqueName('e2e-clone')
    seed.removeProjectAfterTest(cloneName)
    await auth.signIn()
    await page.goto(`/projects/${source.id}`)
    await expect(page.getByRole('heading', { level: 1, name: source.name })).toBeVisible()

    await page.getByRole('button', { name: 'Clone', exact: true }).click()
    await expect(page.getByRole('heading', { name: 'Clone Project' })).toBeVisible()
    await field(page, 'Project Name').fill(cloneName)
    await continueButton(page).click()
    await page.getByRole('button', { name: 'Create Clone' }).click()

    // A new page for the clone: its name, its id, no clone modal left over
    await expect(page.getByRole('heading', { level: 1, name: cloneName })).toBeVisible()
    await expect(page).not.toHaveURL(new RegExp(`/projects/${source.id}(#|$)`))
    await expect(page.getByRole('heading', { name: 'Clone Project' })).toHaveCount(0)
    await expect(page.getByRole('heading', { level: 1, name: source.name })).toHaveCount(0)
  })

  test('versions: the files of the version clicked last stay in the editor', async ({ page, auth, seed, mockApi, network, isStack }) => {
    const project = await seed.project('xaas')
    const latestCode = 'def handler(event, context):\n    return {"latest": True}\n'
    if (isStack) {
      await seed.call('post', `/api/projects/${project.id}/code`, { code: latestCode, message: 'e2e: second version' })
    } else {
      mockApi.db.versions[project.id].unshift({ id: mockApi.nextId('v'), label: 'v2', commit_message: 'e2e: second version', build_status: 'ready', created_at: new Date().toISOString() })
      mockApi.db.code[project.id] = latestCode
    }
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const versionList = page.locator('[data-tour="version-list"]')
    const editor = page.locator('.monaco-editor').first()
    await expect(editor).toContainText('"latest"')

    // The older version's files answer late; the user has gone back to the latest meanwhile
    const older = await network.hold('GET', '/projects/:id/versions/:vid/files')
    await versionList.getByText('Initial version').click()
    await older.arrived
    await versionList.getByText('e2e: second version').click()
    await expect(editor).toContainText('"latest"')
    await expect(page.locator('[data-tour="commit-bar"]')).toBeVisible()

    const late = page.waitForResponse(r => /\/versions\/[^/]+\/files/.test(new URL(r.url()).pathname))
    older.release()
    await late
    await settle(page)
    await expect(editor).toContainText('"latest"')
    await expect(editor).not.toContainText('"seeded"')
    await expect(page.locator('[data-tour="commit-bar"]')).toBeVisible()
  })
})

// Lists and pages that broadcasts change while a request is in flight: the list's answer may have
// been read before the broadcast, the page's own delete is broadcast before it answers
test.describe('Lists and deletes with late answers', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to create projects in')
  })

  test('projects list: a project created elsewhere while the list loads stays in it', async ({ page, auth, seed, realtime, network, isStack }) => {
    const broadcasts = recordBroadcasts(page)
    await auth.signIn()
    const list = await network.hold('GET', '/projects')
    await page.goto('/projects')
    await list.arrived                                  // the list is read before the project exists

    // Another tab (or person) creates a project; its broadcast reaches the page before the list
    const created = await seed.project('caas')
    if (isStack) await broadcasts.waitFor(created.name)
    else await realtime.platform('project.created', { project: { ...created, created_by: { id: 999 } } })

    list.release()
    await expect(page.locator(`#p-${created.slug}`)).toBeAttached()
    await settle(page)
    await expect(page.locator(`#p-${created.slug}`)).toHaveCount(1)
  })

  test('project page: deleting it keeps the page until the projects list opens', async ({ page, auth, seed, realtime, network, isStack }) => {
    const project = await seed.project('caas')
    const broadcasts = recordBroadcasts(page)
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const heading = page.getByRole('heading', { level: 1, name: project.name })
    await expect(heading).toBeVisible()

    const deleted = await network.hold('DELETE', '/projects/:id')
    await page.locator('[data-tour="action-buttons"]').getByRole('button', { name: 'Delete' }).click()
    await confirmDialog(page, 'Delete')
    await deleted.arrived
    // The API broadcasts project.deleted before its answer reaches the page
    if (isStack) await broadcasts.waitFor('project.deleted')
    else await realtime.platform('project.deleted', { project_id: project.id })
    await settle(page)
    await expect(heading).toBeVisible()               // no blank page in between

    deleted.release()
    await expect(page).toHaveURL(/\/projects$/)
    await expect(page.locator(`#p-${project.slug}`)).toHaveCount(0)
  })
})
