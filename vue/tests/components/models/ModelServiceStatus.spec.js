import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModelServiceDetail from '@/components/models/ModelServiceDetail.vue'
import ModelServicesList from '@/components/models/ModelServicesList.vue'
import { useAiStore } from '@/stores/ai'
import { makeI18n, freshPinia } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({ aiApi: { getService: vi.fn(), serviceLogs: vi.fn(), serviceBindings: vi.fn().mockResolvedValue({ data: { data: [] } }) } }))

const i18n = makeI18n()
let pinia
const mounted = []

const crashMessage = 'CrashLoopBackOff: the model container keeps exiting (4 restarts, last exit code 1, Error).\n'
  + 'Error: Could not download model artifacts\n\nCaused by:\n    relative URL without a base'

function service(overrides = {}) {
  return {
    id: 's1', name: 'minilm', model: { hub_id: 'sentence-transformers/all-MiniLM-L6-v2', task: 'feature-extraction' },
    region_name: 'eu-1', engine: 'tei', device: 'cpu', mode: 'online', cpu: '1', memory_mb: 1024,
    min_scale: 0, max_scale: 1, scale_to_zero: true, timeout_seconds: 300, url: null,
    playground: { kind: 'embeddings', path: '/v1/embeddings' },
    status: 'deploying', status_message: null, status_reason: null,
    ...overrides,
  }
}

function mountIt(component, props = {}) {
  const wrapper = mount(component, { props, global: { plugins: [pinia, i18n], stubs: { ModelPlayground: true, RouterLink: true } } })
  mounted.push(wrapper)
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Model service status → why the pods do not start', () => {
  it('shows the reason next to the status and the engine output below it', async () => {
    const w = mountIt(ModelServiceDetail, { service: service({ status_reason: 'CrashLoopBackOff', status_message: crashMessage }) })
    await flushPromises()

    expect(w.find('[data-testid="service-status"]').text()).toBe('Deploying')
    const reason = w.find('[data-testid="service-status-reason"]')
    expect(reason.text()).toBe('CrashLoopBackOff')
    expect(reason.attributes('title')).toBe('Why Kubernetes cannot start the pods of the service')
    const message = w.find('[data-testid="service-status-message"]')
    expect(message.text()).toContain('relative URL without a base')
    expect(message.classes()).toContain('text-red-300')
  })

  it('shows no reason while the pods are only starting', async () => {
    const w = mountIt(ModelServiceDetail, { service: service({ status_message: 'Configuration "m-minilm" is waiting for a Revision to become ready.' }) })
    await flushPromises()

    expect(w.find('[data-testid="service-status-reason"]').exists()).toBe(false)
    expect(w.find('[data-testid="service-status-message"]').classes()).toContain('text-gray-400')
  })

  it('lists the reason with the message as its tooltip', () => {
    useAiStore().services = [
      service({ status_reason: 'ImagePullBackOff', status_message: 'ImagePullBackOff: the image of the model container cannot be pulled: Back-off pulling image "ghcr.io/huggingface/text-embeddings-inference:cpu-9.9"' }),
      service({ id: 's2', name: 'bert', status: 'ready' }),
    ]
    const w = mountIt(ModelServicesList)

    const reason = w.find('[data-service-reason="minilm"]')
    expect(reason.text()).toBe('ImagePullBackOff')
    expect(reason.attributes('title')).toContain('Back-off pulling image')
    expect(w.find('[data-service-reason="bert"]').exists()).toBe(false)
  })
})
