import { test, expect } from './fixtures/test'
import { openEditStep, saveChangesButton } from './helpers/edit'
import { continueButton } from './helpers/ui'

// Trigger settings (HTTP endpoint, cron schedule, queue stream) in step 3 of the
// Edit Project modal.

test.describe('Triggers', () => {
  test.beforeEach(async ({ seed }) => {
    test.skip(!(await seed.readyRegion()), 'the stack has no ready region to seed a project in')
  })

  test('switches an HTTP project to a cron schedule', async ({ page, auth, seed, mockApi, isStack }) => {
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)
    const trigger = modal.locator('[data-tour="edit-trigger"]')

    await trigger.getByRole('button', { name: 'Schedule', exact: true }).click()
    await expect(modal.getByText('Cron expression is required')).toBeVisible()
    await expect(continueButton(modal)).toBeDisabled()

    const cron = modal.getByPlaceholder('0 * * * *')
    await cron.fill('0 0 * * * *')
    await expect(modal.getByText(/^Use 5 fields/)).toBeVisible()
    await expect(continueButton(modal)).toBeDisabled()

    await cron.fill('*/15 * * * *')
    await expect(continueButton(modal)).toBeEnabled()
    await continueButton(modal).click()

    // Scheduled projects run one pod: the scale editor is gone
    await expect(modal.locator('[data-tour="edit-resources"]')).toBeVisible()
    await expect(modal.getByText('Zero Scale')).toBeHidden()

    const sent = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()
    if (sent) {
      expect((await sent).body.config).toMatchObject({
        trigger_type: 'schedule', schedule: '*/15 * * * *', scale_to_zero: true, min_scale: 0, max_scale: 1,
      })
    }
    await expect(modal).toBeHidden()
  })

  test('configures a queue trigger with its stream name', async ({ page, auth, seed, mockApi, isStack }) => {
    const region = await seed.readyRegion()
    test.skip(!(region?.is_synced || region?.has_redis), 'queue triggers need a region with Redis')
    const project = await seed.project('xaas')
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)

    await modal.locator('[data-tour="edit-trigger"]').getByRole('button', { name: 'Queue', exact: true }).click()
    const stream = modal.getByPlaceholder('my-events-stream')
    await stream.fill('')
    await expect(modal.getByText('Queue/Stream name is required')).toBeVisible()
    await stream.fill('orders-events')
    await continueButton(modal).click()

    const sent = isStack ? null : mockApi.waitFor('PUT', `/projects/${project.id}`)
    await saveChangesButton(modal).click()
    if (sent) expect((await sent).body.config).toMatchObject({ trigger_type: 'queue', queue_stream: 'orders-events' })
    await expect(modal).toBeHidden()
  })

  test('offers only the schedule trigger on a region without Knative Serving', async ({ page, auth, seed, isStack }) => {
    test.skip(isStack, 'needs a region without Serving; the mocked API has one')
    const project = await seed.project('xaas', {
      knative_region_id: 'r-cron',
      endpoint_url: null,
      config: { trigger_type: 'schedule', schedule: '0 * * * *' },
    })
    await auth.signIn()
    await page.goto(`/projects/${project.id}`)
    const modal = await openEditStep(page, 3)
    const trigger = modal.locator('[data-tour="edit-trigger"]')

    await expect(trigger.getByRole('button', { name: 'Endpoint', exact: true })).toBeDisabled()
    await expect(trigger.getByRole('button', { name: 'Queue', exact: true })).toBeDisabled()
    await expect(modal.getByPlaceholder('0 * * * *')).toHaveValue('0 * * * *')
  })
})
