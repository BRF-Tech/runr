<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { useJobEvents } from '@/composables/useJobEvents'

const props = defineProps({
  regionId: { type: String, required: true },
  status:   { type: String, default: null },
  canSeed:  { type: Boolean, default: false },
})

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const demoStatus    = ref(props.status)
const lastAction    = ref(
  props.status === 'seeding'  ? 'seed'   :
  props.status === 'removing' ? 'remove' : null,
)

// A seed / remove request in flight: the button is busy at once (the status changes only when it
// answers), so a double click cannot send a second request ("already exists")
const requesting = ref(false)
// Demo events while the request ran: a fast job can finish before it answers, and that answer
// ("seeding" / "removing") must not overwrite the status the event told
let demoEvents = 0
const busy   = computed(() => requesting.value || demoStatus.value === 'seeding' || demoStatus.value === 'removing')
const loaded = computed(() => demoStatus.value === 'ready')
const hasError = computed(() => demoStatus.value === 'error')

watch(() => props.status, (val) => {
  demoStatus.value = val
  if (val === 'seeding' && !lastAction.value)  lastAction.value = 'seed'
  if (val === 'removing' && !lastAction.value) lastAction.value = 'remove'
})

watch(demoStatus, (val) => {
  if (!lastAction.value) return
  if (val === 'ready' && lastAction.value === 'seed') {
    lastAction.value = null
    toast.success(t('settings.regionDemo.seedSuccess'))
  } else if (!val && lastAction.value === 'remove') {
    lastAction.value = null
    toast.success(t('settings.regionDemo.removeSuccess'))
  } else if (val === 'error') {
    lastAction.value = null
    toast.error(t('settings.regionDemo.error'))
  }
})

// WS event handlers — replace polling
useJobEvents({
  'demo.deployed': (payload) => {
    if (payload.region_id !== props.regionId) return
    demoEvents++
    demoStatus.value = payload.region_demo_status ?? 'ready'
  },
  'demo.removed': (payload) => {
    if (payload.region_id !== props.regionId) return
    demoEvents++
    demoStatus.value = payload.region_demo_status ?? null
  },
  'demo.seed_failed': (payload) => {
    if (payload.region_id !== props.regionId) return
    demoEvents++
    demoStatus.value = payload.region_demo_status ?? 'error'
  },
  'demo.deploy_failed': (payload) => {
    if (payload.region_id !== props.regionId) return
    demoEvents++
    demoStatus.value = payload.region_demo_status ?? 'error'
  },
  'demo.remove_failed': (payload) => {
    if (payload.region_id !== props.regionId) return
    demoEvents++
    demoStatus.value = payload.region_demo_status ?? 'error'
  },
})

async function seed() {
  if (requesting.value) return
  requesting.value = true
  const eventsBefore = demoEvents
  try {
    lastAction.value = 'seed'
    const { data } = await knativeRegionsApi.seedDemoProjects(props.regionId)
    if (demoEvents === eventsBefore) demoStatus.value = data.region_demo_status ?? 'seeding'
  } catch (err) {
    lastAction.value = null
    const msg = err.response?.data?.message ?? err.message
    toast.error(msg)
  } finally {
    requesting.value = false
  }
}

async function remove() {
  const ok = await confirm({
    title:       t('settings.regionDemo.removeBtn'),
    message:     t('settings.regionDemo.removeConfirm'),
    destructive: true,
  })
  if (!ok || requesting.value) return
  requesting.value = true
  const eventsBefore = demoEvents
  try {
    lastAction.value = 'remove'
    const { data } = await knativeRegionsApi.removeDemoProjects(props.regionId)
    if (demoEvents === eventsBefore) demoStatus.value = data.region_demo_status ?? 'removing'
  } catch (err) {
    lastAction.value = null
    const msg = err.response?.data?.message ?? err.message
    toast.error(msg)
  } finally {
    requesting.value = false
  }
}
</script>

<template>
  <div class="mt-6">
    <p class="text-xs text-gray-400 uppercase tracking-wider font-medium mb-3">
      {{ t('settings.regionDemo.section') }}
    </p>
    <div class="flex items-center justify-between rounded-md border border-gray-800 bg-gray-900/40 px-3 py-2.5">
      <div>
        <p v-if="loaded" class="text-xs text-gray-100 flex items-center gap-2">
          <span class="inline-flex items-center px-1.5 py-0.5 rounded-sm text-[10px] font-medium" style="background:rgb(var(--brand-500)/0.15);color:rgb(var(--brand-500))">
            {{ t('settings.regionDemo.loaded') }}
          </span>
        </p>
        <p v-else-if="demoStatus === 'seeding'" class="text-xs text-yellow-500 flex items-center gap-1.5">
          <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ t('settings.regionDemo.seeding') }}
        </p>
        <p v-else-if="demoStatus === 'removing'" class="text-xs text-yellow-500 flex items-center gap-1.5">
          <svg class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
          </svg>
          {{ t('settings.regionDemo.removing') }}
        </p>
        <p v-else-if="hasError" class="text-xs text-red-400">
          {{ t('settings.regionDemo.error') }}
        </p>
        <p v-else class="text-xs text-gray-400">{{ t('settings.regionDemo.notLoaded') }}</p>
        <p class="text-xs text-gray-400 mt-0.5">{{ t('settings.regionDemo.description') }}</p>
        <p v-if="!canSeed && !loaded && !busy" class="text-[10px] text-gray-400 mt-1">
          {{ t('settings.regionDemo.prerequisiteHint') }}
        </p>
      </div>
      <button
        v-if="loaded || hasError"
        type="button"
        class="text-xs text-red-500 hover:text-red-400 border border-red-800/50 hover:border-red-600/50 rounded-sm px-2.5 py-1 transition-colors shrink-0 ml-3"
        :disabled="requesting"
        @click="remove"
      >
        {{ t('settings.regionDemo.removeBtn') }}
      </button>
      <button
        v-else
        type="button"
        class="text-xs border rounded-sm px-2.5 py-1 transition-colors shrink-0 ml-3 flex items-center gap-1.5"
        :class="(!canSeed || busy) ? 'opacity-40 cursor-not-allowed' : ''"
        :disabled="!canSeed || busy"
        style="color:rgb(var(--brand-500));border-color:rgb(var(--brand-500)/0.4)"
        @click="seed"
      >
        <svg v-if="busy" class="w-3 h-3 animate-spin" viewBox="0 0 24 24" fill="none">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" />
        </svg>
        {{ busy ? t('settings.regionDemo.processing') : t('settings.regionDemo.seedBtn') }}
      </button>
    </div>
  </div>
</template>
