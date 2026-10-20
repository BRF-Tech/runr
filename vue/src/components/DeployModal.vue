<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()

const props = defineProps({
  projectName: { type: String, required: true },
  versions:    { type: Array, default: () => [] },
  deploying:   { type: Boolean, default: false },
})

const emit = defineEmits(['deploy', 'cancel'])

const selectedVersionId = ref(props.versions[0]?.id ?? null)

function doDeploy() {
  emit('deploy', { versionId: selectedVersionId.value })
}

function fmt(dateTime) {
  if (!dateTime) return '—'
  return new Date(dateTime).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('cancel')" />
    <div class="relative card w-full max-w-lg">
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-semibold">{{ t('project.deploy') }} — {{ projectName }}</h2>
        <button class="text-gray-400 hover:text-gray-100" @click="$emit('cancel')">✕</button>
      </div>

      <p class="text-sm text-gray-400 mb-4">{{ t('project.selectVersion') }}</p>

      <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
        <label
          v-for="v in versions" :key="v.id"
          class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors border"
          :class="[
            v.build_status && v.build_status !== 'ready' ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
            selectedVersionId === v.id ? 'border-brand-500/50' : 'hover:bg-gray-800 border-transparent'
          ]"
          :style="selectedVersionId === v.id ? 'background:rgb(var(--brand-600) / 0.15);border-color:rgb(var(--brand-500) / 0.5)' : ''"
          :title="v.build_status && v.build_status !== 'ready' ? t('build.notReady') : ''"
        >
          <input
            type="radio" :value="v.id" v-model="selectedVersionId" class="accent-brand-500"
            :disabled="v.build_status && v.build_status !== 'ready'"
          />
          <div class="flex-1 min-w-0">
            <span class="font-mono text-sm" style="color:rgb(var(--brand-500))">{{ v.label }}</span>
            <span class="text-gray-400 text-xs ml-2 truncate">{{ v.commit_message ?? '' }}</span>
            <span
              v-if="v.build_status === 'pending' || v.build_status === 'building'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-yellow-500/20 text-yellow-400"
            >{{ t('build.building') }}</span>
            <span
              v-else-if="v.build_status === 'failed'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-red-500/20 text-red-400"
            >{{ t('build.failed') }}</span>
            <span
              v-else-if="v.build_status === 'pruned'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-gray-500/20 text-gray-400"
              :title="t('build.prunedHint')"
            >{{ t('build.pruned') }}</span>
            <span
              v-else-if="v.build_status === 'cancelled'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-gray-500/20 text-gray-400"
              :title="v.build_error || t('build.cancelledHint')"
            >{{ t('build.cancelled') }}</span>
          </div>
          <span class="text-xs text-gray-400 shrink-0">{{ fmt(v.created_at) }}</span>
        </label>
      </div>

      <div class="flex gap-3 mt-5 pt-4 border-t border-gray-800/60">
        <button class="btn-secondary flex-1" @click="$emit('cancel')">{{ t('common.cancel') }}</button>
        <button
          class="btn-primary flex-1"
          :disabled="deploying || !selectedVersionId"
          @click="doDeploy"
        >{{ deploying ? t('project.deploying') : t('project.deploy') }}</button>
      </div>
    </div>
  </div>
</template>
