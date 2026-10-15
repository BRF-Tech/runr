// Helpers of the AI screens (GPU fields, model services, bindings, playground).

/** Human-readable byte size (binary units). */
export function formatBytes(bytes) {
  const n = Number(bytes)
  if (!Number.isFinite(n) || n <= 0) return '—'
  const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB']
  let value = n
  let i = 0
  while (value >= 1024 && i < units.length - 1) { value /= 1024; i++ }
  return `${value >= 100 || i === 0 ? Math.round(value) : value.toFixed(1)} ${units[i]}`
}

/** GPU memory in MiB as GiB text ("6 GiB", "79.6 GiB"). */
export function formatGpuMemory(mb) {
  const n = Number(mb)
  if (!Number.isFinite(n) || n <= 0) return '—'
  // one decimal, without a trailing ".0": cards report a little under the size (6141 MiB → "6 GiB")
  const gib = Math.round(n / 1024 * 10) / 10
  return `${Number.isInteger(gib) ? gib : gib.toFixed(1)} GiB`
}

/** Compact count (12.3k, 4.5M). */
export function formatCount(n) {
  const v = Number(n)
  if (!Number.isFinite(v)) return '—'
  if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`
  if (v >= 1_000) return `${(v / 1_000).toFixed(1)}k`
  return String(v)
}

/** Parameter count (0.5B, 7.2B, 110M). */
export function formatParameters(n) {
  const v = Number(n)
  if (!Number.isFinite(v) || v <= 0) return '—'
  if (v >= 1e9) return `${(v / 1e9).toFixed(1)}B`
  if (v >= 1e6) return `${Math.round(v / 1e6)}M`
  return formatCount(v)
}

/** Binding alias of a service name: `qwen-chat` → `QWEN_CHAT` (env var part). */
export function aliasFor(name) {
  let alias = String(name ?? '').toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '')
  if (!alias || !/^[A-Z]/.test(alias)) alias = `M_${alias}`.replace(/_+$/, '')
  return alias.slice(0, 32).replace(/_+$/, '')
}

export const ALIAS_PATTERN = /^[A-Z][A-Z0-9_]{0,31}$/

export function isValidAlias(alias) {
  return ALIAS_PATTERN.test(String(alias ?? ''))
}

/** Engines that answer the OpenAI-compatible routes under /v1. */
export const OPENAI_COMPATIBLE_APIS = ['openai', 'tei', 'runr']

/**
 * The env vars a binding injects (the API answers the authoritative list with every binding;
 * this preview is shown before a binding exists).
 */
export function bindingEnv(alias, service, { mountWeights = false } = {}) {
  if (!alias || !service) return {}
  const env = {
    [`RUNR_MODEL_${alias}_URL`]:   service.url ?? '',
    [`RUNR_MODEL_${alias}_MODEL`]: service.served_name ?? service.name ?? '',
    [`RUNR_MODEL_${alias}_API`]:   service.api ?? 'custom',
    [`RUNR_MODEL_${alias}_TASK`]:  service.model?.task ?? '',
  }
  if (OPENAI_COMPATIBLE_APIS.includes(service.api) && service.url) {
    env[`RUNR_MODEL_${alias}_OPENAI_BASE`] = `${service.url}/v1`
  }
  if (mountWeights) env[`RUNR_MODEL_${alias}_PATH`] = `/models/${alias.toLowerCase()}`
  return env
}

/** The playground form a service gets (API `playground.kind`, else derived from the task). */
export function playgroundKind(service) {
  if (service?.playground?.kind) return service.playground.kind
  const task = service?.model?.task
  const map = {
    'text-generation':              'chat',
    'feature-extraction':           'embeddings',
    'sentence-similarity':          'embeddings',
    'text-classification':          'classification',
    'zero-shot-classification':     'classification',
    'token-classification':         'token-classification',
    summarization:                  'summarization',
    translation:                    'translation',
    'text2text-generation':         'summarization',
    'fill-mask':                    'fill-mask',
    'question-answering':           'question-answering',
    'automatic-speech-recognition': 'asr',
    'image-classification':         'image-classification',
    'object-detection':             'image-classification',
    'text-to-image':                'text-to-image',
  }
  return map[task] ?? 'generic'
}

/** Kinds whose input is a file (base64 to the service). */
export const FILE_KINDS = ['asr', 'image-classification']

/** Kinds that send `{ inputs: <text> }` to /predict. */
export const TEXT_PREDICT_KINDS = ['classification', 'token-classification', 'summarization', 'translation', 'fill-mask']

/**
 * The invoke request (POST /model-services/{id}/invoke) of a playground form.
 * input: { text, system, question, context, prompt, fileBase64, path, rawBody, maxTokens }
 */
export function playgroundRequest(kind, service, input = {}) {
  const model = service?.served_name ?? service?.name ?? ''
  const maxTokens = Number(input.maxTokens) > 0 ? Number(input.maxTokens) : 256

  switch (kind) {
    case 'chat': {
      const messages = []
      if (input.system?.trim()) messages.push({ role: 'system', content: input.system.trim() })
      messages.push({ role: 'user', content: input.text ?? '' })
      return { method: 'POST', path: '/v1/chat/completions', body: { model, messages, max_tokens: maxTokens } }
    }
    case 'completion':
      return { method: 'POST', path: '/v1/completions', body: { model, prompt: input.text ?? '', max_tokens: maxTokens } }
    case 'embeddings': {
      const lines = String(input.text ?? '').split('\n').map(l => l.trim()).filter(Boolean)
      return { method: 'POST', path: '/v1/embeddings', body: { model, input: lines.length > 1 ? lines : (lines[0] ?? '') } }
    }
    case 'question-answering':
      return { method: 'POST', path: '/predict', body: { inputs: { question: input.question ?? '', context: input.context ?? '' } } }
    case 'asr':
    case 'image-classification':
      return { method: 'POST', path: '/predict', body: { inputs: input.fileBase64 ?? '' } }
    case 'text-to-image':
      return { method: 'POST', path: '/v1/images/generations', body: { model, prompt: input.prompt ?? input.text ?? '', n: 1 } }
    case 'generic': {
      let body = input.rawBody ?? '{}'
      try { body = JSON.parse(body) } catch { /* sent as text */ }
      return { method: input.method === 'GET' ? 'GET' : 'POST', path: input.path || service?.playground?.path || '/', body }
    }
    default:
      return { method: 'POST', path: '/predict', body: { inputs: input.text ?? '' } }
  }
}

/** What the playground shows from an invoke answer. */
export function playgroundResult(kind, body) {
  if (body == null) return { type: 'text', value: '' }
  if (typeof body === 'string') return { type: 'text', value: body }

  if (kind === 'chat') {
    const text = body.choices?.[0]?.message?.content
    if (typeof text === 'string') return { type: 'text', value: text }
  }
  if (kind === 'completion') {
    const text = body.choices?.[0]?.text
    if (typeof text === 'string') return { type: 'text', value: text }
  }
  if (kind === 'embeddings' && Array.isArray(body.data)) {
    const vectors = body.data.map(d => d.embedding).filter(Array.isArray)
    return {
      type: 'embeddings',
      count: vectors.length,
      dimensions: vectors[0]?.length ?? 0,
      preview: (vectors[0] ?? []).slice(0, 8).map(v => Number(v).toFixed(4)),
    }
  }
  if (kind === 'text-to-image') {
    const b64 = body.data?.[0]?.b64_json ?? body.outputs?.[0]?.image
    if (b64) return { type: 'image', value: `data:image/png;base64,${b64}` }
  }
  return { type: 'json', value: JSON.stringify(body.outputs ?? body, null, 2) }
}

/** Read a File as base64 (no data: prefix). */
export function fileToBase64(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => {
      const result = String(reader.result ?? '')
      resolve(result.includes(',') ? result.slice(result.indexOf(',') + 1) : result)
    }
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

/** Usage snippets of a binding alias for a function's code. */
export function usageSnippets(alias, service) {
  const api  = service?.api ?? 'custom'
  const kind = playgroundKind(service)
  const url  = `RUNR_MODEL_${alias}_URL`
  const mdl  = `RUNR_MODEL_${alias}_MODEL`

  const route = api === 'custom' ? '/' : kind === 'chat' ? '/v1/chat/completions'
    : kind === 'embeddings' ? '/v1/embeddings'
    : kind === 'text-to-image' ? '/v1/images/generations' : '/predict'

  // Request body as JSON with a MODEL placeholder (kinds without a model name have none)
  const template = kind === 'chat'
    ? '{"model":MODEL,"messages":[{"role":"user","content":"Hello!"}]}'
    : kind === 'embeddings'
      ? '{"model":MODEL,"input":"RunR runs functions"}'
      : kind === 'text-to-image'
        ? '{"model":MODEL,"prompt":"a lighthouse at dawn"}'
        : '{"inputs":"RunR runs functions"}'
  const hasModel = template.includes('MODEL')

  const bodyPy   = template.replace('MODEL', `os.environ["${mdl}"]`)
  const bodyJs   = template.replace('MODEL', `process.env.${mdl}`)
  const bodyJson = template.replace('MODEL', `"'"$${mdl}"'"`)
  const bodyGo   = hasModel
    ? 'fmt.Sprintf(`' + template.replace('MODEL', '%q') + '`, os.Getenv("' + mdl + '"))'
    : '`' + template + '`'

  return {
    python: `import json, os, urllib.request

def handler(event, context):
    req = urllib.request.Request(
        os.environ["${url}"] + "${route}",
        data=json.dumps(${bodyPy}).encode(),
        headers={"Content-Type": "application/json"},
    )
    with urllib.request.urlopen(req, timeout=120) as res:
        return {"statusCode": 200, "body": res.read().decode()}
`,
    node: `exports.handler = async (event, context) => {
  const res = await fetch(process.env.${url} + '${route}', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(${bodyJs}),
  })
  return { statusCode: res.status, body: await res.text() }
}
`,
    go: `package main

import (
${hasModel ? '\t"fmt"\n' : ''}\t"io"
\t"net/http"
\t"os"
\t"strings"
)

func Handler(event interface{}, context interface{}) (interface{}, error) {
\tbody := strings.NewReader(${bodyGo})
\tres, err := http.Post(os.Getenv("${url}")+"${route}", "application/json", body)
\tif err != nil {
\t\treturn nil, err
\t}
\tdefer res.Body.Close()
\tout, err := io.ReadAll(res.Body)
\tif err != nil {
\t\treturn nil, err
\t}
\treturn map[string]interface{}{"statusCode": res.StatusCode, "body": string(out)}, nil
}
`,
    curl: `curl -sS "$${url}${route}" \\
  -H 'Content-Type: application/json' \\
  -d '${bodyJson}'
`,
  }
}

/** Badge classes per model service / snapshot / upload status. */
export const AI_STATUS_CLASSES = {
  ready:            'bg-emerald-900/40 text-emerald-300 border-emerald-800',
  completed:        'bg-emerald-900/40 text-emerald-300 border-emerald-800',
  deploying:        'bg-blue-900/40 text-blue-300 border-blue-800',
  running:          'bg-blue-900/40 text-blue-300 border-blue-800',
  installing:       'bg-blue-900/40 text-blue-300 border-blue-800',
  completing:       'bg-blue-900/40 text-blue-300 border-blue-800',
  preparing:        'bg-blue-900/40 text-blue-300 border-blue-800',
  uninstalling:     'bg-orange-900/40 text-orange-300 border-orange-800',
  deleting:         'bg-orange-900/40 text-orange-300 border-orange-800',
  waiting_snapshot: 'bg-yellow-900/40 text-yellow-300 border-yellow-800',
  pending:          'bg-yellow-900/40 text-yellow-300 border-yellow-800',
  failed:           'bg-red-900/40 text-red-300 border-red-800',
  error:            'bg-red-900/40 text-red-300 border-red-800',
  aborted:          'bg-gray-800 text-gray-400 border-gray-700',
  stopped:          'bg-gray-800 text-gray-400 border-gray-700',
  not_installed:    'bg-gray-800 text-gray-400 border-gray-700',
}

/** Statuses that change on their own (the screens poll while one is shown). */
export const AI_TRANSIENT = ['deploying', 'waiting_snapshot', 'pending', 'running', 'installing', 'uninstalling', 'deleting', 'preparing', 'completing']

/** Parse `KEY=value` lines (env editor) into an object; undefined when empty. */
export function parseEnvLines(raw) {
  const out = {}
  for (const line of String(raw ?? '').split('\n')) {
    const i = line.indexOf('=')
    if (i <= 0) continue
    const key = line.slice(0, i).trim()
    if (key) out[key] = line.slice(i + 1).trim()
  }
  return Object.keys(out).length ? out : undefined
}

/** One argument per line → string[] (undefined when empty). */
export function parseArgLines(raw) {
  const args = String(raw ?? '').split('\n').map(l => l.trim()).filter(Boolean)
  return args.length ? args : undefined
}

/** GPU fields of a form (`{enabled, count, type, memory_min}`) as the API's `gpu` object. */
export function gpuPayload(gpu) {
  if (!gpu?.enabled) return null
  const count = Math.max(1, Math.min(8, parseInt(gpu.count, 10) || 1))
  const out = { count }
  if (gpu.type) out.type = gpu.type
  if (Number(gpu.memory_min) > 0) out.memory_min = Number(gpu.memory_min)
  return out
}

/** Form state of a stored `gpu` object (null → disabled). */
export function gpuForm(gpu) {
  return {
    enabled:    !!gpu,
    count:      gpu?.count ?? 1,
    type:       gpu?.type ?? '',
    memory_min: gpu?.memory_min ?? null,
  }
}

/** Library filter of the Hub search. */
export const HUB_LIBRARIES = ['transformers', 'sentence-transformers', 'diffusers', 'timm', 'peft', 'gguf', 'onnx', 'safetensors']

/** Default timeout of a GPU workload when the form still has the 30 s default. */
export const GPU_DEFAULT_TIMEOUT = 300
