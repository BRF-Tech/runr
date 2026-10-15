<script setup>
import { useI18n } from 'vue-i18n'

const { t, locale } = useI18n()

defineProps({
  versions:        { type: Array, default: () => [] },
  versionsLoaded:  { type: Boolean, default: false },
  selectedVersion: { type: Object, default: null },
  configMode:      { type: String, default: null },
})

defineEmits(['select'])

function fmt(dateTime) {
  if (!dateTime) return '—'
  return new Date(dateTime).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div data-tour="version-list" class="w-60 shrink-0 flex flex-col overflow-hidden">

    <!-- Header -->
    <div class="flex items-center gap-2 px-2.5 mb-3 shrink-0">
      <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
      <span class="text-[15px] font-semibold text-gray-100">{{ t('project.tabs.versions') }}</span>
      <span v-if="versionsLoaded && versions.length" class="ml-auto text-[10px] font-mono px-1.5 py-0.5 rounded-sm" style="background:rgb(var(--brand-500)/0.1);color:rgb(var(--brand-500))">{{ versions.length }}</span>
    </div>

    <!-- Loading -->
    <div v-if="!versionsLoaded" class="text-xs text-gray-400 px-1 py-6 text-center">
      {{ t('common.loading') }}
    </div>

    <!-- List -->
    <div class="flex-1 overflow-y-auto flex flex-col gap-1 pr-0.5">
      <div
        v-for="(v, i) in versions" :key="v.id"
        class="group relative flex flex-col gap-1 px-3 py-3 rounded-lg cursor-pointer transition-all border"
        :class="selectedVersion?.id === v.id
          ? 'text-gray-100'
          : 'border-transparent text-gray-400 hover:text-gray-100 hover:bg-gray-800/50 hover:border-gray-700/60'"
        :style="selectedVersion?.id === v.id
          ? 'background:linear-gradient(135deg,rgb(var(--brand-600)/0.25),rgb(var(--brand-700)/0.12));border-color:rgb(var(--brand-500)/0.35)'
          : ''"
        @click="$emit('select', v)"
      >
        <!-- Selected accent bar -->
        <div
          class="absolute left-0 top-3 bottom-3 w-0.5 rounded-full transition-all"
          :style="selectedVersion?.id === v.id ? 'background:rgb(var(--brand-500))' : 'background:transparent'"
        />

        <!-- Version label + badges -->
        <div class="flex items-center gap-1.5 pl-2">
          <span class="font-mono text-sm font-semibold leading-tight">{{ v.label }}</span>
          <span
            v-if="i === 0"
            class="text-[9px] px-1.5 py-0.5 rounded-sm font-medium leading-none"
            :style="selectedVersion?.id === v.id
              ? 'background:rgb(255 255 255/0.15);color:#fff'
              : 'background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))'"
          >latest</span>
          <span
            v-if="configMode === 'blueprint'"
            class="text-[9px] px-1.5 py-0.5 rounded-sm font-medium leading-none bg-amber-500/15 text-amber-400"
          >blueprint</span>
          <span
            v-if="v.build_status === 'pending' || v.build_status === 'building'"
            class="text-[9px] px-1.5 py-0.5 rounded-sm font-medium leading-none bg-yellow-500/15 text-yellow-400"
            :title="t('build.building')"
          >{{ t('build.building') }}</span>
          <span
            v-else-if="v.build_status === 'failed'"
            class="text-[9px] px-1.5 py-0.5 rounded-sm font-medium leading-none bg-red-500/15 text-red-400"
            :title="v.build_error || t('build.failed')"
          >{{ t('build.failed') }}</span>
          <span
            v-else-if="v.build_status === 'pruned'"
            class="text-[9px] px-1.5 py-0.5 rounded-sm font-medium leading-none bg-gray-500/15 text-gray-400"
            :title="t('build.prunedHint')"
          >{{ t('build.pruned') }}</span>
        </div>

        <!-- Commit message -->
        <p class="text-xs leading-snug pl-2 line-clamp-2"
          :class="selectedVersion?.id === v.id ? 'text-gray-300' : 'text-gray-300'">
          {{ v.commit_message ?? '—' }}
        </p>

        <!-- Creator + Date -->
        <div class="flex items-center gap-1 pl-2 text-[11px]"
          :class="selectedVersion?.id === v.id ? 'text-gray-400' : 'text-gray-500'">
          <template v-if="v.created_by?.name">
            <span class="truncate max-w-20">{{ v.created_by.name }}</span>
            <span>·</span>
          </template>
          <span class="whitespace-nowrap">{{ fmt(v.created_at) }}</span>
        </div>
      </div>

      <p v-if="versionsLoaded && !versions.length" class="text-xs text-gray-400 px-1 py-6 text-center">
        {{ t('project.noVersions') }}
      </p>
    </div>
  </div>
</template>
