<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { brandColor } from '@/utils/brandColor'

const { t } = useI18n()

const props = defineProps({
  project:      { type: Object, default: null },
  deployment:   { type: Object, default: null },
  // Other shell targets (e.g. a sandbox): async (cols, rows) => one-time session id for /ws/shell
  startSession: { type: Function, default: null },
  title:        { type: String, default: '' },
  // Called every 60 s while connected (e.g. to reset a sandbox's idle timer)
  keepalive:    { type: Function, default: null },
})

const KEEPALIVE_INTERVAL_MS = 60000
let keepaliveTimer = null

function stopKeepalive() {
  if (keepaliveTimer) clearInterval(keepaliveTimer)
  keepaliveTimer = null
}

function startKeepalive() {
  if (!props.keepalive || keepaliveTimer) return
  keepaliveTimer = setInterval(() => {
    Promise.resolve().then(() => props.keepalive()).catch(() => { /* best effort */ })
  }, KEEPALIVE_INTERVAL_MS)
}

const emit = defineEmits(['close'])

const terminalEl = ref(null)
const status     = ref('connecting') // connecting | searching | waking_up | connected | error | closed
const podName    = ref('')
const errorMsg   = ref('')

let terminal = null
let fitAddon = null
let ws       = null

const encoder = new TextEncoder()
const decoder = new TextDecoder()

function toBase64(str) {
  return btoa(String.fromCharCode(...encoder.encode(str)))
}

function fromBase64(b64) {
  const bin = atob(b64)
  const bytes = Uint8Array.from(bin, c => c.charCodeAt(0))
  return decoder.decode(bytes)
}

// The modal can close while the xterm chunks or the session token load: no socket may be opened
// after that (an orphan shell on the pod, and for a sandbox a keepalive that never lets it idle)
let disposed = false

// A socket still connecting is closed too (closing it aborts the handshake)
function closeSocket() {
  if (ws && ws.readyState <= WebSocket.OPEN) ws.close()
}

// ── Close: immediate UI dismiss, WS cleanup in background ────────────────────
function closeSession() {
  emit('close')
  stopKeepalive()
  closeSocket()
}

// ── Build WebSocket URL from current window location ──────────────────────────
function wsUrl(token) {
  const proto = window.location.protocol === 'https:' ? 'wss:' : 'ws:'
  return `${proto}//${window.location.host}/ws/shell?token=${token}`
}

// ── Init xterm + session ──────────────────────────────────────────────────────
async function init() {
  const { Terminal } = await import('@xterm/xterm')
  const { FitAddon } = await import('@xterm/addon-fit')
  if (disposed) return

  if (!document.querySelector('#xterm-styles')) {
    const link = document.createElement('link')
    link.id   = 'xterm-styles'
    link.rel  = 'stylesheet'
    link.href = new URL('@xterm/xterm/css/xterm.css', import.meta.url).href
    document.head.appendChild(link)
  }

  terminal = new Terminal({
    cursorBlink: true,
    fontSize:    13,
    fontFamily:  '"JetBrains Mono", "Cascadia Code", "Fira Code", ui-monospace, monospace',
    scrollback:  5000,
    theme: {
      background:          '#030712',
      foreground:          '#e2e8f0',
      cursor:              brandColor(400),
      cursorAccent:        '#030712',
      selectionBackground: brandColor(700, 0.35),
      black:               '#1e293b',
      red:                 '#f87171',
      green:               '#4ade80',
      yellow:              '#facc15',
      blue:                '#60a5fa',
      magenta:             '#c084fc',
      cyan:                '#22d3ee',
      white:               '#e2e8f0',
      brightBlack:         '#475569',
      brightRed:           '#fca5a5',
      brightGreen:         '#86efac',
      brightYellow:        '#fde047',
      brightBlue:          '#93c5fd',
      brightMagenta:       '#d8b4fe',
      brightCyan:          '#67e8f9',
      brightWhite:         '#f8fafc',
    },
  })

  fitAddon = new FitAddon()
  terminal.loadAddon(fitAddon)
  terminal.open(terminalEl.value)
  fitAddon.fit()
  window.addEventListener('resize', handleResize)

  // Get initial dimensions from xterm for PTY
  const cols = terminal.cols || 80
  const rows = terminal.rows || 24

  // Get one-time token from Laravel (instant — no pod finding or wake-up)
  let sessionId
  try {
    if (props.startSession) {
      sessionId = await props.startSession(cols, rows)
    } else {
      const res = await projectsApi.execStart(props.project.id, props.deployment.id, cols, rows)
      sessionId = res.data.session_id
    }
  } catch (e) {
    status.value   = 'error'
    errorMsg.value = e.response?.data?.message || t('project.shell.startFailed')
    return
  }
  if (disposed) return

  // Open WebSocket to Reverb shell handler
  try {
    ws = new WebSocket(wsUrl(sessionId))
  } catch {
    status.value   = 'error'
    errorMsg.value = t('project.shell.wsFailed')
    return
  }

  ws.onopen = () => {
    // nothing — wait for { connected: true } from server
  }

  ws.onmessage = (event) => {
    try {
      const msg = JSON.parse(event.data)

      if (msg.status) {
        // Wake phase status frames: searching, waking_up, waiting
        if (msg.status === 'searching') status.value = 'searching'
        else if (msg.status === 'waking_up' || msg.status === 'waiting') status.value = 'waking_up'
      } else if (msg.connected) {
        podName.value = msg.pod || ''
        status.value = 'starting' // keep overlay until first output
        if (!disposed) startKeepalive()
      } else if (msg.output) {
        terminal?.write(fromBase64(msg.output))
        if (status.value !== 'connected') {
          status.value = 'connected'
          setTimeout(() => fitAddon?.fit(), 50)
        }
      } else if (msg.error) {
        status.value   = 'error'
        errorMsg.value = msg.error
        terminal?.write(`\r\n\x1b[31mError: ${msg.error}\x1b[0m\r\n`)
      }
    } catch { /* ignore malformed */ }
  }

  ws.onclose = () => {
    stopKeepalive()
    if (status.value === 'connected') {
      terminal?.write(`\r\n\x1b[90m${t('project.shell.connectionClosed')}\x1b[0m\r\n`)
    }
    // Don't overwrite error status — user needs to see the error message
    if (status.value !== 'error') {
      status.value = 'closed'
    }
  }

  ws.onerror = () => {
    if (['connecting', 'searching', 'waking_up', 'starting'].includes(status.value)) {
      status.value   = 'error'
      errorMsg.value = t('project.shell.connectionFailed')
    }
  }

  // Keystrokes → WebSocket (base64 encoded, no buffering needed — WS is fast)
  terminal.onData((data) => {
    if (ws?.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ input: toBase64(data) }))
    }
  })

  // Terminal resize → notify Reverb to resize PTY
  terminal.onResize(({ cols, rows }) => {
    if (ws?.readyState === WebSocket.OPEN) {
      ws.send(JSON.stringify({ resize: { cols, rows } }))
    }
  })
}

function handleResize() { fitAddon?.fit() }

onMounted(() => { init() })

onUnmounted(() => {
  disposed = true
  stopKeepalive()
  window.removeEventListener('resize', handleResize)
  closeSocket()
  terminal?.dispose()
})
</script>

<template>
  <div
    class="fixed inset-0 z-60 flex items-center justify-center bg-black/60 backdrop-blur-xs"
    @click.self="closeSession"
  >
    <div class="w-[960px] h-[600px] bg-gray-950 border border-gray-800 rounded-xl shadow-2xl flex flex-col overflow-hidden">

      <!-- Header -->
      <div class="flex items-center justify-between px-4 py-2.5 border-b border-gray-800 shrink-0 bg-gray-900/80">
        <div class="flex items-center gap-2.5">
          <svg class="w-4 h-4 text-brand-400 shrink-0" viewBox="0 0 20 20" fill="none">
            <rect x="2" y="3" width="16" height="14" rx="2" stroke="currentColor" stroke-width="1.5"/>
            <path d="M6 8l3 2-3 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M11 12h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          <span class="text-sm font-semibold text-gray-100 tracking-tight">{{ title || 'Shell' }}</span>
          <span v-if="podName" class="text-[11px] font-mono text-gray-400 truncate max-w-[400px]">{{ podName }}</span>
        </div>

        <div class="flex items-center gap-3 shrink-0">
          <span class="inline-flex items-center gap-1.5 text-[10px] font-mono" :class="{
            'text-amber-400':   ['connecting', 'searching', 'waking_up', 'starting'].includes(status),
            'text-emerald-400': status === 'connected',
            'text-red-400':     status === 'error',
            'text-gray-400':    status === 'closed',
          }">
            <span class="w-1.5 h-1.5 rounded-full" :class="{
              'bg-amber-400 animate-pulse': ['connecting', 'searching', 'waking_up', 'starting'].includes(status),
              'bg-emerald-400':             status === 'connected',
              'bg-red-400':                 status === 'error',
              'bg-gray-600':                status === 'closed',
            }" />
            {{ {waking_up: t('project.shell.wakingUp').toUpperCase(), searching: t('project.shell.searching').toUpperCase(), starting: t('project.shell.starting').toUpperCase()}[status] || status.toUpperCase() }}
          </span>
          <button
            class="text-gray-400 hover:text-gray-100 transition-colors text-sm leading-none"
            :title="t('project.shell.closeTitle')"
            @click="closeSession"
          >✕</button>
        </div>
      </div>

      <!-- Body: terminal always in DOM so xterm measures correctly -->
      <div class="flex-1 min-h-0 relative">

        <!-- xterm container — always rendered -->
        <div ref="terminalEl" class="absolute inset-0 px-1 py-1" />

        <!-- Connecting / Searching / Waking up overlay -->
        <div v-if="['connecting', 'searching', 'waking_up', 'starting'].includes(status)"
          class="absolute inset-0 flex items-center justify-center bg-gray-950 z-10">
          <div class="text-center space-y-2">
            <div class="w-6 h-6 border-2 border-brand-600 border-t-brand-400 rounded-full animate-spin mx-auto" />
            <p class="text-gray-400 text-xs font-mono">
              {{ {waking_up: t('project.shell.wakingUp'), searching: t('project.shell.searching'), starting: t('project.shell.starting')}[status] || t('project.shell.connecting') }}
            </p>
          </div>
        </div>

        <!-- Error overlay -->
        <div v-if="status === 'error'"
          class="absolute inset-0 flex items-center justify-center bg-gray-950 z-10">
          <div class="text-center space-y-2">
            <svg class="w-8 h-8 text-red-500 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/>
            </svg>
            <p class="text-red-400 text-sm font-mono">{{ errorMsg || t('project.shell.connectionFailed') }}</p>
            <p class="text-gray-400 text-xs font-mono">{{ t('project.shell.checkPods') }}</p>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>
