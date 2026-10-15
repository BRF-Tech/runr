<script setup>
// One-click deploy of a model as a model service: region, online (download into the region's
// model cache) or offline (mirrored snapshot, no egress), CPU or GPU, engine (auto = RunR's
// pick for the task and size), resources, scaling and an advanced image / args / env override.
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Info, ChevronDown, ChevronRight, Globe, ShieldOff } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { useRegionGpu } from '@/composables/useRegionGpu'
import { isRegionReady } from '@/composables/useProjectForm'
import { maxTimeoutFor } from '@/composables/projectValidation'
import { formatBytes, gpuPayload, parseArgLines, parseEnvLines } from '@/utils/ai'
import GpuFields from '@/components/ai/GpuFields.vue'
import ScaleConfig from '@/components/project/ScaleConfig.vue'

const { t } = useI18n()
const ai = useAiStore()
const settingsStore = useSettingsStore()
const toast = useToast()

const props = defineProps({
  // Hub model card (GET /models/hub/info) for a one-click deploy from the Hub …
  hubInfo: { type: Object, default: null },
  // … or a model of the library (AiModel)
  model:   { type: Object, default: null },
})
const emit = defineEmits(['close', 'deployed'])

const task      = computed(() => props.hubInfo?.task ?? props.model?.task ?? null)
const sizeBytes = computed(() => props.hubInfo?.size_bytes ?? props.model?.size_bytes ?? null)
const suggested = computed(() => props.hubInfo?.suggested ?? null)
const modelLabel = computed(() => props.hubInfo?.id ?? props.model?.hub_id ?? props.model?.name ?? '')

function defaultName(id) {
  const last = String(id ?? '').split('/').pop() ?? ''
  const slug = last.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 30).replace(/-+$/, '')
  return /^[a-z]/.test(slug) ? slug : `m-${slug}`.slice(0, 30)
}

const regions = computed(() => (settingsStore.data.regions ?? []).filter(isRegionReady))

const form = reactive({
  name:              defaultName(modelLabel.value),
  knative_region_id: null,
  mode:              'online',
  engine:            'auto',
  device:            suggested.value?.device ?? 'cpu',
  gpu:               { enabled: true, count: suggested.value?.gpu?.count ?? 1, type: '', memory_min: null },
  cpu:               suggested.value?.cpu ?? '1',
  memory_mb:         suggested.value?.memory_mb ?? 2048,
  scale_to_zero:     true,
  min_scale:         0,
  max_scale:         1,
  timeout_seconds:   300,
  image:             '',
  args:              '',
  env_vars:          '',
  port:              null,
  health_path:       '',
})

const showAdvanced = ref(false)
const saving = ref(false)
const error  = ref(null)
const quota  = ref(null)

/** Engines for the task: the card's list (with devices), else the catalog. */
const engineOptions = computed(() => {
  if (props.hubInfo?.engines?.length) {
    const list = props.hubInfo.engines.map(e => ({ id: e.engine, devices: e.devices ?? ai.engineDevices(e.engine), recommended: !!e.recommended }))
    return list.some(e => e.id === 'custom') ? list : [...list, { id: 'custom', devices: ['cpu', 'gpu'], recommended: false }]
  }
  return ai.enginesForTask(task.value).map(id => ({ id, devices: ai.engineDevices(id), recommended: false }))
})

const deviceOptions = computed(() => {
  if (form.engine === 'auto') {
    const all = new Set(engineOptions.value.filter(e => e.id !== 'custom').flatMap(e => e.devices))
    return ['cpu', 'gpu'].filter(d => all.size === 0 || all.has(d))
  }
  return engineOptions.value.find(e => e.id === form.engine)?.devices ?? ['cpu', 'gpu']
})

watch(deviceOptions, (opts) => {
  if (opts.length && !opts.includes(form.device)) form.device = opts[0]
}, { immediate: true })

const regionIdRef = computed(() => form.knative_region_id)
const { products, hasGpus, gpu: regionGpu } = useRegionGpu(regionIdRef)

watch(() => form.knative_region_id, async (id) => {
  quota.value = null
  if (!id) return
  try {
    const { data } = await aiApi.gpuQuota(id)
    quota.value = data
  } catch { quota.value = null }
})

const isCustom = computed(() => form.engine === 'custom')
const maxTimeout = computed(() => maxTimeoutFor(regions.value.find(r => r.id === form.knative_region_id)))
const coldStartHint = computed(() => form.device === 'gpu' || suggested.value?.suggest_min_scale || (sizeBytes.value ?? 0) > 2 * 1024 ** 3)
const nameValid = computed(() => /^[a-z]([-a-z0-9]{0,28}[a-z0-9])?$/.test(form.name))

const canSubmit = computed(() => nameValid.value && !!form.knative_region_id && !saving.value
  && (!isCustom.value || form.image.trim()) && Number(form.memory_mb) >= 64)

onMounted(() => {
  ai.fetchEngines()
  if (!settingsStore.loaded) settingsStore.fetch()
  if (regions.value.length === 1) form.knative_region_id = regions.value[0].id
})

async function submit() {
  saving.value = true
  error.value  = null
  try {
    const payload = {
      name:              form.name,
      knative_region_id: form.knative_region_id,
      mode:              form.mode,
      device:            form.device,
      engine:            form.engine,
      cpu:               String(form.cpu || '') || undefined,
      memory_mb:         Number(form.memory_mb) || undefined,
      scale_to_zero:     form.scale_to_zero,
      min_scale:         form.min_scale,
      max_scale:         form.max_scale,
      timeout_seconds:   Number(form.timeout_seconds) || undefined,
      deploy:            true,
    }
    if (props.model?.id) payload.ai_model_id = props.model.id
    else {
      payload.hub_id   = props.hubInfo?.id
      if (props.hubInfo?.sha) payload.revision = props.hubInfo.sha
    }
    if (form.device === 'gpu') payload.gpu = gpuPayload({ ...form.gpu, enabled: true })
    if (form.image.trim()) payload.image = form.image.trim()
    const args = parseArgLines(form.args)
    if (args) payload.args = args
    const env = parseEnvLines(form.env_vars)
    if (env) payload.env_vars = env
    if (Number(form.port) > 0) payload.port = Number(form.port)
    if (form.health_path.trim()) payload.health_path = form.health_path.trim()

    const { data } = await aiApi.createService(payload)
    const service = data?.data ?? data
    ai.upsertService(service)
    toast.success(t('ai.deploy.created', { name: service?.name ?? form.name }))
    emit('deployed', service)
  } catch (e) {
    error.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="deploy-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')" />
    <div class="relative w-full max-w-2xl card max-h-[90vh] flex flex-col">
      <div class="flex items-start justify-between mb-4">
        <div class="min-w-0">
          <h2 class="text-lg font-semibold text-gray-100">{{ t('ai.deploy.title') }}</h2>
          <p class="text-xs text-gray-400 font-mono truncate">{{ modelLabel }}<template v-if="sizeBytes"> · {{ formatBytes(sizeBytes) }}</template><template v-if="task"> · {{ t(`ai.tasks.${task}`, task) }}</template></p>
        </div>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div class="flex-1 overflow-y-auto pr-1 space-y-5">
        <!-- Name + region -->
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="deploy-name">{{ t('ai.deploy.name') }}</label>
            <input id="deploy-name" v-model="form.name" class="input font-mono" data-testid="deploy-name" />
            <p v-if="!nameValid" class="text-xs text-red-400 mt-1">{{ t('ai.deploy.nameInvalid') }}</p>
          </div>
          <div>
            <label class="label" for="deploy-region">{{ t('ai.deploy.region') }}</label>
            <select id="deploy-region" v-model="form.knative_region_id" class="input" data-testid="deploy-region">
              <option :value="null" disabled>{{ t('ai.deploy.selectRegion') }}</option>
              <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
            </select>
          </div>
        </div>

        <!-- Online / offline -->
        <section>
          <p class="section-title">{{ t('ai.deploy.mode') }}</p>
          <div class="grid grid-cols-2 gap-2">
            <button v-for="m in ['online', 'offline']" :key="m" type="button"
              class="text-left p-3 rounded-lg border transition-colors"
              :class="form.mode === m ? 'border-brand-500/60 bg-brand-900/20' : 'border-gray-800 bg-gray-900/40 hover:border-gray-700'"
              :data-testid="`deploy-mode-${m}`" @click="form.mode = m">
              <span class="flex items-center gap-1.5 text-sm font-medium text-gray-100">
                <Globe v-if="m === 'online'" class="w-4 h-4" /><ShieldOff v-else class="w-4 h-4" />{{ t(`ai.modes.${m}`) }}
              </span>
              <span class="block text-[11px] text-gray-400 mt-1">{{ t(`ai.deploy.modeHints.${m}`) }}</span>
            </button>
          </div>
        </section>

        <!-- Engine + device -->
        <section>
          <p class="section-title">{{ t('ai.deploy.engine') }}</p>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="label" for="deploy-engine">{{ t('ai.deploy.engine') }}</label>
              <select id="deploy-engine" v-model="form.engine" class="input" data-testid="deploy-engine">
                <option value="auto">{{ t('ai.deploy.engineAuto') }}</option>
                <option v-for="e in engineOptions" :key="e.id" :value="e.id">
                  {{ t(`ai.engines.${e.id}`, e.id) }}{{ e.recommended ? ` — ${t('ai.hub.recommended')}` : '' }}
                </option>
              </select>
            </div>
            <div>
              <label class="label">{{ t('ai.deploy.device') }}</label>
              <div class="flex rounded-md overflow-hidden border border-gray-700">
                <button v-for="d in ['cpu', 'gpu']" :key="d" type="button"
                  class="flex-1 px-3 py-2 text-sm font-mono transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                  :class="form.device === d ? 'text-white' : 'bg-gray-800 text-gray-400 hover:text-brand-500'"
                  :style="form.device === d ? 'background:rgb(var(--brand-500))' : ''"
                  :disabled="!deviceOptions.includes(d)" :data-testid="`deploy-device-${d}`"
                  @click="form.device = d">{{ d.toUpperCase() }}</button>
              </div>
            </div>
          </div>
          <div v-if="form.device === 'gpu'" class="mt-3 space-y-2">
            <GpuFields id-prefix="deploy-gpu" :products="products"
              v-model:count="form.gpu.count" v-model:type="form.gpu.type" v-model:memory-min="form.gpu.memory_min" />
            <p v-if="suggested?.gpu?.memory_min_hint_mb" class="text-[11px] text-gray-400">
              {{ t('ai.deploy.gpuMemoryHint', { memory: Math.ceil(suggested.gpu.memory_min_hint_mb / 1024) }) }}
            </p>
            <p v-if="form.knative_region_id && regionGpu && !hasGpus" class="text-xs text-amber-400">{{ t('ai.gpu.noGpuNodes') }}</p>
            <p v-if="quota && quota.quota !== null && quota.quota !== undefined" class="text-[11px] text-gray-400" data-testid="deploy-gpu-quota">
              {{ t('ai.deploy.gpuQuota', { used: quota.used ?? 0, quota: quota.quota, remaining: quota.remaining ?? 0 }) }}
            </p>
          </div>
        </section>

        <!-- Resources -->
        <section>
          <p class="section-title">{{ t('create.sections.resources') }}</p>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="label" for="deploy-cpu">{{ t('ai.deploy.cpu') }}</label>
              <input id="deploy-cpu" v-model="form.cpu" class="input font-mono" placeholder="1" />
            </div>
            <div>
              <label class="label" for="deploy-memory">{{ t('create.fields.memoryMb') }}</label>
              <input id="deploy-memory" v-model.number="form.memory_mb" type="number" min="64" step="256" class="input font-mono" data-testid="deploy-memory" />
            </div>
            <div>
              <label class="label" for="deploy-timeout">{{ t('create.fields.timeoutS') }}</label>
              <input id="deploy-timeout" v-model.number="form.timeout_seconds" type="number" min="1" :max="maxTimeout || undefined" class="input font-mono" />
            </div>
          </div>
        </section>

        <ScaleConfig v-model:scale-to-zero="form.scale_to_zero" v-model:min-scale="form.min_scale" v-model:max-scale="form.max_scale" />
        <div v-if="coldStartHint" class="flex items-start gap-2 rounded-lg border border-gray-700 bg-gray-800/40 px-3 py-2 text-[11px] text-gray-400" data-testid="deploy-cold-start">
          <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />
          <span>{{ t('ai.deploy.coldStart') }}<template v-if="form.scale_to_zero"> {{ t('ai.gpu.minScaleSuggestion') }}</template></span>
        </div>

        <!-- Advanced -->
        <section>
          <button type="button" class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-100" data-testid="deploy-advanced" @click="showAdvanced = !showAdvanced">
            <ChevronDown v-if="showAdvanced" class="w-3.5 h-3.5" /><ChevronRight v-else class="w-3.5 h-3.5" />{{ t('ai.deploy.advanced') }}
          </button>
          <div v-if="showAdvanced || isCustom" class="mt-3 space-y-3">
            <p class="text-[11px] text-gray-400">{{ t('ai.deploy.advancedHint') }}</p>
            <div>
              <label class="label" for="deploy-image">{{ t('ai.deploy.image') }}</label>
              <input id="deploy-image" v-model="form.image" class="input font-mono" :placeholder="isCustom ? 'ghcr.io/acme/my-server:1.0' : t('ai.deploy.imageDefault')" data-testid="deploy-image" />
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label" for="deploy-args">{{ t('ai.deploy.args') }}</label>
                <textarea id="deploy-args" v-model="form.args" rows="3" class="input font-mono text-xs" placeholder="--max-model-len&#10;4096" />
              </div>
              <div>
                <label class="label" for="deploy-env">{{ t('ai.deploy.env') }}</label>
                <textarea id="deploy-env" v-model="form.env_vars" rows="3" class="input font-mono text-xs" placeholder="KEY=value" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
              <div>
                <label class="label" for="deploy-port">{{ t('ai.deploy.port') }}</label>
                <input id="deploy-port" v-model.number="form.port" type="number" min="1" max="65535" class="input font-mono" placeholder="8080" />
              </div>
              <div>
                <label class="label" for="deploy-health">{{ t('ai.deploy.healthPath') }}</label>
                <input id="deploy-health" v-model="form.health_path" class="input font-mono" placeholder="/health" />
              </div>
            </div>
          </div>
        </section>
      </div>

      <p v-if="error" class="text-sm text-red-400 mt-3" data-testid="deploy-error">{{ error }}</p>
      <div class="flex justify-end gap-2 pt-4 mt-2 border-t border-gray-800/60">
        <button class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="!canSubmit" data-testid="deploy-submit" @click="submit">
          {{ saving ? t('ai.deploy.deploying') : t('ai.deploy.submit') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
