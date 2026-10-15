import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createRouter, createMemoryHistory } from 'vue-router'
import ProjectModels from '@/components/project/ProjectModels.vue'
import { aiApi } from '@/api/ai'
import { makeI18n, freshPinia, modelService } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({
  aiApi: { projectModels: vi.fn(), services: vi.fn(), bindModel: vi.fn(), updateBinding: vi.fn(), unbindModel: vi.fn() },
}))
const confirmMock = vi.fn(() => Promise.resolve(true))
vi.mock('@/composables/useConfirm', () => ({ useConfirm: () => ({ confirm: confirmMock }) }))

const i18n = makeI18n()
let pinia
let router
const mounted = []

const project = { id: 'p1', name: 'search-api', service_type: 'faas', runtime: 'python', knative_region_id: 'r1' }

const minilm = modelService()
const qwen   = modelService({
  id: 'ms2', name: 'qwen-chat', served_name: 'qwen-chat', engine: 'vllm', device: 'gpu', api: 'openai',
  url: 'http://m-qwen-chat.fn.svc.cluster.local', playground: { kind: 'chat', path: '/v1/chat/completions' },
  model: { id: 'am2', name: 'qwen2-0-5b', hub_id: 'Qwen/Qwen2.5-0.5B-Instruct', task: 'text-generation', size_bytes: 990_000_000 },
})
const elsewhere = modelService({ id: 'ms3', name: 'other-region', knative_region_id: 'r2' })

function binding(extra = {}) {
  return {
    id: 'b1', project_id: 'p1', ai_model_service_id: 'ms1', alias: 'MINILM', mount_weights: false,
    env: {
      RUNR_MODEL_MINILM_URL: 'http://m-minilm.fn.svc.cluster.local', RUNR_MODEL_MINILM_MODEL: 'minilm',
      RUNR_MODEL_MINILM_API: 'tei', RUNR_MODEL_MINILM_TASK: 'feature-extraction',
      RUNR_MODEL_MINILM_OPENAI_BASE: 'http://m-minilm.fn.svc.cluster.local/v1',
    },
    service: minilm, applies_on_next_deploy: true,
    ...extra,
  }
}

async function mountModels(bindings = []) {
  aiApi.projectModels.mockResolvedValue({ data: { data: bindings } })
  aiApi.services.mockResolvedValue({ data: { data: [minilm, qwen, elsewhere], abilities: { view: true, deploy: true, manage: true } } })
  const wrapper = mount(ProjectModels, { props: { project }, global: { plugins: [pinia, i18n, router] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  pinia = freshPinia()
  router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:p(.*)*', component: { render: () => null } }] })
  vi.clearAllMocks()
})
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Project → Models', () => {
  it('lists the bindings with their env vars and says they apply on the next deploy', async () => {
    const w = await mountModels([binding()])

    expect(aiApi.projectModels).toHaveBeenCalledWith('p1')
    expect(w.find('[data-testid="bind-next-deploy"]').text()).toContain('next deploy')
    const env = w.find('[data-binding="MINILM"] [data-testid="binding-env"]').text()
    expect(env).toContain('RUNR_MODEL_MINILM_URL=http://m-minilm.fn.svc.cluster.local')
    expect(env).toContain('RUNR_MODEL_MINILM_OPENAI_BASE=http://m-minilm.fn.svc.cluster.local/v1')
  })

  it('offers only the services of the project region that are still unbound', async () => {
    const w = await mountModels([binding()])

    await w.find('[data-testid="bind-open"]').trigger('click')
    const options = w.findAll('[data-testid="bind-service"] option').map(o => o.attributes('value')).filter(Boolean)
    expect(options).toEqual(['ms2'])
  })

  it('binds a service with a default alias, previewing the env vars', async () => {
    const w = await mountModels()
    aiApi.bindModel.mockResolvedValue({ data: { data: binding({ id: 'b2', ai_model_service_id: 'ms2', alias: 'QWEN_CHAT', env: {}, service: qwen }) } })

    await w.find('[data-testid="bind-open"]').trigger('click')
    await w.find('[data-testid="bind-service"]').setValue('ms2')
    await flushPromises()
    expect(w.find('[data-testid="bind-alias"]').element.value).toBe('QWEN_CHAT')
    const preview = w.find('[data-testid="bind-env-preview"]').text()
    expect(preview).toContain('RUNR_MODEL_QWEN_CHAT_URL=http://m-qwen-chat.fn.svc.cluster.local')
    expect(preview).toContain('RUNR_MODEL_QWEN_CHAT_MODEL=qwen-chat')

    await w.find('[data-testid="bind-mount"]').setValue(true)
    expect(w.find('[data-testid="bind-env-preview"]').text()).toContain('RUNR_MODEL_QWEN_CHAT_PATH=/models/qwen_chat')

    await w.find('[data-testid="bind-submit"]').trigger('click')
    await flushPromises()
    expect(aiApi.bindModel).toHaveBeenCalledWith('p1', { ai_model_service_id: 'ms2', alias: 'QWEN_CHAT', mount_weights: true })
    expect(w.find('[data-binding="QWEN_CHAT"]').exists()).toBe(true)
  })

  it('rejects an invalid or already bound alias before calling the API', async () => {
    const w = await mountModels([binding()])

    await w.find('[data-testid="bind-open"]').trigger('click')
    await w.find('[data-testid="bind-service"]').setValue('ms2')
    await flushPromises()
    await w.find('[data-testid="bind-alias"]').setValue('MINILM')
    expect(w.text()).toContain('This alias is bound already.')
    expect(w.find('[data-testid="bind-submit"]').attributes('disabled')).toBeDefined()

    await w.find('[data-testid="bind-alias"]').setValue('1BAD')
    expect(w.find('[data-testid="bind-submit"]').attributes('disabled')).toBeDefined()
  })

  it('shows usage snippets in Python, Node.js, Go and curl', async () => {
    const w = await mountModels([binding()])
    const snippet = () => w.find('[data-binding="MINILM"] [data-testid="binding-snippet"]').text()

    expect(snippet()).toContain('os.environ["RUNR_MODEL_MINILM_URL"]')
    await w.find('[data-binding="MINILM"] [data-testid="snippet-node"]').trigger('click')
    expect(snippet()).toContain('process.env.RUNR_MODEL_MINILM_URL')
    await w.find('[data-binding="MINILM"] [data-testid="snippet-go"]').trigger('click')
    expect(snippet()).toContain('os.Getenv("RUNR_MODEL_MINILM_URL")')
    await w.find('[data-binding="MINILM"] [data-testid="snippet-curl"]').trigger('click')
    expect(snippet()).toContain('$RUNR_MODEL_MINILM_URL/v1/embeddings')
  })

  it('unbinds after a confirmation', async () => {
    const w = await mountModels([binding()])
    aiApi.unbindModel.mockResolvedValue({ status: 204 })

    await w.find('[data-binding="MINILM"] button[title="Unbind"]').trigger('click')
    await flushPromises()

    expect(confirmMock).toHaveBeenCalled()
    expect(aiApi.unbindModel).toHaveBeenCalledWith('p1', 'b1')
    expect(w.find('[data-binding="MINILM"]').exists()).toBe(false)
  })
})
