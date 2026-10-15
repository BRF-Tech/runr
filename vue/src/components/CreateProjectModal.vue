<script setup>
import { reactive, ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { projectSecretsApi } from '@/api/security'
import { useToast } from '@/composables/useToast'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useCreateTutorial } from '@/composables/useTutorial'
import { useTypeCards, useRuntimeData, buildCreatePayload, defaultRuntimeId } from '@/composables/useProjectForm'
import { runtimeLabel } from '@/utils/runtimes'
import { slugError, projectSlug, isValidCron, envVarsError, maxTimeoutFor } from '@/composables/projectValidation'
import { defaultSizeFor, toMillicores } from '@/utils/instanceSizes'
import RegionSelector from '@/components/project/RegionSelector.vue'
import CreateStep3 from '@/components/project/CreateStep3.vue'
import CreateStep4 from '@/components/project/CreateStep4.vue'

const props = defineProps({
  existingNames: { type: Array, default: () => [] },
})

// CPU (millicores, as the form field holds it) + memory of a type's default size
function defaultFormSize(serviceType) {
  const size = defaultSizeFor(serviceType)
  return { cpu: toMillicores(size.cpu), memory_mb: size.memory_mb }
}

const { t }         = useI18n()
const emit          = defineEmits(['created', 'close'])
const authStore     = useAuthStore()
const settingsStore = useSettingsStore()
const { start: startTutorial } = useCreateTutorial()

const step = ref(1)
const regions = computed(() => settingsStore.data.regions ?? [])
const types = useTypeCards()

const form = reactive({
  name: '',
  service_type: null,
  runtime: 'python',
  runtime_version: null,
  description: '',
  knative_region_id: null,
  config: {
    image: '',
    command: '',
    image_pull_policy: 'IfNotPresent',
    port: 80,
    ...defaultFormSize(null),
    timeout_seconds: 30,
    trigger_type: 'http',
    schedule: '',
    queue_stream: '',
    env_vars: '',
    scale_to_zero: true,
    min_scale: 0,
    max_scale: 1,
    gpu: { enabled: false, count: 1, type: '', memory_min: null },
  },
  endpoint_type: 'subdomain',
  gateway_path: '',
  gateway_methods: [],
  // Secret env vars ({ name, value }): saved through the write-only API once the project exists
  secrets: [],
})

const loading = ref(false)
const error   = ref(null)

const selectedRegion = computed(() => regions.value.find(r => r.id === form.knative_region_id))
const selectedRegionName = computed(() => selectedRegion.value?.name ?? '')
const serviceTypeLabel = computed(() => types.value.find(t => t.id === form.service_type)?.label ?? '')

const triggerSummary = computed(() => {
  const label = t(`create.triggerTypes.${form.config.trigger_type}`)
  if (form.config.trigger_type === 'schedule' && form.config.schedule.trim()) return `${label}: ${form.config.schedule.trim()}`
  if (form.config.trigger_type === 'queue' && form.config.queue_stream.trim()) return `${label}: ${form.config.queue_stream.trim()}`
  return label
})

const { runtimes, selectableRuntimes, runtimeVersions } = useRuntimeData(form)

watch(() => form.runtime, () => {
  const def = runtimeVersions.value.find(v => v.is_default)
  form.runtime_version = def?.version ?? runtimeVersions.value[0]?.version ?? null
}, { immediate: true })

// The region and trigger narrow the catalog: keep a runtime that is still offered, else the default pick
watch(selectableRuntimes, (list) => {
  if (list.length && !list.some(r => r.id === form.runtime)) form.runtime = defaultRuntimeId(list)
}, { immediate: true })

// The catalog may answer after the default versions were picked
watch(runtimeVersions, (list) => {
  if (!list.some(v => v.version === form.runtime_version)) {
    form.runtime_version = list.find(v => v.is_default)?.version ?? list[0]?.version ?? null
  }
})

watch(selectedRegion, (r) => {
  if (r && !r.is_synced && !r.is_demo && !r.has_serving && form.config.trigger_type === 'http') form.config.trigger_type = 'schedule'
})

watch(() => form.config.trigger_type, (type) => {
  if (type === 'queue' && !form.config.queue_stream.trim()) {
    form.config.queue_stream = form.name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '') || ''
  }
  if (type !== 'http') {
    form.endpoint_type = 'subdomain'
    form.gateway_path = ''
  }
})

watch(() => form.endpoint_type, (type) => {
  if (type === 'gateway' && !form.gateway_path) {
    const slug = form.name.toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
    form.gateway_path = slug ? `/${slug}` : ''
  }
})

const nameAlreadyExists = computed(() =>
  props.existingNames.some(n => n.toLowerCase() === form.name.trim().toLowerCase())
)

const nameSlugError = computed(() => slugError(form.name))
const maxTimeout = computed(() => maxTimeoutFor(selectedRegion.value))

const canNext = computed(() => {
  if (step.value === 1) return form.name.trim().length >= 2 && !!form.knative_region_id && !nameAlreadyExists.value && !nameSlugError.value
  if (step.value === 2) return !!form.service_type
  if (step.value === 3) {
    if (form.service_type === 'caas' && !form.config.image.trim()) return false
    if (form.service_type !== 'caas' && !selectableRuntimes.value.some(r => r.id === form.runtime)) return false
    if (!form.config.trigger_type) return false
    if (form.config.trigger_type === 'schedule' && !isValidCron(form.config.schedule)) return false
    if (form.config.trigger_type === 'queue' && !form.config.queue_stream.trim()) return false
    if (form.config.trigger_type === 'http' && form.endpoint_type === 'gateway' && !form.gateway_path.startsWith('/')) return false
    return true
  }
  if (step.value === 4) {
    return form.config.cpu > 0 && form.config.memory_mb >= 64 && form.config.timeout_seconds >= 1
      && form.config.timeout_seconds <= maxTimeout.value && !envVarsError(form.config.env_vars)
  }
  return true
})

function next() { if (canNext.value) step.value++ }
function back() { error.value = null; step.value-- }

function selectType(id) {
  // Follow the type's default size unless CPU/memory were changed from the
  // previous type's default (a preset pick or a hand-entered value is kept).
  const prev = defaultFormSize(form.service_type)
  if (form.config.cpu === prev.cpu && form.config.memory_mb === prev.memory_mb) {
    Object.assign(form.config, defaultFormSize(id))
  }
  form.service_type = id
  const r = selectedRegion.value
  if (r && !r.is_synced && !r.is_demo && !r.has_serving && form.config.trigger_type === 'http') form.config.trigger_type = 'schedule'
}

const toast = useToast()

async function saveSecrets(projectId) {
  for (const secret of form.secrets) {
    try {
      await projectSecretsApi.set(projectId, secret.name, secret.value)
    } catch (e) {
      toast.error(e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error'))
    }
  }
  form.secrets = []
}

async function submit() {
  error.value  = null
  loading.value = true
  try {
    const { data } = await projectsApi.create(buildCreatePayload(form))
    const project = data.data ?? data
    await saveSecrets(project.id)
    emit('created', project)
  } catch (e) {
    const errors = e.response?.data?.errors ?? {}
    const nameErr = errors.name?.[0]
    if (nameErr) { step.value = 1; error.value = nameErr }
    else { error.value = e.response?.data?.message ?? Object.values(errors).flat()[0] ?? t('common.error') }
  } finally { loading.value = false }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')" />

    <div class="relative w-full max-w-3xl">
      <div class="card">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
          <div class="flex items-center gap-3">
            <div class="flex items-center gap-1.5">
              <span
                v-for="n in 4" :key="n"
                class="h-1.5 rounded-full transition-all duration-300"
                :class="[n < step ? 'w-4' : n === step ? 'w-6' : 'w-1.5']"
                :style="n <= step ? 'background-color:rgb(var(--brand-600))' : 'background-color:#374151'"
              />
            </div>
            <span class="text-xs text-gray-400">{{ t('create.stepOf', { step }) }}</span>
            <template v-if="step >= 3">
              <span class="text-gray-400 text-xs">—</span>
              <span class="text-[11px] text-gray-400 font-mono truncate">
                {{ form.name }} · {{ selectedRegionName }} · {{ serviceTypeLabel }}
                <template v-if="step >= 4">
                  <template v-if="form.service_type !== 'caas'"> · {{ runtimeLabel(form.runtime) }} {{ form.runtime_version }} · {{ triggerSummary }}</template>
                  <template v-else> · {{ form.config.image }}{{ form.config.trigger_type !== 'schedule' && form.config.port ? ':' + form.config.port : '' }}</template>
                </template>
              </span>
            </template>
          </div>
          <div class="flex items-center gap-2">
            <button :title="t('tutorial.restart')" class="text-gray-400 hover:text-gray-100 transition-colors text-sm p-1" @click="startTutorial()">?</button>
            <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
          </div>
        </div>

        <!-- Step 1: Name + Region -->
        <div v-if="step === 1" data-tour="create-name">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step1.title') }}</h2>
          <p class="text-sm text-gray-400 mb-6">{{ t('create.step1.subtitle') }}</p>

          <label class="label">{{ t('create.fields.name') }} <span class="normal-case text-red-500/70">*</span></label>
          <input v-model="form.name" class="input text-lg py-3" :class="(error && step === 1) || nameAlreadyExists ? 'border-red-500/60' : ''" :placeholder="t('create.step1.namePlaceholder')" autofocus @keyup.enter="next" @input="error = null" />
          <p v-if="nameAlreadyExists" class="text-sm text-red-400 mt-1.5">{{ t('create.step1.nameTaken') }}</p>
          <p v-else-if="error && step === 1" class="text-sm text-red-400 mt-1.5">{{ error }}</p>
          <p v-else-if="nameSlugError" class="text-sm text-red-400 mt-1.5">{{ t(nameSlugError.key, nameSlugError.params) }}</p>
          <p v-else class="text-xs text-gray-400 mt-2 font-mono">slug: {{ projectSlug(form.name) || '—' }}</p>

          <div class="mt-6">
            <label class="label">{{ t('create.fields.description') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input v-model="form.description" class="input" :placeholder="t('create.step1.descriptionPlaceholder')" />
          </div>

          <div class="mt-6" data-tour="create-region">
            <label class="label">{{ t('create.sections.region') }} <span class="normal-case text-red-500/70">*</span></label>
            <RegionSelector :regions="regions" :selected-region-id="form.knative_region_id" @select="form.knative_region_id = $event" />
          </div>

          <div class="flex justify-end mt-8">
            <button class="btn-primary" :disabled="!canNext" @click="next">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 2: Type -->
        <div v-else-if="step === 2" data-tour="create-types">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step2.title') }}</h2>
          <p class="text-sm text-gray-400 mb-6">{{ t('create.step2.subtitle') }}</p>

          <div class="flex flex-col gap-3">
            <button
              v-for="typeItem in types" :key="typeItem.id"
              class="text-left p-4 rounded-xl border transition-all duration-150"
              :class="form.service_type === typeItem.id ? typeItem.active : 'border-gray-800 bg-gray-900/50 hover:border-gray-700'"
              @click="selectType(typeItem.id)"
            >
              <div class="flex items-start gap-3">
                <span class="text-2xl mt-0.5">{{ typeItem.icon }}</span>
                <div>
                  <div class="flex items-baseline gap-2">
                    <span class="font-medium text-gray-100">{{ typeItem.label }}</span>
                    <span class="text-xs text-gray-400 font-mono">{{ typeItem.subtitle }}</span>
                  </div>
                  <p class="text-sm text-gray-400 mt-1 leading-relaxed">{{ typeItem.description }}</p>
                </div>
              </div>
            </button>
          </div>

          <div class="flex justify-between mt-8">
            <button class="btn-secondary" @click="back">{{ t('common.back') }}</button>
            <button class="btn-primary" :disabled="!canNext" @click="next">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 3: Lang + Trigger + Endpoint Type -->
        <CreateStep3
          v-else-if="step === 3"
          :form="form"
          :runtimes="runtimes"
          :runtime-versions="runtimeVersions"
          :selected-region="selectedRegion"
          :selected-region-name="selectedRegionName"
          :username="authStore.user?.username ?? ''"
          :can-next="canNext"
          @next="next"
          @back="back"
        />

        <!-- Step 4: Resources + Scale + Env -->
        <CreateStep4
          v-else-if="step === 4"
          :form="form"
          :can-next="canNext"
          :max-timeout="maxTimeout"
          :loading="loading"
          :error="error"
          @back="back"
          @submit="submit"
        />
      </div>
    </div>
  </div>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
