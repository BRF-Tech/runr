import { describe, it, expect } from 'vitest'
import {
  aliasFor, isValidAlias, bindingEnv, playgroundKind, playgroundRequest, playgroundResult,
  gpuPayload, gpuForm, parseEnvLines, parseArgLines, usageSnippets, formatBytes, formatGpuMemory, formatParameters,
} from '@/utils/ai'
import { buildConfigPayload } from '@/composables/useProjectForm'
import { modelService } from '@/test-support/helpers'

describe('binding aliases', () => {
  it('derives an env-safe alias from a service name', () => {
    expect(aliasFor('qwen-chat')).toBe('QWEN_CHAT')
    expect(aliasFor('minilm')).toBe('MINILM')
    expect(aliasFor('7b-model')).toBe('M_7B_MODEL')
    expect(aliasFor('a'.repeat(40))).toHaveLength(32)
  })

  it('validates aliases like the API (^[A-Z][A-Z0-9_]{0,31}$)', () => {
    expect(isValidAlias('EMBED')).toBe(true)
    expect(isValidAlias('EMBED_2')).toBe(true)
    expect(isValidAlias('embed')).toBe(false)
    expect(isValidAlias('2EMBED')).toBe(false)
    expect(isValidAlias('A'.repeat(33))).toBe(false)
  })
})

describe('bindingEnv (preview of RUNR_MODEL_<ALIAS>_*)', () => {
  it('lists URL, model, API, task and the OpenAI base for OpenAI-compatible engines', () => {
    const env = bindingEnv('EMBED', modelService({ api: 'tei' }))
    expect(env).toEqual({
      RUNR_MODEL_EMBED_URL:         'http://m-minilm.fn.svc.cluster.local',
      RUNR_MODEL_EMBED_MODEL:       'minilm',
      RUNR_MODEL_EMBED_API:         'tei',
      RUNR_MODEL_EMBED_TASK:        'feature-extraction',
      RUNR_MODEL_EMBED_OPENAI_BASE: 'http://m-minilm.fn.svc.cluster.local/v1',
    })
  })

  it('adds the read-only mount path with mount weights and no OpenAI base for custom images', () => {
    const env = bindingEnv('LLM', modelService({ api: 'custom' }), { mountWeights: true })
    expect(env.RUNR_MODEL_LLM_PATH).toBe('/models/llm')
    expect(env).not.toHaveProperty('RUNR_MODEL_LLM_OPENAI_BASE')
  })
})

describe('playground', () => {
  it('picks the form from the API kind, else from the task', () => {
    expect(playgroundKind({ playground: { kind: 'chat' } })).toBe('chat')
    expect(playgroundKind({ model: { task: 'automatic-speech-recognition' } })).toBe('asr')
    expect(playgroundKind({ model: { task: 'text-to-image' } })).toBe('text-to-image')
    expect(playgroundKind({ model: { task: 'something-else' } })).toBe('generic')
  })

  it('builds the invoke request per kind', () => {
    const s = modelService({ served_name: 'qwen' })
    expect(playgroundRequest('chat', s, { text: 'Hi', system: 'Be brief', maxTokens: 32 })).toEqual({
      method: 'POST', path: '/v1/chat/completions',
      body: { model: 'qwen', messages: [{ role: 'system', content: 'Be brief' }, { role: 'user', content: 'Hi' }], max_tokens: 32 },
    })
    expect(playgroundRequest('embeddings', s, { text: 'one\ntwo' }).body).toEqual({ model: 'qwen', input: ['one', 'two'] })
    expect(playgroundRequest('embeddings', s, { text: 'one' }).body).toEqual({ model: 'qwen', input: 'one' })
    expect(playgroundRequest('question-answering', s, { question: 'Q?', context: 'C.' })).toEqual({
      method: 'POST', path: '/predict', body: { inputs: { question: 'Q?', context: 'C.' } },
    })
    expect(playgroundRequest('asr', s, { fileBase64: 'AAAA' }).body).toEqual({ inputs: 'AAAA' })
    expect(playgroundRequest('classification', s, { text: 'great' })).toEqual({ method: 'POST', path: '/predict', body: { inputs: 'great' } })
    expect(playgroundRequest('text-to-image', s, { prompt: 'a cat' }).path).toBe('/v1/images/generations')
    expect(playgroundRequest('generic', s, { path: '/v1/models', method: 'GET', rawBody: 'not json' })).toEqual({
      method: 'GET', path: '/v1/models', body: 'not json',
    })
  })

  it('shows chat text, embedding shapes, images and JSON', () => {
    expect(playgroundResult('chat', { choices: [{ message: { content: 'Hello!' } }] })).toEqual({ type: 'text', value: 'Hello!' })
    const emb = playgroundResult('embeddings', { data: [{ embedding: [0.1, 0.2, 0.3] }, { embedding: [1, 2, 3] }] })
    expect(emb).toMatchObject({ type: 'embeddings', count: 2, dimensions: 3 })
    expect(emb.preview).toEqual(['0.1000', '0.2000', '0.3000'])
    expect(playgroundResult('text-to-image', { data: [{ b64_json: 'iVBOR' }] })).toEqual({ type: 'image', value: 'data:image/png;base64,iVBOR' })
    expect(playgroundResult('classification', { outputs: [{ label: 'POSITIVE', score: 0.99 }] }).type).toBe('json')
  })
})

describe('GPU form helpers', () => {
  it('turns the form state into the API gpu object', () => {
    expect(gpuPayload({ enabled: false, count: 2 })).toBeNull()
    expect(gpuPayload({ enabled: true, count: 2, type: '', memory_min: null })).toEqual({ count: 2 })
    expect(gpuPayload({ enabled: true, count: 20, type: 'NVIDIA-A100', memory_min: 40960 })).toEqual({ count: 8, type: 'NVIDIA-A100', memory_min: 40960 })
    expect(gpuForm(null)).toEqual({ enabled: false, count: 1, type: '', memory_min: null })
    expect(gpuForm({ count: 1, type: 'NVIDIA-L4' })).toEqual({ enabled: true, count: 1, type: 'NVIDIA-L4', memory_min: null })
  })

  it('sends config.gpu when on and null when a stored GPU is turned off', () => {
    const base = { cpu: 1000, memory_mb: 2048, timeout_seconds: 300, trigger_type: 'http', scale_to_zero: true, min_scale: 0, max_scale: 1, env_vars: '', image: 'img', image_pull_policy: 'IfNotPresent', port: 8080, command: '' }
    expect(buildConfigPayload({ ...base, gpu: { enabled: true, count: 1, type: 'NVIDIA-L4', memory_min: null } }, 'caas').gpu).toEqual({ count: 1, type: 'NVIDIA-L4' })
    expect(buildConfigPayload({ ...base, gpu: { enabled: false, count: 1, stored: true } }, 'caas').gpu).toBeNull()
    expect(buildConfigPayload({ ...base, gpu: { enabled: false, count: 1 } }, 'caas')).not.toHaveProperty('gpu')
    expect(buildConfigPayload(base, 'xaas')).not.toHaveProperty('gpu')
  })
})

describe('advanced fields', () => {
  it('parses env lines and argument lines', () => {
    expect(parseEnvLines('A=1\nB = two=2\n\nbad')).toEqual({ A: '1', B: 'two=2' })
    expect(parseEnvLines('')).toBeUndefined()
    expect(parseArgLines('--max-model-len\n 4096 \n')).toEqual(['--max-model-len', '4096'])
    expect(parseArgLines('  ')).toBeUndefined()
  })
})

describe('usage snippets', () => {
  it('reads the binding variables in Python, Node.js, Go and curl', () => {
    const chat = usageSnippets('LLM', modelService({ api: 'openai', playground: { kind: 'chat' } }))
    expect(chat.python).toContain('os.environ["RUNR_MODEL_LLM_URL"] + "/v1/chat/completions"')
    expect(chat.python).toContain('os.environ["RUNR_MODEL_LLM_MODEL"]')
    expect(chat.node).toContain("process.env.RUNR_MODEL_LLM_URL + '/v1/chat/completions'")
    expect(chat.go).toContain('os.Getenv("RUNR_MODEL_LLM_MODEL")')
    expect(chat.go).toContain('"fmt"')
    expect(chat.curl).toContain('$RUNR_MODEL_LLM_URL/v1/chat/completions')

    const ner = usageSnippets('NER', modelService({ api: 'runr', playground: { kind: 'token-classification' } }))
    expect(ner.python).toContain('/predict')
    expect(ner.go).not.toContain('"fmt"')
  })
})

describe('formatting', () => {
  it('formats sizes, GPU memory and parameter counts', () => {
    expect(formatBytes(90_900_000)).toBe('86.7 MiB')
    expect(formatBytes(0)).toBe('—')
    expect(formatGpuMemory(6144)).toBe('6 GiB')
    expect(formatGpuMemory(81_559)).toBe('79.6 GiB')
    expect(formatParameters(22_700_000)).toBe('23M')
    expect(formatParameters(7_240_000_000)).toBe('7.2B')
  })
})
