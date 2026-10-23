<script setup>
// The project's rightsizing recommendation (GET /projects/{id}/rightsizing, docs/monitoring.md#rightsizing):
// "requests 512 MB but used at most 140 MB in the last 14 days → 256 MB is enough", what it frees and
// saves per month (with billing), the evidence, and Apply / Snooze / Dismiss for projects.update.
// Applying saves a new version — the next deploy runs it. Shown only while there is something to
// decide (an open recommendation) or to deploy (an applied size the running deployment lacks).
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Gauge, ChevronDown, ChevronUp } from 'lucide-vue-next'
import { rightsizingApi } from '@/api/rightsizing'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { changeLines, freedText, reasonsText, savingsText, sizeText } from '@/utils/rightsizing'

const props = defineProps({
  project: { type: Object, required: true },
})
const emit = defineEmits(['applied'])
const { t, locale } = useI18n()
const toast = useToast()
const perms = usePermissionsStore()
const { confirm } = useConfirm()

const state = ref(null)
const busy = ref(false)
const showEvidence = ref(false)

const recommendation = computed(() => state.value?.data ?? null)
const open = computed(() => state.value?.status === 'open' && !!recommendation.value)
const pendingDeploy = computed(() => state.value?.status === 'applied' && !!state.value?.pending_deploy)
const canDecide = computed(() => perms.can('projects.update', props.project.id))

const title = computed(() => ({
  down: t('rightsizing.titleDown'), up: t('rightsizing.titleUp'),
}[recommendation.value?.direction] ?? t('rightsizing.titleMixed')))
const lines = computed(() => changeLines(t, recommendation.value))
const savings = computed(() => savingsText(t, recommendation.value, locale.value))
const freed = computed(() => freedText(t, recommendation.value))
const evidence = computed(() => recommendation.value?.evidence ?? {})

async function load() {
  try {
    const { data } = await rightsizingApi.forProject(props.project.id)
    // an older API without rightsizing answers 404; the mocked API an empty list
    state.value = data && typeof data.status === 'string' ? data : null
  } catch {
    state.value = null
  }
}

async function apply() {
  const r = recommendation.value
  const ok = await confirm({
    title: t('rightsizing.title'),
    message: t('rightsizing.confirmApply', { project: props.project.name, current: sizeText(r.current), recommended: sizeText(r.recommended) }),
    confirmLabel: t('rightsizing.apply'),
  })
  if (!ok) return
  busy.value = true
  try {
    const { data } = await rightsizingApi.apply(props.project.id)
    state.value = { status: 'applied', data: data.data, pending_deploy: true }
    toast.success(t('rightsizing.applied', { version: data.version?.label ?? '' }), 8000)
    emit('applied', data)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = false
  }
}

async function dismiss(snoozeDays = null) {
  busy.value = true
  try {
    const { data } = await rightsizingApi.dismiss(props.project.id, snoozeDays)
    state.value = { status: data.data?.status ?? 'dismissed', data: data.data }
    toast.success(snoozeDays ? t('rightsizing.snoozed') : t('rightsizing.dismissed'))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = false
  }
}

onMounted(load)
watch(() => props.project.id, load)

defineExpose({ load, apply, dismiss })
</script>

<template>
  <div v-if="open" class="mx-6 mt-3 rounded-xl border border-sky-900/60 bg-sky-950/20 px-4 py-3 space-y-2" data-test="rightsizing-card">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex items-start gap-3 min-w-0">
        <Gauge class="w-5 h-5 text-sky-400 shrink-0 mt-0.5" />
        <div class="space-y-1 min-w-0">
          <p class="text-sm font-medium text-sky-200" data-test="rightsizing-title">{{ title }}</p>
          <p v-for="(line, i) in lines" :key="i" class="text-xs text-gray-300" data-test="rightsizing-line">{{ line }}</p>
          <p class="text-[11px] text-gray-400" data-test="rightsizing-impact">
            <span v-if="freed">{{ freed }}</span>
            <span v-if="freed && savings"> · </span>
            <span v-if="savings" data-test="rightsizing-savings">{{ savings }}</span>
          </p>
          <p v-if="recommendation.blocked" class="text-[11px] text-amber-400" data-test="rightsizing-blocked">
            {{ t('rightsizing.blocked', { message: recommendation.blocked.message }) }}
          </p>
        </div>
      </div>
      <div v-if="canDecide" class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-primary text-xs" :disabled="busy || !!recommendation.blocked" data-test="rightsizing-apply" @click="apply">
          {{ t('rightsizing.apply') }}
        </button>
        <button type="button" class="btn-secondary text-xs" :disabled="busy" data-test="rightsizing-snooze" @click="dismiss(30)">
          {{ t('rightsizing.snooze') }}
        </button>
        <button type="button" class="text-xs text-gray-400 hover:text-gray-200 px-2" :disabled="busy" data-test="rightsizing-dismiss" @click="dismiss()">
          {{ t('rightsizing.dismiss') }}
        </button>
      </div>
    </div>

    <button type="button" class="flex items-center gap-1 text-[11px] text-gray-400 hover:text-gray-200" data-test="rightsizing-evidence-toggle" @click="showEvidence = !showEvidence">
      <component :is="showEvidence ? ChevronUp : ChevronDown" class="w-3 h-3" />
      {{ showEvidence ? t('rightsizing.hideEvidence') : t('rightsizing.showEvidence') }}
    </button>
    <dl v-if="showEvidence" class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-[11px]" data-test="rightsizing-evidence">
      <div class="flex justify-between gap-3 sm:col-span-2">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.window') }}</dt>
        <dd class="text-gray-200">{{ t('rightsizing.evidence.windowValue', { from: evidence.from, to: evidence.to, days: evidence.days, sessions: evidence.sessions }) }}</dd>
      </div>
      <div class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.memoryMax') }}</dt>
        <dd class="font-mono text-gray-200">{{ evidence.memory_max_mb ?? '—' }} MB</dd>
      </div>
      <div class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.memoryP95') }}</dt>
        <dd class="font-mono text-gray-200">{{ evidence.memory_p95_mb ?? '—' }} MB</dd>
      </div>
      <div class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.cpuMax') }}</dt>
        <dd class="font-mono text-gray-200">{{ evidence.cpu_max_m ?? '—' }}m</dd>
      </div>
      <div class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.cpuP95') }}</dt>
        <dd class="font-mono text-gray-200">{{ evidence.cpu_p95_m ?? '—' }}m</dd>
      </div>
      <div v-if="evidence.oom_kills" class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.oomKills') }}</dt>
        <dd class="font-mono text-amber-400">{{ evidence.oom_kills }}</dd>
      </div>
      <div v-if="evidence.throttled_ratio" class="flex justify-between gap-3">
        <dt class="text-gray-400">{{ t('rightsizing.evidence.throttled') }}</dt>
        <dd class="font-mono text-amber-400">{{ t('rightsizing.evidence.throttledValue', { percent: Math.round(evidence.throttled_ratio * 1000) / 10 }) }}</dd>
      </div>
      <p class="sm:col-span-2 text-gray-500">
        {{ t('rightsizing.evidence.rule', { memoryMargin: evidence.memory_margin ?? 1.3, cpuMargin: evidence.cpu_margin ?? 1.3 }) }}
        <span v-if="recommendation.reasons?.length"> · {{ reasonsText(t, recommendation.reasons) }}</span>
      </p>
    </dl>
  </div>

  <div v-else-if="pendingDeploy" class="mx-6 mt-3 rounded-xl border border-gray-800 bg-gray-900 px-4 py-2 flex items-center gap-2 text-xs text-gray-300" data-test="rightsizing-pending">
    <Gauge class="w-4 h-4 text-sky-400 shrink-0" />
    {{ t('rightsizing.pendingDeploy') }}
    <span class="font-mono text-gray-400">{{ sizeText(recommendation?.recommended) }}</span>
  </div>
</template>
