import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { usePermissionsStore } from '@/stores/permissions'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { triggersApi } from '@/modules/triggers/api/triggers'
import TriggerList from '@/modules/triggers/components/TriggerList.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import { apiError } from '@/test-support/helpers'
import { withPlugins } from '../../helpers'
import { HOOKS, HTTP, NIGHTLY, PLAN, PROJECT, REGIONS, TYPES } from './fixtures'

// The trigger list of a project: rows (type, primary, where it runs, settings, the webhook URL),
// enable / disable, make primary, remove, add through the type picker and the editor, the
// requires-deploy hint, the inbox action (replay.manage) and the read-only view.

vi.mock('@/modules/triggers/api/triggers', () => ({
  triggersApi: {
    types: vi.fn(), list: vi.fn(), create: vi.fn(), update: vi.fn(), remove: vi.fn(),
    events: vi.fn(), event: vi.fn(), replayEvent: vi.fn(), replayEvents: vi.fn(),
  },
}))
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn().mockResolvedValue({ data: { data: [] } }) } }))

const confirmMock = vi.fn()
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

function respond(triggers = [HTTP, HOOKS, NIGHTLY], plan = PLAN) {
  triggersApi.list.mockResolvedValue({ data: { data: triggers, plan } })
}

async function mountList(props = {}, permissions = null) {
  const plugins = withPlugins()
  const settings = useSettingsStore()
  settings.data = { ...settings.data, regions: REGIONS }
  settings.loaded = true
  if (permissions) {
    usePermissionsStore().apply({ edition: 'single', platform_admin: false, role: 'custom', roles: ['custom'], permissions, projects: {}, token_scoped: false })
  }
  const wrapper = mount(TriggerList, {
    props: { project: PROJECT, ...props },
    ...plugins,
    global: { ...plugins.global, stubs: { teleport: true } },
  })
  await flushPromises()
  return wrapper
}

const row = (w, name) => w.find(`[data-trigger="${name}"]`)
const lastToast = () => useToast().toasts.value.at(-1)?.message

describe('TriggerList', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    useToast().toasts.value = []
    triggersApi.types.mockResolvedValue({ data: { data: TYPES, platform_events: ['deployment.finished'], webhook_presets: ['none', 'github', 'hmac'] } })
    triggersApi.update.mockResolvedValue({ data: { data: HTTP, requires_deploy: true } })
    triggersApi.remove.mockResolvedValue({ data: { requires_deploy: true } })
    respond()
  })

  it('lists the triggers with type, primary badge, where they run and their settings', async () => {
    const w = await mountList()
    expect(triggersApi.list).toHaveBeenCalledWith('p1')
    expect(w.findAll('[data-trigger]')).toHaveLength(3)

    expect(row(w, 'http').find('[data-test="primary-badge"]').exists()).toBe(true)
    expect(row(w, 'http').find('[data-test="runs-on"]').text()).toBe('Service')
    expect(row(w, 'hooks').text()).toContain('Webhook inbox')
    expect(row(w, 'hooks').find('[data-test="primary-badge"]').exists()).toBe(false)
    expect(row(w, 'hooks').find('[data-test="runs-on"]').text()).toBe('Event gateway')
    expect(row(w, 'hooks').find('[data-test="trigger-summary"]').text()).toBe('GitHub')
    expect(row(w, 'hooks').find('[data-test="trigger-url"]').text()).toBe(HOOKS.url)
    expect(row(w, 'nightly').find('[data-test="trigger-summary"]').text()).toContain('0 3 * * *')
    expect(w.find('[data-test="trigger-plan"]').text()).toBe('Deploys as: Knative Service · public URL · event gateway')
    expect(w.emitted('state')[0][0]).toEqual({ triggers: [HTTP, HOOKS, NIGHTLY], plan: PLAN })
    expect(w.find('[data-test="requires-deploy"]').exists()).toBe(false)
  })

  it('disables a trigger, reloads and shows the requires-deploy hint', async () => {
    const w = await mountList()
    await row(w, 'hooks').find('[data-action="toggle"]').trigger('click')
    await flushPromises()

    expect(triggersApi.update).toHaveBeenCalledWith('p1', HOOKS.id, { enabled: false })
    expect(triggersApi.list).toHaveBeenCalledTimes(2)
    expect(lastToast()).toBe('Trigger "hooks" disabled.')
    expect(w.find('[data-test="requires-deploy"]').text()).toContain('next deploy')
    expect(w.emitted('changed')).toHaveLength(1)
  })

  it('makes an enabled trigger primary (position 0)', async () => {
    const w = await mountList()
    expect(row(w, 'http').find('[data-action="primary"]').exists()).toBe(false)
    await row(w, 'nightly').find('[data-action="primary"]').trigger('click')
    await flushPromises()
    expect(triggersApi.update).toHaveBeenCalledWith('p1', NIGHTLY.id, { position: 0 })
    expect(lastToast()).toBe('"nightly" is the primary trigger now.')
  })

  it('removes a trigger after the confirmation and shows the API refusal', async () => {
    const w = await mountList()
    confirmMock.mockResolvedValueOnce(false)
    await row(w, 'nightly').find('[data-action="remove"]').trigger('click')
    await flushPromises()
    expect(triggersApi.remove).not.toHaveBeenCalled()

    confirmMock.mockResolvedValueOnce(true)
    await row(w, 'nightly').find('[data-action="remove"]').trigger('click')
    await flushPromises()
    expect(confirmMock).toHaveBeenLastCalledWith(expect.objectContaining({ destructive: true, message: expect.stringContaining('"nightly"') }))
    expect(triggersApi.remove).toHaveBeenCalledWith('p1', NIGHTLY.id)
    expect(lastToast()).toBe('Trigger "nightly" removed.')

    confirmMock.mockResolvedValueOnce(true)
    triggersApi.remove.mockRejectedValueOnce(apiError(422, { message: 'A project needs at least one trigger; disable it instead of removing it.' }))
    await row(w, 'http').find('[data-action="remove"]').trigger('click')
    await flushPromises()
    expect(useToast().toasts.value.at(-1)).toMatchObject({ type: 'error', message: expect.stringContaining('at least one trigger') })
  })

  it('says which replication slots RunR drops when a removal left some', async () => {
    const w = await mountList()
    confirmMock.mockResolvedValueOnce(true)
    triggersApi.remove.mockResolvedValueOnce({ data: { requires_deploy: true, cleanup: [
      { trigger: 'nightly', type: 'cdc', slots: ['runr_orders_nightly'], status: 'scheduled', message: 'RunR drops the replication slot(s) runr_orders_nightly on the database once no event gateway reads them any more.' },
    ] } })
    await row(w, 'nightly').find('[data-action="remove"]').trigger('click')
    await flushPromises()
    const notes = w.findAll('[data-test="trigger-cleanup"]')
    expect(notes).toHaveLength(1)
    expect(notes[0].text()).toContain('nightly: RunR drops the replication slot(s) runr_orders_nightly')
  })

  it('offers the addable types with the reason of the others', async () => {
    const w = await mountList()
    await w.find('[data-action="add"]').trigger('click')
    const picker = w.find('[data-test="type-picker"]')
    expect(picker.find('[data-type="http"]').attributes('disabled')).toBeDefined()
    expect(picker.find('[data-type="http"]').text()).toContain('The project already has this trigger.')
    expect(picker.find('[data-type="workflow"]').text()).toContain('cannot be combined')
    expect(picker.find('[data-type="queue"]').attributes('disabled')).toBeUndefined()
  })

  it('adds a webhook trigger through the editor', async () => {
    const w = await mountList()
    await w.find('[data-action="add"]').trigger('click')
    await w.find('[data-type="webhook"]').trigger('click')
    const editor = w.find('[data-test="trigger-editor"]')
    expect(editor.text()).toContain('New trigger: Webhook inbox')
    expect(editor.find('[data-test="webhook-fields"]').exists()).toBe(true)
    expect(editor.text()).toContain('The inbox URL is created when the trigger is saved.')

    triggersApi.create.mockResolvedValue({ data: { data: { ...HOOKS, name: 'stripe-in' }, requires_deploy: true } })
    await editor.find('[data-field="name"]').setValue('stripe-in')
    await editor.find('[data-field="verify"]').setValue('github')
    // the secret field shows once a verification is chosen: look it up in the editor as it is now
    const current = () => w.find('[data-test="trigger-editor"]')
    await current().find('[data-field="signing_secret"]').setValue('whsec-12345678')
    await current().find('form').trigger('submit')
    await flushPromises()

    expect(triggersApi.create).toHaveBeenCalledWith('p1', {
      type: 'webhook', name: 'stripe-in', enabled: true, config: { verify: 'github' }, secrets: { signing_secret: 'whsec-12345678' },
    })
    expect(w.find('[data-test="trigger-editor"]').exists()).toBe(false)
    expect(lastToast()).toBe('Trigger "stripe-in" added.')
    expect(w.find('[data-test="requires-deploy"]').exists()).toBe(true)
  })

  it('opens the inbox of webhook triggers only with replay.manage', async () => {
    let w = await mountList()
    expect(row(w, 'hooks').find('[data-action="inbox"]').exists()).toBe(true)
    expect(row(w, 'nightly').find('[data-action="inbox"]').exists()).toBe(false)
    await row(w, 'hooks').find('[data-action="inbox"]').trigger('click')
    expect(w.emitted('open-inbox')[0][0]).toEqual(HOOKS)

    w = await mountList({}, ['projects.view', 'projects.update'])
    expect(row(w, 'hooks').find('[data-action="inbox"]').exists()).toBe(false)

    // the Edit Project modal (compact) never offers it
    w = await mountList({ compact: true })
    expect(row(w, 'hooks').find('[data-action="inbox"]').exists()).toBe(false)
  })

  it('is read-only without the permission to change the project', async () => {
    const w = await mountList({}, ['projects.view'])
    expect(w.find('[data-action="add"]').exists()).toBe(false)
    expect(w.find('[data-action="edit"]').exists()).toBe(false)
    expect(row(w, 'hooks').find('[data-action="toggle"]').attributes('disabled')).toBeDefined()
    expect(w.text()).toContain('Changing the triggers needs the permission')
  })

  it('shows why the list could not be loaded', async () => {
    triggersApi.list.mockRejectedValue(apiError(500, { message: 'Server error' }))
    const w = await mountList()
    expect(w.find('[data-test="trigger-load-error"]').text()).toBe('Server error')
  })
})

describe('TriggerConfig', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    triggersApi.types.mockResolvedValue({ data: { data: TYPES } })
    respond([HTTP])
  })

  it('edits the trigger list of an existing project', async () => {
    const plugins = withPlugins()
    const w = mount(TriggerConfig, {
      props: { triggerType: 'http', project: { ...PROJECT, triggers: [HTTP] } },
      ...plugins,
      global: { ...plugins.global, stubs: { teleport: true } },
    })
    // the list is an async component: its chunk, then its requests
    for (let i = 0; i < 5; i++) await flushPromises()
    expect(w.find('[data-test="trigger-list-config"]').exists()).toBe(true)
    expect(w.find('[data-trigger="http"]').exists()).toBe(true)
    expect(w.emitted('state')[0][0].triggers).toEqual([HTTP])
    // no single trigger buttons
    expect(w.findAll('button').some(b => b.text() === 'Schedule')).toBe(false)
  })

  it('keeps the single trigger selector for a new project', () => {
    const plugins = withPlugins()
    const w = mount(TriggerConfig, { props: { triggerType: 'http' }, ...plugins })
    expect(w.find('[data-test="trigger-list-config"]').exists()).toBe(false)
    expect(w.findAll('button').some(b => b.text() === 'Schedule')).toBe(true)
    expect(triggersApi.list).not.toHaveBeenCalled()
  })
})
