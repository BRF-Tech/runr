<script setup>
import { reactive, ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { GitFork } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { useSettingsStore } from '@/stores/settings'
import { envVarsToString, buildConfigPayload, runtimeAvailableIn, runtimeSupportsTrigger } from '@/composables/useProjectForm'
import { useRuntimesStore } from '@/stores/runtimes'
import { runtimeLabel } from '@/utils/runtimes'
import { slugError, projectSlug, maxTimeoutFor } from '@/composables/projectValidation'
import { defaultSizeFor, toMillicores } from '@/utils/instanceSizes'
import RegionSelector from '@/components/project/RegionSelector.vue'
import ResourcesEditor from '@/components/project/ResourcesEditor.vue'
import TriggerConfig from '@/components/project/TriggerConfig.vue'
import EnvVarsEditor from '@/components/project/EnvVarsEditor.vue'

const props  = defineProps({ project: { type: Object, required: true } })
const emit   = defineEmits(['created', 'close'])
const { t }  = useI18n()
const settingsStore = useSettingsStore()
const runtimesStore = useRuntimesStore()
runtimesStore.fetch()

const regions = computed(() => settingsStore.data.regions ?? [])

const loading = ref(false)
const error   = ref(null)
const step    = ref(1)

const c    = props.project.config ?? {}
const defaultSize = defaultSizeFor(props.project.service_type)
const form = reactive({
  name:              props.project.name + '-clone',
  description:       props.project.description ?? '',
  knative_region_id: props.project.knative_region_id ?? null,
  config: {
    filename:          c.filename ?? '',
    handler:           c.handler ?? 'main.handler',
    image:             c.image ?? '',
    command:           Array.isArray(c.command) ? c.command.join(' ') : (c.command ?? ''),
    image_pull_policy: c.image_pull_policy ?? 'IfNotPresent',
    port:              c.ports?.[0] ?? 80,
    cpu:               toMillicores(c.cpu) || toMillicores(defaultSize.cpu),
    memory_mb:         c.memory_mb ?? defaultSize.memory_mb,
    timeout_seconds:   c.timeout_seconds ?? 30,
    trigger_type:      c.trigger_type ?? 'http',
    schedule:          c.schedule ?? '',
    queue_stream:      c.queue_stream ?? '',
    env_vars:          envVarsToString(c.env_vars),
    scale_to_zero:     c.scale_to_zero ?? true,
    min_scale:         c.min_scale ?? 0,
    max_scale:         c.max_scale ?? 1,
  },
})

const selectedRegion = computed(() => regions.value.find(r => r.id === form.knative_region_id))
const nameSlugError = computed(() => slugError(form.name))
const canNext   = computed(() => form.name.trim().length >= 2 && !nameSlugError.value)
// The clone keeps the runtime: the target region must offer it and it must support the trigger
const runtimeEntry = computed(() => props.project.service_type === 'caas' ? null : runtimesStore.byId(props.project.runtime))
const runtimeRegionUnavailable  = computed(() => !!runtimeEntry.value && !runtimeAvailableIn(runtimeEntry.value, form.knative_region_id))
const runtimeTriggerUnsupported = computed(() => !!runtimeEntry.value && !runtimeSupportsTrigger(runtimeEntry.value, form.config.trigger_type))
const canSubmit = computed(() => !!form.knative_region_id && !runtimeRegionUnavailable.value && !runtimeTriggerUnsupported.value)

async function submit() {
  error.value   = null
  loading.value = true
  try {
    const base = {
      name:              form.name.trim(),
      service_type:      props.project.service_type,
      description:       form.description.trim() || undefined,
      knative_region_id: form.knative_region_id || undefined,
    }
    if (props.project.service_type !== 'caas') {
      base.runtime = props.project.runtime
      base.runtime_version = props.project.runtime_version || undefined
    }
    const { data } = await projectsApi.create({ ...base, config: buildConfigPayload(form.config, props.project.service_type) })
    emit('created', data.data ?? data)
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
                v-for="n in 2"
                :key="n"
                class="h-1.5 rounded-full transition-all duration-300"
                :class="[n < step ? 'w-4' : n === step ? 'w-6' : 'w-1.5']"
                :style="n <= step ? 'background-color:rgb(var(--brand-600))' : 'background-color:#374151'"
              />
            </div>
            <span class="text-xs text-gray-400">{{ t('clone.stepOf', { step }) }}</span>
          </div>
          <button class="text-gray-400 hover:text-gray-100 transition-colors" @click="emit('close')">{{ t('common.close') }}</button>
        </div>

        <!-- Step 1: Name -->
        <div v-if="step === 1">
          <h2 class="text-lg font-semibold mb-1">{{ t('clone.title') }}</h2>
          <p class="text-sm text-gray-400 mb-1">{{ t('clone.subtitle') }}</p>
          <p class="text-xs text-gray-400 mb-6 font-mono">
            {{ t('clone.sourceLabel') }}: <span class="text-gray-400">{{ project.name }}</span>
            <span class="ml-2 px-1.5 py-0.5 rounded bg-gray-800 text-[10px]">{{ project.service_type }}</span>
            <span v-if="project.runtime" class="ml-1 px-1.5 py-0.5 rounded bg-gray-800 text-[10px]">{{ runtimeLabel(project.runtime) }}</span>
            <span v-if="project.runtime_version" class="ml-1 px-1.5 py-0.5 rounded bg-gray-800 text-[10px]">{{ project.runtime_version }}</span>
          </p>

          <label class="label">{{ t('create.fields.name') }}</label>
          <input
            v-model="form.name"
            class="input text-lg py-3"
            autofocus
            @keyup.enter="canNext && (step = 2)"
          />
          <p v-if="nameSlugError" class="text-sm text-red-400 mt-1.5">{{ t(nameSlugError.key, nameSlugError.params) }}</p>
          <p v-else class="text-xs text-gray-400 mt-2 font-mono">
            slug: {{ projectSlug(form.name) || '—' }}
          </p>

          <div class="mt-5">
            <label class="label">{{ t('create.fields.description') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input v-model="form.description" class="input" />
          </div>

          <div class="flex justify-end mt-8">
            <button class="btn-primary" :disabled="!canNext" @click="step = 2">{{ t('common.next') }}</button>
          </div>
        </div>

        <!-- Step 2: Config -->
        <div v-else>
          <h2 class="text-lg font-semibold mb-1">{{ t('clone.configTitle') }}</h2>
          <p class="text-sm text-gray-400 mb-5">{{ t('clone.configSubtitle') }}</p>

          <div class="space-y-5 max-h-[58vh] overflow-y-auto pr-1">

            <!-- Entrypoint -->
            <section>
              <p class="section-title">{{ t('create.sections.entrypoint') }}</p>
              <div v-if="project.service_type === 'xaas'">
                <label class="label">{{ t('create.fields.filename') }}</label>
                <input v-model="form.config.filename" class="input font-mono" />
              </div>
              <div v-if="project.service_type === 'faas'">
                <label class="label">{{ t('create.fields.handler') }}</label>
                <input v-model="form.config.handler" class="input font-mono" />
              </div>
              <template v-if="project.service_type === 'caas'">
                <div class="flex gap-3">
                  <div class="flex-1">
                    <label class="label">{{ t('create.fields.image') }}</label>
                    <input v-model="form.config.image" class="input font-mono" />
                  </div>
                  <div v-if="form.config.trigger_type !== 'schedule'" class="w-28">
                    <label class="label">{{ t('create.fields.port') }}</label>
                    <input v-model.number="form.config.port" type="number" min="1" max="65535" class="input font-mono" placeholder="80" />
                  </div>
                </div>
                <div class="mt-3">
                  <label class="label">{{ t('create.fields.command') }} <span class="normal-case text-gray-400">{{ t('create.fields.commandOptional') }}</span></label>
                  <input v-model="form.config.command" class="input font-mono" />
                </div>
              </template>
            </section>

            <!-- Trigger -->
            <TriggerConfig
              v-model:trigger-type="form.config.trigger_type"
              v-model:schedule="form.config.schedule"
              v-model:queue-stream="form.config.queue_stream"
              :region="selectedRegion"
              :show-tooltips="false"
            />
            <p v-if="runtimeTriggerUnsupported" class="text-xs text-red-400 -mt-3">
              {{ t('runtimes.picker.triggerUnsupportedBy', { runtime: runtimeLabel(project.runtime), trigger: t(`create.triggerTypes.${form.config.trigger_type}`) }) }}
            </p>

            <!-- Resources -->
            <ResourcesEditor
              v-model:cpu="form.config.cpu"
              v-model:memory-mb="form.config.memory_mb"
              v-model:timeout-seconds="form.config.timeout_seconds"
              v-model:image-pull-policy="form.config.image_pull_policy"
              :service-type="project.service_type"
              :max-timeout="maxTimeoutFor(selectedRegion)"
            />

            <EnvVarsEditor v-model="form.config.env_vars" />

            <!-- Region -->
            <section>
              <p class="section-title">{{ t('create.sections.region') }} <span class="normal-case text-red-500/70 font-normal">*</span></p>
              <RegionSelector
                :regions="regions"
                :selected-region-id="form.knative_region_id"
                @select="form.knative_region_id = $event"
              />
              <p v-if="runtimeRegionUnavailable" class="text-xs text-red-400 mt-2">
                {{ t('runtimes.picker.regionUnavailable', { runtime: runtimeLabel(project.runtime) }) }}
              </p>
            </section>
          </div>

          <p v-if="error" class="text-sm text-red-400 mt-3">{{ error }}</p>

          <div class="flex justify-between pt-4 mt-2 border-t border-gray-800/60">
            <button class="btn-secondary" @click="step = 1">{{ t('common.back') }}</button>
            <button class="btn-primary flex items-center gap-1.5" :disabled="loading || !canSubmit" @click="submit">
              <GitFork v-if="!loading" class="w-3.5 h-3.5" />
              {{ loading ? t('clone.submitting') : t('clone.submit') }}
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
