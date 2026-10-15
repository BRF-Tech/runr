<script setup>
import { reactive, ref, computed, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useTutorialStore } from '@/stores/tutorial'
import { useEditTutorial } from '@/composables/useTutorial'
import { envVarsToString, parseEnvVars, buildConfigPayload, useTypeCards, useRuntimeData } from '@/composables/useProjectForm'
import { runtimeLabel } from '@/utils/runtimes'
import { isValidCron, envVarsError, maxTimeoutFor } from '@/composables/projectValidation'
import { defaultSizeFor, toMillicores } from '@/utils/instanceSizes'
import RegionSelector from '@/components/project/RegionSelector.vue'
import RuntimeSelector from '@/components/project/RuntimeSelector.vue'
import ResourcesEditor from '@/components/project/ResourcesEditor.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import EnvVarsEditor from '@/components/project/EnvVarsEditor.vue'
import SecretEnvVarsEditor from '@/components/project/SecretEnvVarsEditor.vue'
import ScaleConfig from '@/components/project/ScaleConfig.vue'
import GpuConfig from '@/components/project/GpuConfig.vue'
import { gpuForm } from '@/utils/ai'

const { t }         = useI18n()
const settingsStore = useSettingsStore()
const tutorialStore = useTutorialStore()
const { start: startEditTutorial } = useEditTutorial()

watch(() => tutorialStore.restartCounter, () => { nextTick(() => startEditTutorial()) })

const props = defineProps({ project: { type: Object, default: null } })
const canEditEnv = computed(() =>
  !props.project?.config?.env_vars_hidden && usePermissionsStore().can('env.edit', props.project?.id))
const emit  = defineEmits(['updated', 'close'])

const step = ref(1)
const regions = computed(() => settingsStore.data.regions ?? [])
const types = useTypeCards()
const defaultSize = defaultSizeFor(props.project.service_type)

const form = reactive({
  name:               props.project.name,
  service_type:       props.project.service_type,
  runtime:            props.project.runtime ?? 'python',
  runtime_version:    props.project.runtime_version ?? null,
  description:        props.project.description ?? '',
  knative_region_id:  props.project.knative_region_id ?? null,
  config_message: '',
  config: {
    image:             props.project.config?.image             ?? '',
    command:           props.project.config?.command?.join(' ') ?? '',
    image_pull_policy: props.project.config?.image_pull_policy ?? 'IfNotPresent',
    port:              props.project.config?.ports?.[0]        ?? 80,
    cpu:             toMillicores(props.project.config?.cpu) || toMillicores(defaultSize.cpu),
    memory_mb:       props.project.config?.memory_mb       ?? defaultSize.memory_mb,
    timeout_seconds: props.project.config?.timeout_seconds ?? 30,
    trigger_type:    props.project.config?.trigger_type    ?? 'http',
    schedule:        props.project.config?.schedule        ?? '',
    queue_stream:    props.project.config?.queue_stream    ?? '',
    env_vars:        envVarsToString(props.project.config?.env_vars),
    scale_to_zero:   props.project.config?.scale_to_zero   ?? true,
    min_scale:       props.project.config?.min_scale        ?? 0,
    max_scale:       props.project.config?.max_scale        ?? 1,
    // stored: turning the GPU off sends gpu: null (buildConfigPayload)
    gpu:             { ...gpuForm(props.project.config?.gpu), stored: !!props.project.config?.gpu },
  },
})

const loading = ref(false)
const error   = ref(null)
const initial = JSON.parse(JSON.stringify(form))
const isDirty = computed(() => JSON.stringify(form) !== JSON.stringify(initial))

const selectedRegion = computed(() => regions.value.find(r => r.id === form.knative_region_id))
const selectedRegionName = computed(() => selectedRegion.value?.name ?? '')
const serviceTypeLabel = computed(() => types.value.find(t => t.id === form.service_type)?.label ?? '')

const triggerSummary = computed(() => {
  const label = t(`create.triggerTypes.${form.config.trigger_type}`)
  if (form.config.trigger_type === 'schedule' && form.config.schedule.trim()) return `${label}: ${form.config.schedule.trim()}`
  if (form.config.trigger_type === 'queue' && form.config.queue_stream.trim()) return `${label}: ${form.config.queue_stream.trim()}`
  return label
})

// The runtime can not change on edit: the project's own runtime stays listed (read-only pick)
const { runtimes, runtimeVersions } = useRuntimeData(form, { includeCurrent: true })

// The chosen trigger must be one the runtime supports (manifest triggers) / the region offers it
const runtimeEntry = computed(() => runtimes.value.find(r => r.id === form.runtime) ?? null)
const runtimeTriggerUnsupported = computed(() => form.service_type !== 'caas' && !!runtimeEntry.value?.unsupported)
const runtimeRegionUnavailable  = computed(() => form.service_type !== 'caas' && !!runtimeEntry.value
  && Array.isArray(runtimeEntry.value.regions) && !!form.knative_region_id && !runtimeEntry.value.regions.includes(form.knative_region_id))

// Secret env vars are saved on their own (write-only API); a name is either plain or secret
const plainEnvNames = computed(() => Object.keys(parseEnvVars(form.config.env_vars) ?? {}))

const canNext = computed(() => {
  if (step.value === 1) return !!form.knative_region_id && !runtimeRegionUnavailable.value
  if (step.value === 2) return true
  if (step.value === 3) {
    if (form.service_type === 'caas' && !form.config.image.trim()) return false
    if (runtimeTriggerUnsupported.value) return false
    if (!form.config.trigger_type) return false
    if (form.config.trigger_type === 'schedule' && !isValidCron(form.config.schedule)) return false
    if (form.config.trigger_type === 'queue' && !form.config.queue_stream.trim()) return false
    return true
  }
  if (step.value === 4) {
    return form.config.cpu > 0 && form.config.memory_mb >= 64 && form.config.timeout_seconds >= 1
      && form.config.timeout_seconds <= maxTimeoutFor(selectedRegion.value) && !envVarsError(form.config.env_vars)
  }
  return true
})
function next() { if (canNext.value) step.value++ }
function back() { error.value = null; step.value-- }
async function submit() {
  error.value  = null
  loading.value = true
  try {
    const base = {
      description:       form.description.trim() || undefined,
      knative_region_id: form.knative_region_id || undefined,
      config_message:    form.config_message || undefined,
    }
    // runtime ve runtime_version edit sırasında değiştirilemez
    const { data } = await projectsApi.update(props.project.id, { ...base, config: buildConfigPayload(form.config, form.service_type) })
    emit('updated', data.data ?? data)
  } catch (e) {
    error.value = e.response?.data?.message
      ?? Object.values(e.response?.data?.errors ?? {}).flat()[0]
      ?? t('common.error')
  } finally {
    loading.value = false
  }
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
            <span class="text-xs text-gray-400">{{ t('edit.stepOf', { step }) }}</span>
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
          <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
        </div>

        <!-- Step 1: Name + Region -->
        <div v-if="step === 1" data-tour="edit-name">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step1.title') }}</h2>
          <p class="text-sm text-gray-400 mb-6">{{ t('create.step1.subtitle') }}</p>

          <label class="label">{{ t('create.fields.name') }}</label>
          <input :value="form.name" disabled class="input text-lg py-3 opacity-50 cursor-not-allowed" />
          <p class="text-xs text-yellow-500/80 mt-2">{{ t('edit.step1.nameLocked') }}</p>

          <div class="mt-6">
            <label class="label">{{ t('create.fields.description') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input v-model="form.description" class="input" :placeholder="t('create.step1.descriptionPlaceholder')" />
          </div>

          <div class="mt-6">
            <label class="label">{{ t('create.sections.region') }} <span class="normal-case text-red-500/70">*</span></label>
            <RegionSelector :regions="regions" :selected-region-id="form.knative_region_id" @select="form.knative_region_id = $event" />
            <p v-if="runtimeRegionUnavailable" class="text-xs text-red-400 mt-2">
              {{ t('runtimes.picker.regionUnavailable', { runtime: runtimeLabel(form.runtime) }) }}
            </p>
          </div>

          <div class="flex justify-end mt-8">
            <button class="btn-primary" :disabled="!canNext" @click="next">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 2: Type (locked) -->
        <div v-else-if="step === 2" data-tour="edit-type">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step2.title') }}</h2>
          <p class="text-sm text-gray-400 mb-1">{{ t('create.step2.subtitle') }}</p>
          <p class="text-xs text-yellow-500/80 mb-6">{{ t('edit.step2.locked') }}</p>

          <div class="flex flex-col gap-3">
            <div
              v-for="typeItem in types" :key="typeItem.id"
              class="text-left p-4 rounded-xl border transition-all duration-150"
              :class="form.service_type === typeItem.id ? typeItem.active : 'border-gray-800 bg-gray-900/50 opacity-40'"
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
            </div>
          </div>

          <div class="flex justify-between mt-8">
            <button class="btn-secondary" @click="back">{{ t('common.back') }}</button>
            <button class="btn-primary" @click="next">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 3: Lang + Trigger -->
        <div v-else-if="step === 3" data-tour="edit-trigger">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step3.title') }}</h2>
          <p class="text-sm text-gray-400 mb-1">{{ t('create.step3.subtitle') }}</p>
          <p class="text-xs text-gray-400 mb-5">{{ t('create.stepInfo', { name: form.name, region: selectedRegionName, type: serviceTypeLabel }) }}</p>

          <div class="space-y-5 max-h-[58vh] overflow-y-auto pr-1">
            <RuntimeSelector
              v-if="form.service_type !== 'caas'"
              :runtimes="runtimes"
              :runtime-versions="runtimeVersions"
              :selected-runtime="form.runtime"
              :selected-version="form.runtime_version"
              :readonly="true"
            />
            <p v-if="form.service_type !== 'caas'" class="text-xs text-yellow-500/80 -mt-3">{{ t('edit.step3.locked') }}</p>

            <section v-if="form.service_type === 'caas'">
              <p class="section-title">{{ t('create.sections.container') }}</p>
              <div class="flex gap-3">
                <div class="flex-1">
                  <label class="label">{{ t('create.fields.image') }}</label>
                  <input v-model="form.config.image" class="input font-mono" :placeholder="t('create.placeholders.image')" />
                </div>
                <div v-if="form.config.trigger_type !== 'schedule'" class="w-28">
                  <label class="label">{{ t('create.fields.port') }}</label>
                  <input v-model.number="form.config.port" type="number" min="1" max="65535" class="input font-mono" placeholder="80" />
                </div>
              </div>
              <div class="mt-3">
                <label class="label">{{ t('create.fields.command') }} <span class="normal-case text-gray-400">{{ t('create.fields.commandOptional') }}</span></label>
                <input v-model="form.config.command" class="input font-mono" :placeholder="t('create.placeholders.command')" />
              </div>
            </section>

            <TriggerConfig
              v-model:trigger-type="form.config.trigger_type"
              v-model:schedule="form.config.schedule"
              v-model:queue-stream="form.config.queue_stream"
              :region="selectedRegion"
            />
            <p v-if="runtimeTriggerUnsupported" class="text-xs text-red-400 -mt-3">
              {{ t('runtimes.picker.triggerUnsupportedBy', { runtime: runtimeLabel(form.runtime), trigger: t(`create.triggerTypes.${form.config.trigger_type}`) }) }}
            </p>
          </div>

          <div class="flex justify-between pt-4 mt-2 border-t border-gray-800/60">
            <button class="btn-secondary" @click="back">{{ t('common.back') }}</button>
            <button class="btn-primary" :disabled="!canNext" @click="next">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 4: Resources + Scale + Env + Submit -->
        <div v-else-if="step === 4" data-tour="edit-resources">
          <h2 class="text-lg font-semibold mb-1">{{ t('create.step4.title') }}</h2>
          <p class="text-sm text-gray-400 mb-1">{{ t('create.step4.subtitle') }}</p>
          <p class="text-xs text-gray-400 mb-5">{{ t('create.stepInfo', { name: form.name, region: selectedRegionName, type: serviceTypeLabel }) }}</p>

          <div class="space-y-5 max-h-[58vh] overflow-y-auto pr-1">
            <ResourcesEditor
              v-model:cpu="form.config.cpu"
              v-model:memory-mb="form.config.memory_mb"
              v-model:timeout-seconds="form.config.timeout_seconds"
              v-model:image-pull-policy="form.config.image_pull_policy"
              :service-type="form.service_type"
              :max-timeout="maxTimeoutFor(selectedRegion)"
            />

            <GpuConfig
              v-model="form.config.gpu"
              v-model:timeout-seconds="form.config.timeout_seconds"
              :service-type="form.service_type"
              :region-id="form.knative_region_id"
              :runtime="form.runtime"
              :runtime-version="form.runtime_version"
              :max-timeout="maxTimeoutFor(selectedRegion)"
              :scale-to-zero="form.config.scale_to_zero"
              :min-scale="form.config.min_scale"
            />

            <ScaleConfig
              v-if="form.config.trigger_type !== 'schedule'"
              v-model:scale-to-zero="form.config.scale_to_zero"
              v-model:min-scale="form.config.min_scale"
              v-model:max-scale="form.config.max_scale"
            />

            <!-- Environment variables: env.view to see them, env.edit to change them -->
            <EnvVarsEditor v-if="canEditEnv" v-model="form.config.env_vars" />
            <p v-else-if="props.project?.config?.env_vars_hidden" class="text-xs text-gray-400">{{ t('rbac.envHidden') }}</p>

            <SecretEnvVarsEditor :project-id="props.project.id" :plain-names="plainEnvNames" />

            <section>
              <p class="section-title">{{ t('edit.sections.versionNote') }}</p>
              <input v-model="form.config_message" class="input" :placeholder="t('edit.fields.versionNotePlaceholder')" />
            </section>
          </div>

          <p v-if="error" class="text-sm text-red-400 mt-3">{{ error }}</p>

          <div class="flex justify-between pt-4 mt-2 border-t border-gray-800/60">
            <button class="btn-secondary" @click="back">{{ t('common.back') }}</button>
            <button class="btn-primary" :disabled="loading || !canNext || !isDirty" @click="submit">
              {{ loading ? t('common.saving') : t('edit.step4.submit') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
