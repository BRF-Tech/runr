import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import ModelPlayground from '@/components/models/ModelPlayground.vue'
import { aiApi } from '@/api/ai'
import { makeI18n, freshPinia, modelService } from '@/test-support/helpers'

vi.mock('@/api/ai', () => ({ aiApi: { invokeService: vi.fn() } }))

const i18n = makeI18n()
let pinia
const mounted = []

async function mountPlayground(service) {
  const wrapper = mount(ModelPlayground, { props: { service }, global: { plugins: [pinia, i18n] } })
  mounted.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => { pinia = freshPinia(); vi.clearAllMocks() })
afterEach(() => { mounted.splice(0).forEach(w => w.unmount()) })

describe('Model playground', () => {
  it('embeds text through /v1/embeddings and shows the vector shape', async () => {
    aiApi.invokeService.mockResolvedValue({ data: { status: 200, duration_ms: 42, content_type: 'application/json', body: { data: [{ embedding: Array(384).fill(0.01) }] } } })
    const w = await mountPlayground(modelService())

    expect(w.find('[data-testid="playground"]').attributes('data-kind')).toBe('embeddings')
    await w.find('[data-testid="pg-text"]').setValue('RunR runs functions')
    await w.find('[data-testid="pg-run"]').trigger('click')
    await flushPromises()

    expect(aiApi.invokeService).toHaveBeenCalledWith('ms1', { method: 'POST', path: '/v1/embeddings', body: { model: 'minilm', input: 'RunR runs functions' } })
    expect(w.find('[data-testid="pg-result"]').text()).toContain('HTTP 200 · 42 ms')
    expect(w.find('[data-testid="pg-embeddings"]').text()).toContain('1 vector(s) with 384 dimensions')
  })

  it('chats with an OpenAI-compatible LLM', async () => {
    aiApi.invokeService.mockResolvedValue({ data: { status: 200, duration_ms: 900, body: { choices: [{ message: { content: 'Merhaba! Hello! Hallo!' } }] } } })
    const w = await mountPlayground(modelService({ id: 'ms2', served_name: 'qwen', playground: { kind: 'chat', path: '/v1/chat/completions' } }))

    await w.find('[data-testid="pg-text"]').setValue('Say hello in three languages.')
    await w.find('[data-testid="pg-run"]').trigger('click')
    await flushPromises()

    const [, request] = aiApi.invokeService.mock.calls[0]
    expect(request.path).toBe('/v1/chat/completions')
    expect(request.body.messages).toEqual([{ role: 'user', content: 'Say hello in three languages.' }])
    expect(w.find('[data-testid="pg-result"]').text()).toContain('Merhaba! Hello! Hallo!')
  })

  it('sends question and context for question answering', async () => {
    aiApi.invokeService.mockResolvedValue({ data: { status: 200, duration_ms: 10, body: { outputs: { answer: 'London', score: 0.98 } } } })
    const w = await mountPlayground(modelService({ playground: { kind: 'question-answering', path: '/predict' } }))

    expect(w.find('[data-testid="pg-run"]').attributes('disabled')).toBeDefined()
    await w.find('[data-testid="pg-question"]').setValue('Where was Ada born?')
    await w.find('[data-testid="pg-context"]').setValue('Ada Lovelace was born in London.')
    await w.find('[data-testid="pg-run"]').trigger('click')
    await flushPromises()

    expect(aiApi.invokeService.mock.calls[0][1].body).toEqual({ inputs: { question: 'Where was Ada born?', context: 'Ada Lovelace was born in London.' } })
    expect(w.find('[data-testid="pg-result"]').text()).toContain('London')
  })

  it('renders a generated image', async () => {
    aiApi.invokeService.mockResolvedValue({ data: { status: 200, duration_ms: 3000, body: { data: [{ b64_json: 'iVBORw0KGgo=' }] } } })
    const w = await mountPlayground(modelService({ playground: { kind: 'text-to-image', path: '/v1/images/generations' } }))

    await w.find('[data-testid="pg-prompt"]').setValue('a lighthouse at dawn')
    await w.find('[data-testid="pg-run"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="pg-image"]').attributes('src')).toBe('data:image/png;base64,iVBORw0KGgo=')
  })

  it('shows the error of a failed call and a hint while the service is not ready', async () => {
    aiApi.invokeService.mockRejectedValue(Object.assign(new Error('502'), { response: { status: 502, data: { message: 'The model service did not answer.' } } }))
    const w = await mountPlayground(modelService({ status: 'deploying' }))

    expect(w.text()).toContain('still starting')
    await w.find('[data-testid="pg-text"]').setValue('x')
    await w.find('[data-testid="pg-run"]').trigger('click')
    await flushPromises()

    expect(w.find('[data-testid="pg-error"]').text()).toContain('did not answer')
  })
})
