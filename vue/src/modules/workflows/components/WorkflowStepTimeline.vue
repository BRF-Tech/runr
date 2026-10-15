<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  Play, Moon, Radio, GitBranch, Send, ChevronDown, ChevronRight, RotateCcw, ExternalLink,
} from 'lucide-vue-next'
import WorkflowStatusBadge from './WorkflowStatusBadge.vue'
import JsonBlock from './JsonBlock.vue'
import { formatDuration, formatTime, stepPending } from '../utils/format'

defineProps({
  steps:     { type: Array, default: () => [] },
  canReplay: { type: Boolean, default: false },
})

const emit = defineEmits(['replay-from', 'open-run'])
const { t, locale } = useI18n()

const ICONS = { run: Play, sleep: Moon, wait_for_event: Radio, invoke: GitBranch, send_event: Send }
const expanded = ref({})

function toggle(step) {
  expanded.value = { ...expanded.value, [step.id]: !expanded.value[step.id] }
}

function hasDetails(step) {
  return step.input != null || step.output != null || step.error != null || (step.attempts?.length ?? 0) > 0
}

function pendingText(step) {
  const p = stepPending(step)
  const at = formatTime(p.at, locale.value)
  if (p.kind === 'wake') return t('workflows.detail.wakesAt', { at })
  if (p.kind === 'wait') {
    const what = step.type === 'invoke' ? t('workflows.detail.childRun') : (step.wait_event ?? '')
    return `${t('workflows.detail.waitingFor', { event: what })} ${t('workflows.detail.waitTimeout', { at })}`
  }
  if (p.kind === 'retry') return `${t('workflows.detail.nextAttempt')}: ${at}`
  return ''
}
</script>

<template>
  <div>
    <p v-if="!steps.length" class="text-xs text-gray-400 py-4" data-test="no-steps">{{ t('workflows.detail.noSteps') }}</p>

    <ol v-else class="relative border-l border-gray-800 ml-2 space-y-3" data-test="step-timeline">
      <li v-for="step in steps" :key="step.id" class="pl-5 relative" :data-step="step.step_key">
        <span class="absolute left-[-9px] top-1.5 w-4 h-4 rounded-full bg-gray-900 border border-gray-700 flex items-center justify-center">
          <component :is="ICONS[step.type] || Play" class="w-2.5 h-2.5 text-gray-300" />
        </span>

        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <button
            type="button"
            class="flex items-center gap-1 text-sm text-gray-100 hover:text-sky-300 transition-colors font-mono"
            :disabled="!hasDetails(step)"
            @click="toggle(step)"
          >
            <template v-if="hasDetails(step)">
              <ChevronDown v-if="expanded[step.id]" class="w-3.5 h-3.5" />
              <ChevronRight v-else class="w-3.5 h-3.5" />
            </template>
            {{ step.step_key }}
          </button>
          <span class="text-[11px] text-gray-400">{{ t(`workflows.stepType.${step.type}`, step.type) }}</span>
          <WorkflowStatusBadge :status="step.status" size="xs" />
          <span v-if="step.max_attempts > 1 || step.attempt > 1" class="text-[11px] text-gray-400">
            {{ t('workflows.detail.attemptsOf', { n: step.attempt, max: step.max_attempts }) }}
          </span>
          <span v-if="step.duration_ms != null" class="text-[11px] text-gray-400 font-mono">{{ formatDuration(step.duration_ms) }}</span>

          <div class="ml-auto flex items-center gap-1.5">
            <button
              v-if="step.child_run_id"
              type="button"
              class="text-[11px] inline-flex items-center gap-1 text-sky-400 hover:text-sky-300"
              @click="emit('open-run', step.child_run_id)"
            >
              <ExternalLink class="w-3 h-3" />{{ t('workflows.detail.childRun') }}
            </button>
            <button
              v-if="canReplay"
              type="button"
              class="text-[11px] inline-flex items-center gap-1 text-gray-400 hover:text-gray-100"
              :data-test="`replay-from-${step.step_key}`"
              @click="emit('replay-from', step.step_key)"
            >
              <RotateCcw class="w-3 h-3" />{{ t('workflows.detail.replayFrom') }}
            </button>
          </div>
        </div>

        <p v-if="pendingText(step)" class="text-xs text-gray-400 mt-0.5">{{ pendingText(step) }}</p>
        <p v-if="step.error && !expanded[step.id]" class="text-xs text-red-400 mt-0.5 truncate">{{ step.error.name }}: {{ step.error.message }}</p>

        <div v-if="expanded[step.id]" class="mt-2 grid gap-2 md:grid-cols-2" data-test="step-details">
          <JsonBlock v-if="step.input != null" :value="step.input" :label="t('workflows.detail.input')" />
          <JsonBlock v-if="step.output != null" :value="step.output" :label="t('workflows.detail.output')" />
          <JsonBlock v-if="step.error" :value="step.error" :label="t('workflows.detail.error')" tone="error" />
          <div v-if="step.attempts?.length" class="md:col-span-2">
            <span class="text-[11px] uppercase tracking-wider font-medium text-gray-400">{{ t('workflows.detail.attempts') }}</span>
            <table class="w-full text-xs mt-1">
              <tbody>
                <tr v-for="a in step.attempts" :key="a.attempt" class="border-t border-gray-800">
                  <td class="py-1 pr-3 text-gray-400 whitespace-nowrap">{{ t('workflows.detail.attempt', { n: a.attempt }) }}</td>
                  <td class="py-1 pr-3"><WorkflowStatusBadge :status="a.status" size="xs" /></td>
                  <td class="py-1 pr-3 font-mono text-gray-400 whitespace-nowrap">{{ formatDuration(a.duration_ms) }}</td>
                  <td class="py-1 pr-3 text-gray-400 whitespace-nowrap">{{ formatTime(a.at, locale) }}</td>
                  <td class="py-1 text-red-400 break-all">{{ a.error ? `${a.error.name}: ${a.error.message}` : '' }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </li>
    </ol>
  </div>
</template>
