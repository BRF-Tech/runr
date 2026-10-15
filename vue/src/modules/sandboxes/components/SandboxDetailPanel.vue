<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Clock, Pause, Play, Square, TerminalSquare, Zap } from 'lucide-vue-next'
import SandboxStatusBadge from './SandboxStatusBadge.vue'
import SandboxFileBrowser from './SandboxFileBrowser.vue'
import SandboxProcesses from './SandboxProcesses.vue'
import SandboxRunCode from './SandboxRunCode.vue'
import SandboxLogs from './SandboxLogs.vue'
import { sandboxesApi } from '../api/sandboxes'
import { useSandboxActions } from '../composables/useSandboxActions'
import { formatDateTime, formatDuration, isAlive, secondsUntil } from '../utils'

const props = defineProps({
  sandbox:    { type: Object, required: true },
  initialTab: { type: String, default: 'overview' },
})
const emit = defineEmits(['close', 'changed', 'killed', 'open-terminal'])

const { t } = useI18n()
const actions = useSandboxActions()

const TABS = ['overview', 'files', 'processes', 'run', 'logs', 'terminal']
const tab      = ref(TABS.includes(props.initialTab) ? props.initialTab : 'overview')
const current  = ref({ ...props.sandbox })
const busy     = ref(false)
const extendBy = ref(900)
const now      = ref(Date.now())

watch(() => props.sandbox, (s) => { if (s) current.value = { ...current.value, ...s } })

async function refresh() {
  try {
    const { data } = await sandboxesApi.get(current.value.id)
    if (data?.data) {
      current.value = data.data
      emit('changed', data.data)
    }
  } catch { /* the list keeps polling */ }
}

let clock = null
onMounted(() => {
  refresh()
  clock = setInterval(() => { now.value = Date.now() }, 1000)
})
onUnmounted(() => clearInterval(clock))

const running = computed(() => current.value.status === 'running')
const alive   = computed(() => isAlive(current.value.status))

async function act(action, ...args) {
  busy.value = true
  try {
    const updated = await actions[action](current.value, ...args)
    if (updated) {
      current.value = { ...current.value, ...updated }
      emit(action === 'kill' ? 'killed' : 'changed', current.value)
    }
  } finally {
    busy.value = false
  }
}

const remaining = computed(() => {
  const left = secondsUntil(current.value.expires_at, now.value)
  if (left === null) return '—'
  return left <= 0 ? t('sandboxes.expired') : t('sandboxes.inTime', { time: formatDuration(left) })
})

const networkLabel = computed(() => {
  const n = current.value.network ?? {}
  if (n.mode !== 'allowlist') return t('sandboxes.detail.networkNone')
  return (n.egress_allow ?? []).join(', ') || t('sandboxes.detail.networkNone')
})

const fields = computed(() => {
  const s = current.value
  const r = s.resources ?? {}
  const yesNo = (v) => v ? t('sandboxes.detail.yes') : t('sandboxes.detail.no')
  return [
    ['id',            s.id],
    ['region',        s.region?.name],
    ['runtime',       s.runtime ? `${s.runtime}${s.runtime_version ? ' ' + s.runtime_version : ''}` : null],
    ['image',         s.image],
    ['sandboxClass',  s.sandbox_class],
    ['isolation',     s.isolation ? t(`sandboxes.isolation.${s.isolation}`, s.isolation) : null],
    ['cpu',           r.cpu],
    ['memory',        r.memory_mb ? t('sandboxes.units.mib', { n: r.memory_mb }) : null],
    ['disk',          r.disk_mb ? t('sandboxes.units.mib', { n: r.disk_mb }) : null],
    ['pids',          r.pids_limit],
    ['network',       networkLabel.value],
    ['readOnlyRoot',  yesNo(s.read_only_root)],
    ['env',           (s.env_keys ?? []).join(', ')],
    ['metadata',      Object.entries(s.metadata ?? {}).map(([k, v]) => `${k}=${v}`).join(', ')],
    ['ttl',           s.ttl_seconds ? formatDuration(s.ttl_seconds) : null],
    ['idleTimeout',   s.idle_timeout_seconds ? formatDuration(s.idle_timeout_seconds) : t('sandboxes.detail.none')],
    ['expiresAt',     alive.value ? `${formatDateTime(s.expires_at)} (${remaining.value})` : formatDateTime(s.expires_at)],
    ['idleExpiresAt', s.idle_expires_at ? formatDateTime(s.idle_expires_at) : null],
    ['lastActivity',  formatDateTime(s.last_activity_at)],
    ['createdAt',     formatDateTime(s.created_at)],
    ['startedAt',     formatDateTime(s.started_at)],
    ['start',         s.start_ms !== null && s.start_ms !== undefined
      ? (s.from_pool ? t('sandboxes.fromPoolHint', { ms: s.start_ms }) : t('sandboxes.startedIn', { ms: s.start_ms }))
      : null],
    ['endedAt',       s.ended_at ? formatDateTime(s.ended_at) : null],
    ['endReason',     s.end_reason ? t(`sandboxes.endReason.${s.end_reason}`, s.end_reason) : null],
    ['error',         s.error],
    ['createdBy',     s.created_by],
  ].filter(([, v]) => v !== null && v !== undefined && v !== '')
})

const extendOptions = computed(() => [
  { value: 900,   label: t('sandboxes.detail.minutes15') },
  { value: 3600,  label: t('sandboxes.detail.hour1') },
  { value: 14400, label: t('sandboxes.detail.hours4') },
])
</script>

<template>
  <div class="fixed inset-0 z-50 flex justify-end">
    <div class="absolute inset-0 bg-black/60 backdrop-blur-xs" @click="emit('close')" />
    <aside class="relative w-full max-w-4xl h-full bg-gray-950 border-l border-gray-800 shadow-2xl flex flex-col" data-sandbox-detail>
      <!-- Header -->
      <div class="flex items-center justify-between gap-3 px-5 py-3 border-b border-gray-800 bg-gray-900/80 shrink-0">
        <div class="flex items-center gap-3 min-w-0">
          <SandboxStatusBadge :status="current.status" />
          <span class="font-mono text-sm text-gray-100 truncate" :title="current.id">{{ current.id }}</span>
          <span v-if="current.from_pool" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded-sm border border-emerald-800/50 bg-emerald-950/30 text-[10px] text-emerald-400">
            <Zap class="w-2.5 h-2.5" />{{ t('sandboxes.fromPool') }}
          </span>
        </div>
        <button class="text-gray-400 hover:text-gray-100" :title="t('sandboxes.actions.close')" @click="emit('close')">
          <X class="w-4 h-4" />
        </button>
      </div>

      <!-- Tabs -->
      <div class="flex gap-1 px-4 border-b border-gray-800 shrink-0 overflow-x-auto">
        <button v-for="key in TABS" :key="key" class="tab-line" :class="tab === key && 'is-active'" :data-tab="key" @click="tab = key">
          {{ t(`sandboxes.detail.tabs.${key}`) }}
        </button>
      </div>

      <div class="flex-1 overflow-y-auto px-5 py-4">
        <!-- Overview -->
        <div v-if="tab === 'overview'" data-panel="overview">
          <div v-if="alive" class="flex flex-wrap items-end gap-2 mb-5">
            <div>
              <label class="label">{{ t('sandboxes.detail.extendBy') }}</label>
              <select v-model.number="extendBy" class="input w-40" name="extend_by">
                <option v-for="o in extendOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
            </div>
            <button class="btn-secondary flex items-center gap-1.5" :disabled="busy" data-action="extend" @click="act('extend', extendBy)">
              <Clock class="w-3.5 h-3.5" />{{ t('sandboxes.actions.extend') }}
            </button>
            <button v-if="running" class="btn-secondary flex items-center gap-1.5" :disabled="busy" data-action="pause" @click="act('pause')">
              <Pause class="w-3.5 h-3.5" />{{ t('sandboxes.actions.pause') }}
            </button>
            <button v-else-if="current.status === 'paused'" class="btn-secondary flex items-center gap-1.5" :disabled="busy" data-action="resume" @click="act('resume')">
              <Play class="w-3.5 h-3.5" />{{ t('sandboxes.actions.resume') }}
            </button>
            <button class="btn-danger flex items-center gap-1.5 ml-auto" :disabled="busy" data-action="kill" @click="act('kill')">
              <Square class="w-3.5 h-3.5" />{{ t('sandboxes.actions.kill') }}
            </button>
          </div>

          <div class="grid grid-cols-[180px_1fr] gap-x-4 gap-y-1.5 text-sm" data-fields>
            <template v-for="[key, value] in fields" :key="key">
              <span class="text-xs text-gray-500 pt-0.5">{{ t(`sandboxes.detail.fields.${key}`) }}</span>
              <span class="font-mono text-xs text-gray-200 break-all" :data-field="key">{{ value }}</span>
            </template>
          </div>
        </div>

        <SandboxFileBrowser v-else-if="tab === 'files'" :sandbox-id="current.id" :disabled="!running" />
        <SandboxProcesses v-else-if="tab === 'processes'" :sandbox-id="current.id" :disabled="!running" />
        <SandboxRunCode v-else-if="tab === 'run'" :sandbox="current" :disabled="!running" />
        <SandboxLogs v-else-if="tab === 'logs'" :sandbox-id="current.id" />

        <!-- Terminal -->
        <div v-else-if="tab === 'terminal'" data-panel="terminal" class="max-w-xl">
          <p class="text-sm text-gray-400 mb-4">{{ t('sandboxes.detail.terminalText') }}</p>
          <button class="btn-primary flex items-center gap-1.5" :disabled="!running" data-action="open-terminal" @click="emit('open-terminal', current)">
            <TerminalSquare class="w-4 h-4" />{{ t('sandboxes.detail.openTerminal') }}
          </button>
          <p v-if="!running" class="text-xs text-amber-400 mt-2">{{ t('sandboxes.detail.notRunning') }}</p>
        </div>
      </div>
    </aside>
  </div>
</template>
