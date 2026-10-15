<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { settingsApi } from '@/api/settings'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { useJobEvents } from '@/composables/useJobEvents'

const props = defineProps({
  status: { type: String, default: 'idle' },
})
const emit = defineEmits(['changed'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const demoStatus = ref(props.status)
const lastDemoAction = ref(
  props.status === 'seeding'  ? 'seed'   :
  props.status === 'removing' ? 'remove' : null,
)

const demoBusy   = computed(() => demoStatus.value === 'seeding' || demoStatus.value === 'removing')
const demoLoaded = computed(() => demoStatus.value === 'loaded')

watch(() => props.status, (val) => {
  demoStatus.value = val
  if (val === 'seeding' && !lastDemoAction.value)  lastDemoAction.value = 'seed'
  if (val === 'removing' && !lastDemoAction.value) lastDemoAction.value = 'remove'
})

watch(demoStatus, (val) => {
  if (!lastDemoAction.value) return
  if (val === 'loaded' && lastDemoAction.value === 'seed') {
    lastDemoAction.value = null
    toast.success(t('settings.demo.seedSuccess'))
    emit('changed')
  } else if (val === 'idle' && lastDemoAction.value === 'remove') {
    lastDemoAction.value = null
    toast.success(t('settings.demo.removeSuccess'))
    emit('changed')
  }
})

// WS event handlers — replace polling
useJobEvents({
  'demo.seeded': (payload) => {
    demoStatus.value = payload.demo_status ?? 'loaded'
  },
  'demo.removed': (payload) => {
    if (!payload.region_id) demoStatus.value = payload.demo_status ?? 'idle'
  },
  'demo.seed_failed': (payload) => {
    if (!payload.region_id) demoStatus.value = payload.demo_status ?? 'error'
  },
  'demo.remove_failed': (payload) => {
    if (!payload.region_id) demoStatus.value = payload.demo_status ?? 'error'
  },
})

async function seedDemo() {
  try {
    lastDemoAction.value = 'seed'
    const { data } = await settingsApi.seedDemo()
    demoStatus.value = data.demo_status ?? 'seeding'
  } catch {
    // silent
  }
}

async function removeDemo() {
  const ok = await confirm({
    title:       t('settings.demo.removeBtn'),
    message:     t('settings.demo.removeConfirm'),
    destructive: true,
  })
  if (!ok) return
  try {
    lastDemoAction.value = 'remove'
    const { data } = await settingsApi.removeDemo()
    demoStatus.value = data.demo_status ?? 'removing'
  } catch {
    // silent
  }
}

defineExpose({ demoBusy })
</script>

<template>
  <div data-tour="settings-demo">
    <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3">
      {{ t('settings.demo.section') }}
    </p>
    <div class="flex items-center justify-between rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5">
      <div>
        <p v-if="demoLoaded" class="text-xs text-gray-100 flex items-center gap-2">
          <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">
            {{ t('settings.demo.loaded') }}
          </span>
        </p>
        <p v-else-if="demoStatus === 'seeding'" class="text-xs text-yellow-500 flex items-center gap-1.5">
          <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ t('settings.demo.seeding') }}
        </p>
        <p v-else-if="demoStatus === 'removing'" class="text-xs text-yellow-500 flex items-center gap-1.5">
          <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ t('settings.demo.removing') }}
        </p>
        <p v-else class="text-xs text-gray-400">{{ t('settings.demo.notLoaded') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('settings.demo.description') }}</p>
      </div>
      <button
        v-if="demoLoaded"
        type="button"
        class="text-xs text-red-500 hover:text-red-400 border border-red-800/50 hover:border-red-600/50 rounded px-2.5 py-1 transition-colors shrink-0 ml-3"
        @click="removeDemo"
      >
        {{ t('settings.demo.removeBtn') }}
      </button>
      <button
        v-else
        type="button"
        class="text-xs border rounded px-2.5 py-1 transition-colors shrink-0 ml-3 flex items-center gap-1.5"
        :class="demoBusy ? 'opacity-50 cursor-not-allowed' : ''"
        :disabled="demoBusy"
        style="color:rgb(var(--brand-500));border-color:rgb(var(--brand-500)/0.4)"
        @click="seedDemo"
      >
        <svg v-if="demoBusy" class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        {{ demoBusy ? t('settings.demo.processing') : t('settings.demo.seedBtn') }}
      </button>
    </div>
  </div>
</template>
