<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import { runtimeLabel } from '@/utils/runtimes'

const { t } = useI18n()

const props = defineProps({
  runtimes: { type: Array, required: true },   // [{ key, runtime, version }]
  selectedRuntimes: { type: Array, required: true },
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['toggle', 'clear', 'update:open'])

const grouped = computed(() => {
  const map = {}
  for (const rt of props.runtimes) {
    if (!map[rt.runtime]) map[rt.runtime] = []
    map[rt.runtime].push(rt)
  }
  return Object.entries(map).sort(([a], [b]) => a.localeCompare(b))
})

function isLangFullySelected(versions) {
  return versions.every(v => props.selectedRuntimes.includes(v.key))
}

function isLangPartial(versions) {
  return versions.some(v => props.selectedRuntimes.includes(v.key)) && !isLangFullySelected(versions)
}

function toggleLang(versions) {
  const allSelected = isLangFullySelected(versions)
  for (const v of versions) {
    const included = props.selectedRuntimes.includes(v.key)
    if (allSelected && included) emit('toggle', v.key)
    if (!allSelected && !included) emit('toggle', v.key)
  }
}

function isVersionSelected(key) {
  return props.selectedRuntimes.includes(key)
}
</script>

<template>
  <div v-if="runtimes.length > 0" class="relative" data-runtime-dropdown>
    <!-- Trigger -->
    <button
      class="h-9 flex items-center gap-2 px-3 rounded-md text-xs font-medium border transition-colors"
      :class="selectedRuntimes.length > 0
        ? 'text-white border-transparent'
        : 'bg-gray-900 border-gray-800 text-gray-400 hover:text-gray-100'"
      :style="selectedRuntimes.length > 0 ? 'background:rgb(var(--brand-600)/0.2);border-color:rgb(var(--brand-500)/0.4);color:rgb(var(--brand-500))' : ''"
      @click.stop="$emit('update:open', !open)"
    >
      {{ t('projects.runtimeFilter') }}
      <span
        v-if="selectedRuntimes.length > 0"
        class="text-white rounded-full w-4.5 h-4.5 flex items-center justify-center text-[10px] leading-none font-bold"
        style="background-color:rgb(var(--brand-600))"
      >{{ selectedRuntimes.length }}</span>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-3 w-3 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 20 20" fill="currentColor">
        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
      </svg>
    </button>

    <!-- Panel -->
    <div
      v-if="open"
      class="absolute right-0 top-full mt-1.5 z-20 bg-gray-950 border border-gray-800 rounded-lg shadow-2xl"
      style="min-width: 320px"
    >
      <div class="grid grid-cols-2 gap-2 p-3">
        <div
          v-for="[lang, versions] in grouped"
          :key="lang"
          class="rounded-lg border p-2.5 transition-all"
          :class="isLangFullySelected(versions)
            ? 'border-sky-600/50 bg-sky-950/30'
            : isLangPartial(versions)
              ? 'border-sky-700/30 bg-gray-900/60'
              : 'border-gray-800 bg-gray-900/40 hover:border-gray-700'"
        >
          <!-- Runtime header -->
          <div
            class="flex items-center gap-2 cursor-pointer mb-2"
            @click.stop="toggleLang(versions)"
          >
            <RuntimeIcon :runtime="lang" :size="22" />
            <span
              class="text-sm font-semibold"
              :class="isLangFullySelected(versions) || isLangPartial(versions) ? 'text-gray-100' : 'text-gray-400'"
            >{{ runtimeLabel(lang) }}</span>
            <span class="ml-auto text-[10px] text-gray-400 font-mono">{{ versions.length }}</span>
          </div>

          <!-- Version chips -->
          <div class="flex flex-wrap gap-1">
            <button
              v-for="v in versions"
              :key="v.key"
              class="px-2 py-0.5 rounded-sm text-[11px] font-mono border transition-all"
              :class="isVersionSelected(v.key)
                ? 'bg-sky-900/50 border-sky-600/50 text-sky-300'
                : 'bg-gray-800/60 border-gray-700/50 text-gray-400 hover:text-gray-100 hover:border-gray-600'"
              @click.stop="$emit('toggle', v.key)"
            >{{ v.version || t('projects.runtimeDefault') }}</button>
          </div>
        </div>
      </div>

      <!-- Clear -->
      <button
        v-if="selectedRuntimes.length > 0"
        class="w-full text-center px-3 py-2 text-[11px] text-gray-400 hover:text-gray-100 border-t border-gray-800/60 transition-colors"
        @click.stop="$emit('clear')"
      >{{ t('projects.runtimeAll') }}</button>
    </div>
  </div>
</template>
