<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, Ban, RefreshCw, RotateCcw, Repeat, Copy } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { workflowsApi } from '../api/workflows'
import WorkflowStatusBadge from './WorkflowStatusBadge.vue'
import WorkflowStepTimeline from './WorkflowStepTimeline.vue'
import JsonBlock from './JsonBlock.vue'
import { formatDuration, formatTime, isActive } from '../utils/format'

const props = defineProps({
  projectId:    { type: String, required: true },
  runId:        { type: String, required: true },
  pollInterval: { type: Number, default: 5000 },
  canRun:       { type: Boolean, default: true },
})

const emit = defineEmits(['back', 'open-run', 'changed'])
const { t, locale } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const run     = ref(null)
const loading = ref(true)
const error   = ref(null)
const busy    = ref(null) // 'cancel' | 'retry' | 'replay'
let timer = null

const active     = computed(() => run.value && isActive(run.value.status))
const canRetry   = computed(() => props.canRun && run.value && ['failed', 'cancelled', 'timed_out'].includes(run.value.status))
const canReplay  = computed(() => props.canRun && run.value && !isActive(run.value.status))

async function load({ silent = false } = {}) {
  if (!silent) loading.value = true
  try {
    const { data } = await workflowsApi.run(props.projectId, props.runId)
    run.value   = data.data
    error.value = null
  } catch (e) {
    error.value = e.response?.data?.message ?? t('workflows.loadFailed')
  } finally {
    loading.value = false
    schedule()
  }
}

function schedule() {
  clearTimeout(timer)
  if (active.value && props.pollInterval > 0) {
    timer = setTimeout(() => {
      if (typeof document === 'undefined' || !document.hidden) load({ silent: true })
      else schedule()
    }, props.pollInterval)
  }
}

onMounted(() => load())
onUnmounted(() => clearTimeout(timer))
watch(() => props.runId, () => load())

async function cancel() {
  const ok = await confirm({
    title:        t('workflows.detail.cancelTitle'),
    message:      t('workflows.detail.cancelConfirm'),
    confirmLabel: t('workflows.detail.cancelTitle'),
    destructive:  true,
  })
  if (!ok) return
  await act('cancel', () => workflowsApi.cancelRun(props.projectId, props.runId), t('workflows.detail.cancelled'))
}

async function retry() {
  await act('retry', () => workflowsApi.retryRun(props.projectId, props.runId), t('workflows.detail.retried'))
}

async function replay(fromStep = null) {
  busy.value = 'replay'
  try {
    const { data } = await workflowsApi.replayRun(props.projectId, props.runId, fromStep)
    toast.success(t('workflows.detail.replayed'))
    emit('changed')
    emit('open-run', data.data.id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}

async function act(kind, call, message) {
  busy.value = kind
  try {
    const { data } = await call()
    run.value = { ...run.value, ...data.data }
    toast.success(message)
    emit('changed')
    await load({ silent: true })
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}

async function copyId() {
  try { await navigator.clipboard?.writeText(props.runId) } catch { /* plain http */ }
}
</script>

<template>
  <div class="space-y-4" data-test="run-detail">
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="emit('back')">
        <ArrowLeft class="w-3.5 h-3.5" />{{ t('workflows.detail.back') }}
      </button>
      <template v-if="run">
        <span class="font-mono text-sm text-gray-100">{{ run.workflow?.name }}</span>
        <WorkflowStatusBadge :status="run.status" />
        <button type="button" class="text-gray-400 hover:text-gray-100 font-mono text-xs inline-flex items-center gap-1" :title="t('workflows.detail.copyId')" @click="copyId">
          {{ run.id }}<Copy class="w-3 h-3" />
        </button>
      </template>
      <div class="ml-auto flex items-center gap-1.5">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load({ silent: true })">
          <RefreshCw class="w-3.5 h-3.5" />{{ t('workflows.refresh') }}
        </button>
        <button v-if="active && canRun" type="button" class="btn-secondary text-xs px-3 py-1.5 text-red-400" :disabled="!!busy" data-test="cancel-run" @click="cancel">
          <Ban class="w-3.5 h-3.5" />{{ t('workflows.detail.cancel') }}
        </button>
        <button v-if="canRetry" type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="!!busy" data-test="retry-run" @click="retry">
          <RotateCcw class="w-3.5 h-3.5" />{{ t('workflows.detail.retry') }}
        </button>
        <button v-if="canReplay" type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="!!busy" data-test="replay-run" @click="replay()">
          <Repeat class="w-3.5 h-3.5" />{{ t('workflows.detail.replay') }}
        </button>
      </div>
    </div>

    <div v-if="loading && !run" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="error && !run" class="text-xs text-red-400">{{ error }}</p>

    <template v-else-if="run">
      <!-- Summary -->
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs bg-gray-900 border border-gray-800 rounded-md p-4">
        <div><p class="text-gray-400">{{ t('workflows.runs.source') }}</p><p class="text-gray-100 mt-0.5">{{ t(`workflows.source.${run.trigger_source}`, run.trigger_source) }}</p></div>
        <div><p class="text-gray-400">{{ t('workflows.runs.started') }}</p><p class="text-gray-100 mt-0.5">{{ formatTime(run.started_at || run.created_at, locale) }}</p></div>
        <div><p class="text-gray-400">{{ t('workflows.runs.duration') }}</p><p class="text-gray-100 mt-0.5 font-mono">{{ formatDuration(run.duration_ms) }}</p></div>
        <div><p class="text-gray-400">{{ t('workflows.runs.steps') }}</p><p class="text-gray-100 mt-0.5">{{ run.steps_count }}</p></div>
        <div v-if="run.event_name"><p class="text-gray-400">{{ t('workflows.detail.event') }}</p><p class="text-gray-100 mt-0.5 font-mono break-all">{{ run.event_name }}</p></div>
        <div v-if="run.attempt > 0"><p class="text-gray-400">{{ t('workflows.detail.attempts') }}</p><p class="text-amber-300 mt-0.5">{{ t('workflows.detail.runAttempt', { n: run.attempt }) }}</p></div>
        <div v-if="run.next_attempt_at && active"><p class="text-gray-400">{{ t('workflows.detail.nextAttempt') }}</p><p class="text-gray-100 mt-0.5">{{ formatTime(run.next_attempt_at, locale) }}</p></div>
        <div v-if="run.timeout_at && active"><p class="text-gray-400">{{ t('workflows.detail.timeoutAt') }}</p><p class="text-gray-100 mt-0.5">{{ formatTime(run.timeout_at, locale) }}</p></div>
        <div v-if="run.idempotency_key"><p class="text-gray-400">{{ t('workflows.detail.idempotencyKey') }}</p><p class="text-gray-100 mt-0.5 font-mono break-all">{{ run.idempotency_key }}</p></div>
        <div v-if="run.concurrency_key"><p class="text-gray-400">{{ t('workflows.detail.concurrencyKey') }}</p><p class="text-gray-100 mt-0.5 font-mono break-all">{{ run.concurrency_key }}</p></div>
        <div v-if="run.replay_of_run_id">
          <p class="text-gray-400">{{ t('workflows.detail.replayOf') }}</p>
          <p class="mt-0.5">
            <button type="button" class="font-mono text-sky-400 hover:text-sky-300" @click="emit('open-run', run.replay_of_run_id)">{{ run.replay_of_run_id }}</button>
            <span v-if="run.replay_from_step" class="text-gray-400"> {{ t('workflows.detail.replayFromStep', { step: run.replay_from_step }) }}</span>
          </p>
        </div>
        <div v-if="run.parent_run_id">
          <p class="text-gray-400">{{ t('workflows.detail.parent') }}</p>
          <p class="mt-0.5"><button type="button" class="font-mono text-sky-400 hover:text-sky-300" @click="emit('open-run', run.parent_run_id)">{{ run.parent_run_id }}</button></p>
        </div>
      </div>

      <div v-if="run.error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3" data-test="run-error">
        <p class="text-xs text-red-300 font-medium">{{ run.error.name }}</p>
        <p class="text-xs text-red-400 mt-0.5 break-all">{{ run.error.message }}</p>
      </div>

      <div class="grid gap-3 md:grid-cols-2">
        <JsonBlock :value="run.event" :label="t('workflows.detail.event')" />
        <JsonBlock v-if="run.status === 'completed'" :value="run.output" :label="t('workflows.detail.output')" />
      </div>

      <section>
        <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2">{{ t('workflows.detail.timeline') }}</p>
        <WorkflowStepTimeline
          :steps="run.steps || []"
          :can-replay="canReplay"
          @replay-from="replay"
          @open-run="(id) => emit('open-run', id)"
        />
      </section>

      <section v-if="run.children?.length">
        <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-2">{{ t('workflows.detail.children') }}</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="c in run.children" :key="c.id" type="button"
            class="inline-flex items-center gap-1.5 text-xs font-mono text-sky-400 hover:text-sky-300"
            @click="emit('open-run', c.id)"
          >{{ c.id }} <WorkflowStatusBadge :status="c.status" size="xs" /></button>
        </div>
      </section>
    </template>
  </div>
</template>
