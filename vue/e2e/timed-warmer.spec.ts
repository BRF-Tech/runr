import { test, expect } from './fixtures/test'
import { uniqueName } from './helpers/seed'
import { openEditStep, saveChangesButton } from './helpers/edit'
import { continueButton } from './helpers/ui'
import type { Locator, Page } from '@playwright/test'

// The timed warmer (config.scale_schedule) in the scale step of the project wizards: the
// "Advanced: timed warmer" toggle, rows of cron + container count with what each one means
// (cronstrue, the organization's time zone), the week it keeps warm and, in pay-as-you-go regions,
// the month's warm hours; the organization time zone in Settings; #627 (scale to zero keeps its max).

async function wizardToScaleStep(page: Page, regionName: string, name: string): Promise<Locator> {
  await page.goto('/projects')
  await page.getByRole('button', { name: '+ New Project' }).click()
  await page.locator('[data-tour="create-name"] input').first().fill(name)
  await page.locator('[data-tour="create-region"]').getByRole('button', { name: new RegExp(regionName) }).click()
  await continueButton(page).click()
  await page.getByRole('button', { name: /Code Snippet/ }).click()
  await continueButton(page).click()
  await expect(page.getByText('Step 3 / 4')).toBeVisible()
  await continueButton(page).click()
  await expect(page.getByText('Step 4 / 4')).toBeVisible()
  return page.locator('[data-tour="create-scale"]')
}

test.describe('Timed warmer', () => {
  // one after the other: the time-zone test changes the organization's zone, which the other
  // tests' explanations show (on the stack it is one organization for every worker)
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to create projects in')
  })

  test('creates a project with a warmer: rows explained, the week shown, the schedule saved', async ({ page, auth, seed, mockApi, isStack }) => {
    const region = await seed.readyRegion()
    const name = uniqueName('e2e-warm')
    seed.removeProjectAfterTest(name)
    await auth.signIn()
    const scale = await wizardToScaleStep(page, region!.name, name)

    // Scale to zero can burst (#627): raise the max first, the warmer's counts stay within it
    await scale.locator('[data-testid="scale-max"]').fill('5')
    await scale.locator('[data-testid="warmer-toggle"]').click()

    const rows = scale.locator('[data-testid="warmer-row"]')
    await expect(rows).toHaveCount(2)
    await expect(rows.nth(0).locator('[data-testid="warmer-explain"]'))
      .toHaveText(/At 09:00, Monday through Friday \(Europe\/Istanbul\): from then on 1 container stays warm/)
    await expect(rows.nth(1).locator('[data-testid="warmer-explain"]'))
      .toHaveText(/At 18:00, Monday through Friday \(Europe\/Istanbul\): from then on nothing is kept warm/)

    // (2 warm containers: what a small stack region can reserve)
    await rows.nth(0).locator('[data-testid="warmer-count"]').fill('2')
    await expect(rows.nth(0).locator('[data-testid="warmer-explain"]')).toHaveText(/2 containers stay warm/)

    // The week from the preview: weekdays 09:00–18:00 warm
    const timeline = scale.locator('[data-testid="warmer-timeline"]')
    await expect(timeline).toBeVisible()
    await expect(timeline).toContainText('Europe/Istanbul')
    if (!isStack) await expect(timeline.locator('[data-testid="warmer-cell"][data-value="2"]')).toHaveCount(5 * 9)
    else await expect(timeline.locator('[data-testid="warmer-cell"][data-value="2"]').first()).toBeVisible()

    const sent = isStack ? null : mockApi.waitFor('POST', '/projects')
    const created = isStack
      ? page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/api/projects')
      : null
    await page.getByRole('button', { name: 'Create Project' }).click()
    if (sent) {
      expect((await sent).body.config).toMatchObject({
        scale_to_zero: true, min_scale: 0, max_scale: 5,
        scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 2 }, { cron: '0 18 * * 1-5', min_scale: 0 }],
      })
    }
    if (created) expect((await created).status()).toBe(201)

    // The project keeps it: the header says the warmer is on, the editor shows the rows again
    await page.getByText(name).first().click()
    await expect(page.locator('[data-testid="warmer-badge"]')).toBeVisible()
    const modal = await openEditStep(page, 4)
    const editRows = modal.locator('[data-testid="warmer-row"]')
    await expect(editRows).toHaveCount(2)
    await expect(editRows.nth(0).locator('[data-testid="warmer-cron"]')).toHaveValue('0 9 * * 1-5')
    await expect(editRows.nth(0).locator('[data-testid="warmer-count"]')).toHaveValue('2')
  })

  test('shows the month\'s warm hours and cost where the region bills by use', async ({ page, auth, seed, mockApi, isStack }) => {
    test.skip(isStack, 'the estimate needs a pay-as-you-go region with billing on (mocked)')
    const project = await seed.project('xaas', { config: { max_scale: 4, scale_schedule: [{ cron: '0 8 * * *', min_scale: 2 }, { cron: '0 20 * * *', min_scale: 0 }] } })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 4)

    const estimate = modal.locator('[data-testid="warmer-estimate"]')
    await expect(estimate).toContainText('warm vCPU-hours')
    await expect(estimate.locator('[data-testid="warmer-cost"]')).toContainText('$')

    // A fixed region (or billing off): the preview has no estimate and nothing is shown
    mockApi.on('POST', '/scale-schedule/preview', () => ({
      status: 200,
      body: { timezone: 'Europe/Istanbul', week_start: '2026-10-19T00:00:00+03:00', now: 0, peak: 2, timeline: Array.from({ length: 7 }, () => Array(24).fill(0)), estimate: null },
    }))
    await modal.locator('[data-testid="warmer-count"]').first().fill('1')
    await expect(estimate).toBeHidden()
  })

  test('explains a wrong row locally and shows the API\'s error under its row', async ({ page, auth, seed, mockApi, isStack }) => {
    test.skip(isStack, 'forces the preview error with the mock')
    const project = await seed.project('xaas', { config: { max_scale: 3, scale_schedule: [{ cron: '0 9 * * *', min_scale: 1 }] } })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 4)
    const row = modal.locator('[data-testid="warmer-row"]').first()

    await row.locator('[data-testid="warmer-cron"]').fill('61 9 * * *')
    await expect(row.locator('[data-testid="warmer-row-error"]')).toHaveText(/^Use 5 fields/)
    await expect(saveChangesButton(modal)).toBeDisabled()

    await row.locator('[data-testid="warmer-count"]').fill('7')
    await row.locator('[data-testid="warmer-cron"]').fill('0 9 * * *')
    await expect(row.locator('[data-testid="warmer-row-error"]')).toHaveText('At most 3 (the max scale, never above 10)')

    mockApi.on('POST', '/scale-schedule/preview', () => ({
      status: 422,
      body: { message: 'Overlapping rows.', errors: { 'scale_schedule.0.cron': ['This row never fires in the organization\'s time zone.'] } },
    }))
    await row.locator('[data-testid="warmer-count"]').fill('2')
    await expect(row.locator('[data-testid="warmer-row-error"]')).toHaveText('This row never fires in the organization\'s time zone.')
  })

  test('switching to scale to zero keeps the max (#627) and the warmer can be switched off', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas', { config: { scale_to_zero: false, min_scale: 1, max_scale: 8, scale_schedule: [{ cron: '30 7 * * 1-5', min_scale: 2 }] } })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 4)

    // The warmer replaces the minimum
    await expect(modal.locator('[data-testid="scale-min-replaced"]')).toBeVisible()
    await modal.getByRole('button', { name: /Zero Scale/ }).click()
    await expect(modal.locator('[data-testid="scale-max"]')).toHaveValue('8')
    await expect(modal.locator('[data-testid="scale-zero-burst"]')).toContainText('8 containers')

    await modal.locator('[data-testid="warmer-toggle"]').click()
    await expect(modal.locator('[data-testid="warmer-row"]')).toHaveCount(0)

    const sent = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()
    if (sent) {
      expect((await sent).body.config).toMatchObject({ scale_to_zero: true, min_scale: 0, max_scale: 8, scale_schedule: null })
    }
    await expect(modal).toBeHidden()
    await expect(page.locator('[data-testid="warmer-badge"]')).toBeHidden()
  })

  test('shows what the warmer keeps warm now', async ({ page, auth, seed, isStack }) => {
    test.skip(isStack, 'needs a value the scheduler applied (mocked)')
    const project = await seed.project('xaas', {
      config: { max_scale: 5, scale_schedule: [{ cron: '0 9 * * *', min_scale: 3 }] },
      warm_scale: { applied: 3, applied_at: '2026-10-21T06:00:00Z' },
    })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    await expect(page.locator('[data-testid="warmer-badge"]')).toHaveText('Warm: 3')

    const modal = await openEditStep(page, 4)
    await expect(modal.locator('[data-testid="warmer-applied"]')).toContainText('Warm now: 3 containers')
  })

  test('the organization time zone drives the rows\' explanation', async ({ page, auth, seed, mockApi, isStack }) => {
    if (isStack) {
      const original = await seed.call('get', '/api/settings')
      seed.onCleanup(() => seed.call('patch', '/api/settings', { timezone: original.timezone ?? null }))
    }
    await auth.signIn()
    await page.goto('/settings')
    const card = page.locator('[data-testid="timezone-card"]')
    await expect(card).toBeVisible()

    await card.locator('[data-testid="timezone-input"]').fill('Europe/Berlin')
    const sent = isStack ? null : mockApi.waitFor('PATCH', '/settings')
    await card.locator('[data-testid="timezone-save"]').click()
    if (sent) expect((await sent).body).toEqual({ timezone: 'Europe/Berlin' })
    await expect(card.locator('[data-testid="timezone-input"]')).toHaveValue('Europe/Berlin')

    // An unknown zone: the API refuses it and the card says why
    await card.locator('[data-testid="timezone-input"]').fill('Mars/Olympus')
    await card.locator('[data-testid="timezone-save"]').click()
    await expect(card.locator('[data-testid="timezone-error"]')).toBeVisible()

    const project = await seed.project('xaas', { config: { max_scale: 2, scale_schedule: [{ cron: '0 9 * * 1-5', min_scale: 1 }] } })
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 4)
    await expect(modal.locator('[data-testid="warmer-explain"]').first()).toContainText('(Europe/Berlin)')
  })
})
