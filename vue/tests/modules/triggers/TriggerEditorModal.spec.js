import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { triggersApi } from '@/modules/triggers/api/triggers'
import { topicsApi } from '@/modules/triggers/api/topics'
import { projectsApi } from '@/api/projects'
import TriggerEditorModal from '@/modules/triggers/components/TriggerEditorModal.vue'
import { apiError } from '@/test-support/helpers'
import { withPlugins } from '../../helpers'
import { HOOKS, HTTP, KAFKA, NIGHTLY, TOPIC, TYPES, TYPES_WITH_KAFKA, trigger } from './fixtures'

// The trigger editor: the dedicated form of each core type, the generic form built from the
// `fields` of any other type, the delivery section of gateway types, client checks before the
// request and the API's 422 errors next to the fields.

vi.mock('@/modules/triggers/api/triggers', () => ({ triggersApi: { create: vi.fn(), update: vi.fn() } }))
vi.mock('@/modules/triggers/api/topics', () => ({ topicsApi: { list: vi.fn() } }))
vi.mock('@/api/projects', () => ({ projectsApi: { list: vi.fn() } }))

function mountEditor(props) {
  const plugins = withPlugins()
  return mount(TriggerEditorModal, {
    props: { projectId: 'p1', regionId: 'r1', types: TYPES, siblings: [HTTP], ...props },
    ...plugins,
    global: { ...plugins.global, stubs: { teleport: true } },
  })
}

const save = async (w) => {
  await w.find('form').trigger('submit')
  await flushPromises()
}

describe('TriggerEditorModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    triggersApi.create.mockImplementation(async (_, payload) => ({ data: { data: trigger({ ...payload, name: payload.name ?? payload.type }), requires_deploy: true } }))
    triggersApi.update.mockImplementation(async (_, key, payload) => ({ data: { data: trigger({ name: key, ...payload }), requires_deploy: true } }))
    projectsApi.list.mockResolvedValue({ data: { data: [{ id: 'p1', name: 'Orders' }, { id: 'p2', name: 'Billing' }] } })
    topicsApi.list.mockResolvedValue({ data: { data: [TOPIC, { ...TOPIC, id: 't2', name: 'other-region', knative_region_id: 'r2' }] } })
  })

  it('creates an HTTP trigger with no settings and no delivery section', async () => {
    const w = mountEditor({ type: 'http' })
    expect(w.text()).toContain('the trigger serves the project\'s public URL')
    expect(w.find('[data-test="trigger-delivery"]').exists()).toBe(false)
    await w.find('[data-field="name"]').setValue('public-api')
    await save(w)
    expect(triggersApi.create).toHaveBeenCalledWith('p1', { type: 'http', name: 'public-api', enabled: true, config: {} })
    expect(w.emitted('saved')[0][0]).toMatchObject({ created: true, requiresDeploy: true })
  })

  it('checks the cron and the name before saving a schedule', async () => {
    const w = mountEditor({ type: 'schedule' })
    await w.find('[data-field="name"]').setValue('http')
    await save(w)
    expect(triggersApi.create).not.toHaveBeenCalled()
    expect(w.text()).toContain('Cron expression is required.')
    expect(w.text()).toContain('The project already has a trigger named "http".')

    await w.find('[data-field="name"]').setValue('nightly')
    await w.find('[data-field="cron"]').setValue('*/15 * * * *')
    expect(w.text()).toContain('15')   // the cron in words
    await save(w)
    expect(triggersApi.create).toHaveBeenCalledWith('p1', { type: 'schedule', name: 'nightly', enabled: true, config: { cron: '*/15 * * * *' } })
  })

  it('gives a schedule a time zone; empty keeps the organization\'s, shown as the placeholder', async () => {
    const w = mountEditor({ type: 'schedule' })
    const zone = w.find('[data-field="timezone"]')
    expect(zone.attributes('placeholder')).toBe('Europe/Istanbul')
    expect(w.text()).toContain('Empty: the organization\'s time zone (Europe/Istanbul)')
    await w.find('[data-field="cron"]').setValue('0 9 * * 1-5')
    await zone.setValue('Mars/Olympus')
    await save(w)
    expect(triggersApi.create).not.toHaveBeenCalled()
    expect(w.text()).toContain('Not a time zone')

    await zone.setValue('Europe/Berlin')
    await save(w)
    expect(triggersApi.create).toHaveBeenCalledWith('p1', { type: 'schedule', enabled: true, config: { cron: '0 9 * * 1-5', timezone: 'Europe/Berlin' } })
  })

  it('edits a stored trigger: the delivery settings show the effective values as placeholders', async () => {
    const w = mountEditor({ type: 'schedule', trigger: NIGHTLY, siblings: [HTTP] })
    expect(w.text()).toContain('Edit trigger "nightly"')
    expect(w.find('[data-field="cron"]').element.value).toBe('0 3 * * *')
    await w.find('[data-test="trigger-delivery"] button').trigger('click')
    const retries = w.find('[data-field="delivery.max_retries"]')
    expect(retries.element.value).toBe('')
    expect(retries.attributes('placeholder')).toBe('5')
    await retries.setValue('2')
    await save(w)
    expect(triggersApi.update).toHaveBeenCalledWith('p1', NIGHTLY.id, { name: 'nightly', enabled: true, config: { cron: '0 3 * * *', delivery: { max_retries: 2 } } })
    expect(w.emitted('saved')[0][0]).toMatchObject({ created: false })
  })

  it('shows the queue form with where to start', async () => {
    const w = mountEditor({ type: 'queue' })
    await w.find('[data-field="stream"]').setValue('runr.internal')
    await save(w)
    expect(w.text()).toContain('Stream names starting with "runr." are reserved.')
    await w.find('[data-field="stream"]').setValue('orders')
    await w.find('[data-field="start_from"]').setValue('latest')
    await save(w)
    expect(triggersApi.create.mock.calls[0][1].config).toEqual({ stream: 'orders', start_from: 'latest' })
  })

  describe('webhook', () => {
    it('shows the custom HMAC settings and needs a secret for verification', async () => {
      const w = mountEditor({ type: 'webhook' })
      expect(w.find('[data-test="webhook-hmac"]').exists()).toBe(false)
      expect(w.text()).toContain('anyone who knows the URL can send events')
      expect(w.find('[data-field="signing_secret"]').exists()).toBe(false)

      await w.find('[data-field="verify"]').setValue('hmac')
      expect(w.find('[data-test="webhook-hmac"]').exists()).toBe(true)
      expect(w.find('[data-test="secret-state"]').text()).toBe('not set')
      await save(w)
      expect(w.text()).toContain('Signature verification needs a signing secret.')

      await w.find('[data-field="hmac.header"]').setValue('X-Shop-Signature')
      await w.find('[data-field="hmac.algorithm"]').setValue('sha1')
      await w.find('[data-field="hmac.encoding"]').setValue('base64')
      await w.find('[data-field="hmac.prefix"]').setValue('sha1=')
      await w.find('[data-field="signing_secret"]').setValue('shop-secret-1')
      await w.find('[data-test="webhook-advanced"]').trigger('click')
      await w.find('[data-field="delivery_id_header"]').setValue('X-Shop-Delivery')
      expect(w.find('[data-field="tolerance_seconds"]').exists()).toBe(false)
      await w.find('[data-field="retention_days"]').setValue('3')
      await save(w)

      expect(triggersApi.create).toHaveBeenCalledWith('p1', {
        type: 'webhook', enabled: true,
        config: { verify: 'hmac', hmac: { header: 'X-Shop-Signature', algorithm: 'sha1', encoding: 'base64', prefix: 'sha1=' }, delivery_id_header: 'X-Shop-Delivery', retention_days: 3 },
        secrets: { signing_secret: 'shop-secret-1' },
      })
    })

    it('keeps the stored secret, shows the inbox URL and offers the timestamp tolerance for Stripe', async () => {
      const w = mountEditor({ type: 'webhook', trigger: HOOKS })
      expect(w.find('[data-test="webhook-url"]').text()).toBe(HOOKS.url)
      expect(w.find('[data-test="secret-state"]').text()).toBe('set')
      expect(w.find('[data-field="signing_secret"]').element.value).toBe('')
      expect(w.find('[data-field="signing_secret"]').attributes('placeholder')).toBe('Leave empty to keep the current secret')

      await w.find('[data-field="verify"]').setValue('stripe')
      await w.find('[data-test="webhook-advanced"]').trigger('click')
      await w.find('[data-field="tolerance_seconds"]').setValue('120')
      await save(w)
      expect(triggersApi.update).toHaveBeenCalledWith('p1', HOOKS.id, { name: 'hooks', enabled: true, config: { verify: 'stripe', tolerance_seconds: 120 } })
    })
  })

  it('subscribes to a topic of the project\'s region', async () => {
    const w = mountEditor({ type: 'topic' })
    await flushPromises()
    expect(topicsApi.list).toHaveBeenCalled()
    const options = w.findAll('[data-field="topic"] option').map(o => o.attributes('value'))
    expect(options).toEqual(['', 'orders.created'])
    await w.find('[data-field="topic"]').setValue('orders.created')
    await save(w)
    expect(triggersApi.create.mock.calls[0][1].config).toEqual({ topic: 'orders.created', start_from: 'latest' })
  })

  it('says when the region has no topic yet', async () => {
    topicsApi.list.mockResolvedValue({ data: { data: [] } })
    const w = mountEditor({ type: 'topic' })
    await flushPromises()
    expect(w.find('[data-test="no-topics"]').text()).toContain('Build & Run → Topics')
  })

  it('subscribes to platform events, optionally about some projects', async () => {
    const w = mountEditor({ type: 'platform_event', platformEvents: ['deployment.finished', 'deployment.failed', 'alert.fired'] })
    await flushPromises()
    const fields = w.find('[data-test="platform-event-fields"]')
    expect(fields.text()).toContain('Deployment failed')

    await fields.find('[data-field="events.all"]').setValue(true)
    expect(fields.find('[data-event="alert.fired"]').attributes('disabled')).toBeDefined()
    await fields.find('[data-field="events.all"]').setValue(false)
    await fields.find('[data-event="deployment.failed"]').setValue(true)
    await fields.find('[data-event="alert.fired"]').setValue(true)
    await fields.find('[data-project="p2"]').setValue(true)
    await fields.find('[data-field="projects.search"]').setValue('ord')
    expect(fields.findAll('[data-project]').map(x => x.attributes('data-project'))).toEqual(['p1', 'p2'])   // the chosen one stays listed
    await save(w)
    expect(triggersApi.create.mock.calls[0][1].config).toEqual({ events: ['deployment.failed', 'alert.fired'], projects: ['p2'] })
  })

  it('shows the API\'s 422 errors next to the fields', async () => {
    triggersApi.create.mockRejectedValueOnce(apiError(422, {
      message: 'There is no topic "gone".',
      errors: { 'config.cron': ['The cron schedule is invalid.'] },
    }))
    const w = mountEditor({ type: 'schedule' })
    await w.find('[data-field="cron"]').setValue('0 3 * * *')
    await save(w)
    expect(w.text()).toContain('The cron schedule is invalid.')
    expect(w.find('[data-test="trigger-error"]').text()).toBe('There is no topic "gone".')
    expect(w.emitted('saved')).toBeUndefined()
  })

  describe('generic form of a type without a dedicated one', () => {
    it('renders the type\'s fields with the API\'s labels and the delivery section', () => {
      const w = mountEditor({ type: 'kafka', types: TYPES_WITH_KAFKA })
      expect(w.text()).toContain('New trigger: Kafka (API label)')
      const generic = w.find('[data-test="generic-fields"]')
      expect(generic.exists()).toBe(true)
      expect(generic.text()).toContain('Brokers')
      expect(generic.text()).toContain('host:port, one per line')
      expect(generic.find('textarea[data-field="brokers"]').exists()).toBe(true)
      expect(generic.find('select[data-field="sasl.mechanism"]').element.value).toBe('plain')
      expect(generic.find('input[data-field="sasl.password"]').attributes('type')).toBe('password')
      expect(generic.find('input[data-field="batch"]').attributes('type')).toBe('number')
      expect(generic.find('input[data-field="tls"]').element.checked).toBe(true)
      expect(generic.find('textarea[data-field="headers"]').exists()).toBe(true)
      expect(generic.find('[data-test="secret-state"]').text()).toBe('not set')
      expect(w.find('[data-test="trigger-delivery"]').exists()).toBe(true)
    })

    it('checks the required fields and sends config and secrets', async () => {
      const w = mountEditor({ type: 'kafka', types: TYPES_WITH_KAFKA })
      await save(w)
      expect(triggersApi.create).not.toHaveBeenCalled()
      expect(w.find('[data-generic-field="brokers"]').text()).toContain('Required.')
      expect(w.find('[data-generic-field="sasl.password"]').text()).toContain('Required.')

      await w.find('[data-field="brokers"]').setValue('a:9092\nb:9092')
      await w.find('[data-field="topic"]').setValue('orders')
      await w.find('[data-field="sasl.password"]').setValue('pw-123')
      await w.find('[data-field="tls"]').setValue(false)
      await w.find('[data-field="headers"]').setValue('{nope')
      await save(w)
      expect(w.find('[data-generic-field="headers"]').text()).toContain('Enter valid JSON.')

      await w.find('[data-field="headers"]').setValue('{"x": "1"}')
      await save(w)
      expect(triggersApi.create).toHaveBeenCalledWith('p1', {
        type: 'kafka', enabled: true,
        config: { brokers: ['a:9092', 'b:9092'], topic: 'orders', group: 'runr', sasl: { mechanism: 'plain' }, batch: 10, tls: false, poll: '5s', headers: { x: '1' } },
        secrets: { sasl: { password: 'pw-123' } },
      })
    })

    it('edits a stored trigger without asking for its stored secret again', async () => {
      const stored = trigger({ id: 'k1', name: 'orders-in', type: 'kafka', label: 'Kafka', config: { brokers: ['a:9092'], topic: 'orders', sasl: { mechanism: 'scram-sha-256' } }, secrets: ['sasl'] })
      const w = mountEditor({ type: 'kafka', types: TYPES_WITH_KAFKA, trigger: stored })
      expect(w.find('[data-field="brokers"]').element.value).toBe('a:9092')
      expect(w.find('[data-field="sasl.mechanism"]').element.value).toBe('scram-sha-256')
      expect(w.find('[data-field="sasl.password"]').element.value).toBe('')
      expect(w.find('[data-test="secret-state"]').text()).toBe('set')
      await save(w)
      expect(triggersApi.update).toHaveBeenCalledWith('p1', 'k1', {
        name: 'orders-in', enabled: true,
        config: { brokers: ['a:9092'], topic: 'orders', group: 'runr', sasl: { mechanism: 'scram-sha-256' }, batch: 10, tls: true, poll: '5s' },
      })
    })

    it('says so for a type without fields', () => {
      const w = mountEditor({ type: 'nats', types: [...TYPES, { ...KAFKA, type: 'nats', label: 'NATS', fields: [] }] })
      expect(w.find('[data-test="generic-fields"]').text()).toContain('This trigger type has no settings.')
    })
  })
})
