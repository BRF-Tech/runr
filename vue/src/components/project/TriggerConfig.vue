<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { moduleRegistry } from '@/modules/index'
import { isValidCron } from '@/composables/projectValidation'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()

const props = defineProps({
  triggerType: { type: String, required: true },
  schedule: { type: String, default: '' },
  queueStream: { type: String, default: '' },
  region: { type: Object, default: null },
  showTooltips: { type: Boolean, default: true },
})

const streamSuffix = computed(() => moduleRegistry.queueStreamSuffixFn?.() || '')
const resolvedStream = computed(() => props.queueStream.trim() ? `${props.queueStream.trim()}${streamSuffix.value}` : '')

defineEmits(['update:triggerType', 'update:schedule', 'update:queueStream'])

const triggers = [
  { id: 'http',     labelKey: 'create.triggerTypes.http',     activeStyle: 'background:rgb(16 185 129/0.15);border-color:rgb(16 185 129/0.5);color:#6ee7b7' },
  { id: 'schedule', labelKey: 'create.triggerTypes.schedule', activeStyle: 'background:rgb(245 158 11/0.15);border-color:rgb(245 158 11/0.5);color:#fcd34d' },
  { id: 'queue',    labelKey: 'create.triggerTypes.queue',    activeStyle: 'background:rgb(14 165 233/0.15);border-color:rgb(14 165 233/0.5);color:#7dd3fc' },
  // Durable workflows (modules/workflows): served over HTTP to the workflow engine
  { id: 'workflow', labelKey: 'create.triggerTypes.workflow', activeStyle: 'background:rgb(139 92 246/0.15);border-color:rgb(139 92 246/0.5);color:#c4b5fd' },
]

function isDisabled(id) {
  if (id === 'http' || id === 'workflow') return props.region && !props.region.is_synced && !props.region.has_serving && !props.region.is_demo
  if (id === 'queue') return !props.region?.is_synced && !props.region?.has_redis
  return false
}

function disabledReason(id) {
  if (id === 'http' || id === 'workflow') return t('create.triggerDisabled.http')
  if (id === 'queue') return t('create.triggerDisabled.queue')
  return ''
}
</script>

<template>
  <section>
    <p class="section-title">{{ t('create.sections.trigger') }}</p>
    <div class="flex gap-2">
      <div v-for="tr in triggers" :key="tr.id" class="flex-1 relative group/trigger">
        <button
          type="button"
          class="w-full py-1.5 rounded-md border text-sm transition-colors"
          :class="triggerType === tr.id
            ? 'border'
            : isDisabled(tr.id)
              ? 'bg-gray-900 border-gray-800 text-gray-400 cursor-not-allowed opacity-50'
              : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'"
          :style="triggerType === tr.id ? tr.activeStyle : ''"
          :disabled="isDisabled(tr.id)"
          @click="!isDisabled(tr.id) && $emit('update:triggerType', tr.id)"
        >{{ t(tr.labelKey) }}</button>
        <div v-if="showTooltips && isDisabled(tr.id) && disabledReason(tr.id)"
          class="hidden group-hover/trigger:block absolute left-1/2 -translate-x-1/2 bottom-full mb-2 z-20">
          <div class="bg-gray-900 border border-gray-700 rounded-md px-3 py-1.5 text-[11px] text-gray-400 shadow-lg text-center max-w-[180px]">
            {{ disabledReason(tr.id) }}
          </div>
          <div class="absolute left-1/2 -translate-x-1/2 -bottom-1 w-2 h-2 bg-gray-900 border-r border-b border-gray-700 rotate-45" />
        </div>
      </div>
    </div>

    <div v-if="triggerType === 'schedule'" class="mt-3">
      <label class="label">{{ t('create.fields.cronExpr') }} <span class="normal-case text-red-500/70">*</span></label>
      <input :value="schedule" class="input font-mono" :placeholder="t('create.placeholders.schedule')" @input="$emit('update:schedule', $event.target.value)" />
      <p v-if="!schedule.trim()" class="text-xs text-red-400 mt-1">{{ t('create.fields.cronRequired') }}</p>
      <p v-else-if="!isValidCron(schedule)" class="text-xs text-red-400 mt-1">{{ t('create.validation.cronInvalid') }}</p>
    </div>
    <div v-if="triggerType === 'queue'" class="mt-3">
      <label class="label"><TermText path="create.fields.queueStream" /> <span class="normal-case text-red-500/70">*</span></label>
      <input :value="queueStream" class="input font-mono" :placeholder="t('create.placeholders.queueStream')" @input="$emit('update:queueStream', $event.target.value)" />
      <p v-if="!queueStream.trim()" class="text-xs text-red-400 mt-1">{{ t('create.fields.queueRequired') }}</p>
      <p v-else-if="resolvedStream && streamSuffix" class="text-xs text-sky-400/70 mt-1 font-mono">{{ t('create.fields.queueStreamResolved', { name: resolvedStream }) }}</p>
    </div>
    <div v-if="triggerType === 'workflow'" class="mt-3" data-test="workflow-trigger">
      <p class="text-xs text-gray-400 leading-relaxed">{{ t('workflows.trigger.hint') }}</p>
      <label class="label mt-3">{{ t('workflows.trigger.queueStream') }}</label>
      <input :value="queueStream" class="input font-mono" :placeholder="t('create.placeholders.queueStream')" @input="$emit('update:queueStream', $event.target.value)" />
      <p class="text-xs text-gray-400 mt-1">{{ t('workflows.trigger.queueStreamHint') }}</p>
      <p v-if="queueStream.trim() && region && !region.is_synced && !region.has_redis" class="text-xs text-yellow-400 mt-1">{{ t('create.triggerDisabled.queue') }}</p>
    </div>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
