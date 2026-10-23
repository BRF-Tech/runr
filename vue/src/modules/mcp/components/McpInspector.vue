<script setup>
// Built-in MCP inspector: talks to the hosted server through the RunR gateway (server-side,
// POST /api/projects/{id}/mcp-server/inspector) as the signed-in user — connect, list tools,
// resources and prompts, call a tool from a form generated from its inputSchema, and see every
// JSON-RPC message.
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { mcpApi } from '../api/mcp'
import {
  PROTOCOL_VERSION, rpc, initializeMessage, responseFor, formFields, initialValues, argumentsFromForm,
} from '../utils/inspector'
import JsonBlock from '@/modules/workflows/components/JsonBlock.vue'
import { Plug, Play, List, FileText, MessageSquare, ChevronDown, ChevronRight } from 'lucide-vue-next'

const props = defineProps({ project: { type: Object, required: true } })
const { t } = useI18n()

const sessionId = ref(null)
const protocolVersion = ref(PROTOCOL_VERSION)
const serverInfo = ref(null)
const busy = ref(false)
const error = ref(null)

const tools = ref([])
const resources = ref([])
const prompts = ref([])
const view = ref('tools')     // tools | resources | prompts

const log = ref([])           // { key, direction: 'out' | 'in', label, payload, status }
const expanded = ref(new Set())
let logKey = 0

const selectedTool = ref(null)
const fieldValues = ref({})
const touchedBooleans = ref(new Set())
const rawMode = ref(false)
const rawArgs = ref('{}')
const fieldErrors = ref({})
const lastResult = ref(null)

const promptArgs = ref('{}')

const connected = computed(() => !!serverInfo.value)
const fields = computed(() => formFields(selectedTool.value?.inputSchema))

function addLog(direction, payload, extra = {}) {
  log.value.unshift({ key: ++logKey, direction, payload, label: payload?.method || (payload?.error ? 'error' : 'result'), ...extra })
}

function toggle(key) {
  const next = new Set(expanded.value)
  next.has(key) ? next.delete(key) : next.add(key)
  expanded.value = next
}

/** Sends one JSON-RPC message through the gateway; the server's messages. */
async function send(message) {
  addLog('out', message)
  const { data } = await mcpApi.inspector(props.project.id, {
    message,
    session_id: sessionId.value,
    protocol_version: protocolVersion.value,
  })
  if (data.session_id) sessionId.value = data.session_id
  for (const m of data.messages || []) addLog('in', m, { status: data.status, duration: data.duration_ms })
  if (!(data.messages || []).length && data.status >= 400) {
    addLog('in', { error: { code: data.status, message: data.raw || '' } }, { status: data.status })
  }
  return data.messages || []
}

async function request(method, params) {
  const message = rpc(method, params)
  const messages = await send(message)
  const response = responseFor(messages, message.id)
  if (!response) throw new Error(t('mcp.inspector.noResponse'))
  if (response.error) throw new Error(response.error.message || t('common.error'))
  return response.result
}

// One exchange at a time: the session id and the log follow the order of the messages
async function run(fn) {
  if (busy.value) return
  busy.value = true
  error.value = null
  try {
    await fn()
  } catch (e) {
    error.value = e.response?.data?.message ?? e.message ?? t('common.error')
  } finally {
    busy.value = false
  }
}

function connect() {
  return run(async () => {
    sessionId.value = null
    const init = initializeMessage()
    const messages = await send(init)
    const response = responseFor(messages, init.id)
    if (!response || response.error) throw new Error(response?.error?.message || t('mcp.inspector.noResponse'))
    serverInfo.value = response.result
    protocolVersion.value = response.result?.protocolVersion || PROTOCOL_VERSION
    await send(rpc('notifications/initialized', undefined, { notification: true }))
    await loadTools()
  })
}

async function loadTools() {
  const result = await request('tools/list', {})
  tools.value = result?.tools || []
  view.value = 'tools'
}

function listTools() { return run(loadTools) }

function listResources() {
  return run(async () => {
    const result = await request('resources/list', {})
    resources.value = result?.resources || []
    view.value = 'resources'
  })
}

function listPrompts() {
  return run(async () => {
    const result = await request('prompts/list', {})
    prompts.value = result?.prompts || []
    view.value = 'prompts'
  })
}

function selectTool(tool) {
  selectedTool.value = tool
  fieldValues.value = initialValues(formFields(tool.inputSchema))
  touchedBooleans.value = new Set()
  rawArgs.value = '{}'
  fieldErrors.value = {}
  lastResult.value = null
}

function touchBoolean(name) {
  touchedBooleans.value = new Set([...touchedBooleans.value, name])
}

function callTool() {
  if (!selectedTool.value) return
  let args
  if (rawMode.value) {
    try { args = JSON.parse(rawArgs.value || '{}') } catch {
      fieldErrors.value = { _raw: 'json' }
      return
    }
  } else {
    const built = argumentsFromForm(fields.value, fieldValues.value, touchedBooleans.value)
    fieldErrors.value = built.errors
    if (Object.keys(built.errors).length) return
    args = built.args
  }
  const tool = selectedTool.value
  return run(async () => {
    const result = await request('tools/call', { name: tool.name, arguments: args })
    // Another tool picked meanwhile: its form must not show this tool's result
    if (selectedTool.value === tool) lastResult.value = result
  })
}

function readResource(resource) {
  return run(async () => {
    lastResult.value = await request('resources/read', { uri: resource.uri })
  })
}

function getPrompt(prompt) {
  let args = {}
  try { args = JSON.parse(promptArgs.value || '{}') } catch {
    error.value = t('mcp.inspector.invalidJson')
    return
  }
  return run(async () => {
    lastResult.value = await request('prompts/get', { name: prompt.name, arguments: args })
  })
}

function fieldErrorText(name) {
  const code = fieldErrors.value[name]
  return code ? t(`mcp.inspector.fieldError.${code}`) : ''
}
</script>

<template>
  <section class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 space-y-4" data-testid="mcp-inspector">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="text-sm font-semibold text-gray-100">{{ t('mcp.inspector.title') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('mcp.inspector.hint') }}</p>
      </div>
      <button class="btn-primary text-xs flex items-center gap-1.5" :disabled="busy" data-testid="mcp-inspector-connect" @click="connect">
        <Plug class="w-3.5 h-3.5" />{{ connected ? t('mcp.inspector.reconnect') : t('mcp.inspector.connect') }}
      </button>
    </div>

    <p v-if="error" class="text-xs text-red-400" data-testid="mcp-inspector-error">{{ error }}</p>

    <div v-if="connected" class="space-y-4">
      <div class="flex flex-wrap items-center gap-2 text-[11px] text-gray-400" data-testid="mcp-inspector-server">
        <span class="font-mono text-gray-100">{{ serverInfo.serverInfo?.name }} {{ serverInfo.serverInfo?.version }}</span>
        <span class="font-mono px-1.5 py-0.5 rounded-sm border border-gray-700">{{ protocolVersion }}</span>
        <span v-if="sessionId" class="font-mono truncate" data-testid="mcp-inspector-session">{{ t('mcp.inspector.session', { id: sessionId }) }}</span>
      </div>

      <div class="flex flex-wrap gap-2">
        <button class="btn-secondary text-xs flex items-center gap-1.5" :disabled="busy" data-testid="mcp-inspector-tools" @click="listTools"><List class="w-3.5 h-3.5" />{{ t('mcp.inspector.listTools') }}</button>
        <button class="btn-secondary text-xs flex items-center gap-1.5" :disabled="busy" data-testid="mcp-inspector-resources" @click="listResources"><FileText class="w-3.5 h-3.5" />{{ t('mcp.inspector.listResources') }}</button>
        <button class="btn-secondary text-xs flex items-center gap-1.5" :disabled="busy" data-testid="mcp-inspector-prompts" @click="listPrompts"><MessageSquare class="w-3.5 h-3.5" />{{ t('mcp.inspector.listPrompts') }}</button>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Lists -->
        <div class="space-y-1.5 min-w-0">
          <template v-if="view === 'tools'">
            <p v-if="!tools.length" class="text-xs text-gray-400 italic">{{ t('mcp.inspector.noTools') }}</p>
            <button v-for="tool in tools" :key="tool.name" type="button"
              class="w-full text-left rounded-lg border px-3 py-2 transition-colors"
              :class="selectedTool?.name === tool.name ? 'border-brand-500/60 bg-brand-500/10' : 'border-gray-800 bg-gray-950/40 hover:border-gray-700'"
              :data-testid="`mcp-inspector-tool-${tool.name}`"
              @click="selectTool(tool)">
              <code class="text-xs font-mono text-gray-100">{{ tool.name }}</code>
              <p v-if="tool.description" class="text-[11px] text-gray-400 mt-0.5 line-clamp-2">{{ tool.description }}</p>
            </button>
          </template>

          <template v-else-if="view === 'resources'">
            <p v-if="!resources.length" class="text-xs text-gray-400 italic">{{ t('mcp.inspector.noResources') }}</p>
            <button v-for="r in resources" :key="r.uri" type="button"
              class="w-full text-left rounded-lg border border-gray-800 bg-gray-950/40 hover:border-gray-700 px-3 py-2"
              :data-testid="`mcp-inspector-resource-${r.uri}`"
              @click="readResource(r)">
              <code class="text-xs font-mono text-gray-100 break-all">{{ r.uri }}</code>
              <p v-if="r.name || r.description" class="text-[11px] text-gray-400 mt-0.5">{{ r.name }} {{ r.description }}</p>
            </button>
          </template>

          <template v-else>
            <p v-if="!prompts.length" class="text-xs text-gray-400 italic">{{ t('mcp.inspector.noPrompts') }}</p>
            <label v-if="prompts.length" class="label" for="mcp-prompt-args">{{ t('mcp.inspector.promptArguments') }}</label>
            <textarea v-if="prompts.length" id="mcp-prompt-args" v-model="promptArgs" rows="3" class="input font-mono text-xs h-auto" data-testid="mcp-inspector-prompt-args" />
            <button v-for="p in prompts" :key="p.name" type="button"
              class="w-full text-left rounded-lg border border-gray-800 bg-gray-950/40 hover:border-gray-700 px-3 py-2"
              :data-testid="`mcp-inspector-prompt-${p.name}`"
              @click="getPrompt(p)">
              <code class="text-xs font-mono text-gray-100">{{ p.name }}</code>
              <p v-if="p.description" class="text-[11px] text-gray-400 mt-0.5">{{ p.description }}</p>
            </button>
          </template>
        </div>

        <!-- Tool form / result -->
        <div class="space-y-3 min-w-0">
          <div v-if="view === 'tools' && selectedTool" class="rounded-lg border border-gray-800 bg-gray-950/40 p-3 space-y-3" data-testid="mcp-inspector-form">
            <div class="flex items-center justify-between">
              <code class="text-xs font-mono text-gray-100">{{ selectedTool.name }}</code>
              <label class="flex items-center gap-1.5 text-[11px] text-gray-400">
                <input v-model="rawMode" type="checkbox" class="rounded-sm border-gray-600 bg-gray-800" data-testid="mcp-inspector-raw-toggle" />
                {{ t('mcp.inspector.rawArguments') }}
              </label>
            </div>

            <div v-if="rawMode">
              <textarea v-model="rawArgs" rows="6" class="input font-mono text-xs h-auto" data-testid="mcp-inspector-raw" />
              <p v-if="fieldErrors._raw" class="text-[11px] text-red-400 mt-1">{{ fieldErrorText('_raw') }}</p>
            </div>

            <template v-else>
              <p v-if="!fields.length" class="text-[11px] text-gray-400 italic">{{ t('mcp.inspector.noArguments') }}</p>
              <div v-for="f in fields" :key="f.name">
                <label class="label" :for="`mcp-field-${f.name}`" lang="en">
                  {{ f.name }}<span v-if="f.required" class="text-red-500/70"> *</span>
                </label>
                <select v-if="f.kind === 'enum'" :id="`mcp-field-${f.name}`" v-model="fieldValues[f.name]" class="input" :data-testid="`mcp-field-${f.name}`">
                  <option value="">—</option>
                  <option v-for="o in f.options" :key="String(o)" :value="o">{{ o }}</option>
                </select>
                <input v-else-if="f.kind === 'boolean'" :id="`mcp-field-${f.name}`" v-model="fieldValues[f.name]" type="checkbox"
                  class="rounded-sm border-gray-600 bg-gray-800" :data-testid="`mcp-field-${f.name}`" @change="touchBoolean(f.name)" />
                <input v-else-if="f.kind === 'number' || f.kind === 'integer'" :id="`mcp-field-${f.name}`" v-model="fieldValues[f.name]" type="number"
                  :step="f.kind === 'integer' ? 1 : 'any'" class="input" :data-testid="`mcp-field-${f.name}`" />
                <textarea v-else-if="f.kind === 'json'" :id="`mcp-field-${f.name}`" v-model="fieldValues[f.name]" rows="3"
                  class="input font-mono text-xs h-auto" placeholder="{}" :data-testid="`mcp-field-${f.name}`" />
                <input v-else :id="`mcp-field-${f.name}`" v-model="fieldValues[f.name]" class="input" :data-testid="`mcp-field-${f.name}`" />
                <p v-if="f.description" class="text-[11px] text-gray-400 mt-0.5">{{ f.description }}</p>
                <p v-if="fieldErrors[f.name]" class="text-[11px] text-red-400 mt-0.5">{{ fieldErrorText(f.name) }}</p>
              </div>
            </template>

            <button class="btn-primary text-xs flex items-center gap-1.5" :disabled="busy" data-testid="mcp-inspector-call" @click="callTool">
              <Play class="w-3.5 h-3.5" />{{ t('mcp.inspector.call') }}
            </button>
          </div>

          <JsonBlock v-if="lastResult" :value="lastResult" :label="t('mcp.inspector.result')" :tone="lastResult?.isError ? 'error' : 'default'" max-height="20rem" />
        </div>
      </div>
    </div>

    <!-- Raw JSON-RPC -->
    <div v-if="log.length" class="pt-2 border-t border-gray-800" data-testid="mcp-inspector-log">
      <p class="text-xs font-semibold text-gray-100 mb-2">{{ t('mcp.inspector.log') }}</p>
      <div class="space-y-1">
        <div v-for="entry in log" :key="entry.key" class="rounded-sm border border-gray-800 bg-gray-950/40">
          <button type="button" class="w-full flex items-center gap-2 px-2 py-1 text-left" :data-testid="`mcp-log-${entry.direction}`" @click="toggle(entry.key)">
            <ChevronDown v-if="expanded.has(entry.key)" class="w-3 h-3 text-gray-400" />
            <ChevronRight v-else class="w-3 h-3 text-gray-400" />
            <span class="text-[10px] font-mono" :class="entry.direction === 'out' ? 'text-sky-400' : 'text-emerald-400'">{{ entry.direction === 'out' ? '→' : '←' }}</span>
            <span class="text-[11px] font-mono text-gray-100">{{ entry.label }}</span>
            <span v-if="entry.payload?.id !== undefined" class="text-[10px] font-mono text-gray-500">#{{ entry.payload.id }}</span>
            <span v-if="entry.status" class="text-[10px] font-mono text-gray-500">HTTP {{ entry.status }}</span>
            <span v-if="entry.duration != null" class="text-[10px] font-mono text-gray-500">{{ entry.duration }} ms</span>
          </button>
          <div v-if="expanded.has(entry.key)" class="px-2 pb-2">
            <JsonBlock :value="entry.payload" :label="entry.direction === 'out' ? t('mcp.inspector.sent') : t('mcp.inspector.received')" />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
