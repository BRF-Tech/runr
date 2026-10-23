<script setup>
// Deploy strategy of a function: direct (the default), blue-green or canary with their options.
// Saved on its own (PUT /projects/{id}/deploy-strategy): it changes how the next deploys roll
// out, not what they run, so it creates no version — unlike the project config.
// With `environment` (a name other than production): that environment's — the project's settings
// unless it sets its own; saving stores only what changed, "Use the project's" drops what it sets
// (docs/deploy-strategies.md#environments).
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import GitToggle from '@/components/git/GitToggle.vue'
import {
  STEP_PRESETS, LIMITS, isEligibleTrigger, strategyForm, strategyPayload, strategyChanges, validateStrategy, serverFieldErrors,
} from '@/utils/deployStrategy'
import { Rocket, Columns2, Bird, Plus, X, AlertTriangle, Activity, Info } from 'lucide-vue-next'

const props = defineProps({
  project:     { type: Object, required: true },
  // Edit modal: the trigger chosen in the form (saved later), to explain what it changes
  triggerType: { type: String, default: null },
  // Show a button to the project's Traces tab (where tracing is turned on)
  tracingLink: { type: Boolean, default: false },
  // One of the project's environments (a name; null or production: the project's own settings)
  environment: { type: String, default: null },
})
const emit = defineEmits(['saved', 'open-tracing'])

const { t } = useI18n()
const toast = useToast()

const OPTIONS = [
  { id: 'direct',     icon: Rocket,   style: 'background:rgb(var(--brand-600)/0.15);border-color:rgb(var(--brand-500)/0.5)' },
  { id: 'blue_green', icon: Columns2, style: 'background:rgb(14 165 233/0.15);border-color:rgb(14 165 233/0.5)' },
  { id: 'canary',     icon: Bird,     style: 'background:rgb(245 158 11/0.15);border-color:rgb(245 158 11/0.5)' },
]

// Validation keys shown next to a field; any other error goes under the form
const SHOWN_FIELDS = /^(strategy|blue_green\.(health_path|health_checks|keep_previous_minutes)|canary\.(steps(\.\d+)?|interval_minutes|error_rate_threshold|p95_threshold_ms))$/

const settings     = ref(null)   // GET answer: settings + eligible, trigger_type, tracing, defaults
const form         = ref(null)
const initial      = ref('')
const loading      = ref(false)
const loadError    = ref(null)
const saving       = ref(false)
const serverErrors = ref({})
const generalError = ref(null)

// The environment whose settings these are (null: the project's, production's)
const envName = computed(() => (props.environment && props.environment !== 'production' ? props.environment : null))
// An environment's settings are part of the environment: environments.manage too
const canUpdate = computed(() => {
  const permissions = usePermissionsStore()
  return permissions.can('projects.update', props.project.id) && (!envName.value || permissions.can('environments.manage', props.project.id))
})
const projectStrategyName = computed(() => t(`deployStrategy.names.${settings.value?.project ?? 'direct'}`, settings.value?.project ?? 'direct'))
const eligible  = computed(() => settings.value?.eligible !== false)
const locked    = computed(() => !canUpdate.value || !eligible.value || saving.value)
const triggerLabel = computed(() => {
  const trigger = settings.value?.trigger_type ?? props.project.config?.trigger_type ?? 'http'
  return t(`create.triggerTypes.${trigger}`, trigger)
})
// The edit form switched the trigger (not saved yet)
const triggerChanged = computed(() => !!props.triggerType && !!settings.value?.trigger_type
  && props.triggerType !== settings.value.trigger_type
  && isEligibleTrigger(props.triggerType) !== isEligibleTrigger(settings.value.trigger_type))

const clientErrors = computed(() => form.value ? validateStrategy(form.value) : {})
const dirty   = computed(() => !!form.value && JSON.stringify(form.value) !== initial.value)
// An environment saves what changed only: nothing changed in the chosen strategy, nothing to save
const payload = computed(() => {
  if (!form.value) return {}
  return envName.value ? strategyChanges(JSON.parse(initial.value), form.value) : strategyPayload(form.value)
})
const canSave = computed(() => canUpdate.value && eligible.value && dirty.value && !saving.value
  && !Object.keys(clientErrors.value).length && Object.keys(payload.value).length > 0)

function errorFor(field) {
  if (serverErrors.value[field]) return serverErrors.value[field]
  const e = clientErrors.value[field]
  return e ? t(e.key, e.params ?? {}) : null
}

function apply(data) {
  settings.value = data
  form.value     = strategyForm(data)
  initial.value  = JSON.stringify(form.value)
}

async function load() {
  loading.value   = true
  loadError.value = null
  try {
    const { data } = envName.value
      ? await projectsApi.deployStrategy(props.project.id, envName.value)
      : await projectsApi.deployStrategy(props.project.id)
    const body = data?.data
    if (!body || typeof body !== 'object' || Array.isArray(body)) throw new Error('no settings')
    apply(body)
  } catch (e) {
    loadError.value = e?.response?.data?.message ?? t('deployStrategy.loadError')
  } finally {
    loading.value = false
  }
}

// Stale server errors go once the user changes the form
watch(form, () => { serverErrors.value = {}; generalError.value = null }, { deep: true })

function choose(id) {
  if (locked.value) return
  form.value.strategy = id
}

function addStep() {
  const steps = form.value.canary.steps
  if (steps.length >= LIMITS.steps.max) return
  const last = Number(steps[steps.length - 1]) || 0
  if (last < 100) steps.push(Math.min(100, last + 10))
  else steps.splice(steps.length - 1, 0, Math.round(((Number(steps[steps.length - 2]) || 0) + 100) / 2))
}

function removeStep(i) {
  if (form.value.canary.steps.length > 1) form.value.canary.steps.splice(i, 1)
}

function usePreset(preset) {
  if (!locked.value) form.value.canary.steps = [...preset]
}

function reset() {
  form.value = JSON.parse(initial.value)
}

async function save() {
  if (!canSave.value) return
  saving.value       = true
  generalError.value = null
  try {
    const { data } = envName.value
      ? await projectsApi.updateDeployStrategy(props.project.id, payload.value, envName.value)
      : await projectsApi.updateDeployStrategy(props.project.id, payload.value)
    apply(data?.data ?? data)
    toast.success(t('deployStrategy.saved', { strategy: t(`deployStrategy.names.${settings.value.strategy}`) }))
    emit('saved', settings.value)
  } catch (e) {
    // 422: each message next to its field (the keys are the form's), until the next change
    const fields = e?.response?.status === 422 ? serverFieldErrors(e) : {}
    serverErrors.value = fields
    if (!Object.keys(fields).some(f => SHOWN_FIELDS.test(f))) generalError.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

// The environment follows the project's settings again (what it set of its own is dropped)
async function useProjectSettings() {
  if (!envName.value || !canUpdate.value || saving.value) return
  saving.value       = true
  generalError.value = null
  try {
    const { data } = await projectsApi.updateDeployStrategy(props.project.id, { reset: true }, envName.value)
    apply(data?.data ?? data)
    toast.success(t('deployStrategy.environment.reset', { env: envName.value, strategy: t(`deployStrategy.names.${settings.value.strategy}`) }))
    emit('saved', settings.value)
  } catch (e) {
    generalError.value = e?.response?.data?.message ?? t('common.error')
  } finally {
    saving.value = false
  }
}

// Another environment: its settings
watch(() => props.environment, (next, previous) => { if (next !== previous) load() })

onMounted(load)
defineExpose({ load, save, useProjectSettings })
</script>

<template>
  <section data-test="deploy-strategy-settings">
    <p class="section-title flex items-center gap-1.5">{{ t('deployStrategy.title') }}</p>
    <p class="text-xs text-gray-400 mb-3">{{ envName ? t('deployStrategy.environment.hint', { env: envName }) : t('deployStrategy.hint') }}</p>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-xs text-red-400" data-test="deploy-strategy-load-error">{{ loadError }}</p>

    <template v-else-if="form">
      <!-- Queue / schedule functions (and previews) always deploy directly -->
      <div
        v-if="!eligible"
        class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-3 py-2.5 mb-3"
        data-test="deploy-strategy-ineligible"
      >
        <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
        <p class="text-xs text-yellow-300">{{ t('deployStrategy.ineligible', { trigger: triggerLabel }) }}</p>
      </div>
      <p v-else-if="triggerChanged" class="text-xs text-yellow-400 mb-3" data-test="deploy-strategy-trigger-changed">
        {{ t('deployStrategy.triggerChanged') }}
      </p>
      <p v-if="!canUpdate" class="text-xs text-gray-400 mb-3" data-test="deploy-strategy-readonly">
        {{ t('rbac.readOnlyNotice', { permission: envName ? 'environments.manage' : 'projects.update' }) }}
      </p>

      <!-- An environment: the project's settings, or its own over them -->
      <div
        v-if="envName"
        class="flex flex-wrap items-center gap-2 rounded-md border border-gray-800 bg-gray-900/60 px-3 py-2 mb-3 text-xs"
        data-test="deploy-strategy-environment"
      >
        <span v-if="settings?.inherited" class="text-gray-300" data-test="deploy-strategy-inherited">
          {{ t('deployStrategy.environment.inherited', { env: envName, strategy: projectStrategyName }) }}
        </span>
        <template v-else>
          <span class="text-brand-400" data-test="deploy-strategy-own">
            {{ t('deployStrategy.environment.own', { env: envName, strategy: projectStrategyName }) }}
          </span>
          <button
            v-if="canUpdate"
            type="button"
            class="btn-secondary text-xs ml-auto"
            :disabled="saving"
            data-test="deploy-strategy-use-project"
            @click="useProjectSettings"
          >{{ t('deployStrategy.environment.useProject') }}</button>
        </template>
      </div>

      <!-- Strategy -->
      <div class="grid grid-cols-3 gap-2" role="radiogroup" :aria-label="t('deployStrategy.title')">
        <button
          v-for="opt in OPTIONS" :key="opt.id"
          type="button" role="radio"
          :aria-checked="form.strategy === opt.id"
          :disabled="locked"
          class="flex flex-col items-start gap-1.5 p-3 rounded-lg border text-left transition-all disabled:cursor-not-allowed"
          :class="[
            form.strategy === opt.id ? 'text-gray-100' : 'bg-gray-900/60 border-gray-700/60 text-gray-400 hover:border-gray-500 hover:text-gray-100',
            !eligible ? 'opacity-50' : '',
          ]"
          :style="form.strategy === opt.id ? opt.style : ''"
          :data-test="`deploy-strategy-${opt.id}`"
          @click="choose(opt.id)"
        >
          <span class="flex items-center gap-1.5 font-mono text-xs font-medium">
            <component :is="opt.icon" class="w-4 h-4" />{{ t(`deployStrategy.names.${opt.id}`) }}
          </span>
          <span class="text-[11px] leading-snug opacity-70">{{ t(`deployStrategy.descriptions.${opt.id}`) }}</span>
        </button>
      </div>
      <p v-if="errorFor('strategy')" class="text-xs text-red-400 mt-1.5" data-test="deploy-strategy-error-strategy">{{ errorFor('strategy') }}</p>

      <!-- Blue-green options -->
      <fieldset v-if="form.strategy === 'blue_green'" :disabled="locked" class="mt-4 space-y-3" data-test="blue-green-options">
        <GitToggle
          v-model="form.blue_green.auto_promote"
          :label="t('deployStrategy.blueGreen.autoPromote')"
          :hint="t('deployStrategy.blueGreen.autoPromoteHint')"
          :disabled="locked"
          test-id="blue-green-auto-promote"
        />
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="label">{{ t('deployStrategy.blueGreen.healthPath') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input
              v-model="form.blue_green.health_path" class="input font-mono" placeholder="/healthz" maxlength="255"
              autocomplete="off" spellcheck="false" data-test="blue-green-health-path"
              :class="errorFor('blue_green.health_path') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('blue_green.health_path')" class="text-[11px] text-red-400 mt-1" data-test="error-blue_green.health_path">{{ errorFor('blue_green.health_path') }}</p>
            <p v-else class="text-[11px] text-gray-500 mt-1">{{ t('deployStrategy.blueGreen.healthPathHint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('deployStrategy.blueGreen.healthChecks') }}</label>
            <input
              v-model.number="form.blue_green.health_checks" type="number" min="1" max="20" class="input font-mono"
              data-test="blue-green-health-checks" :class="errorFor('blue_green.health_checks') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('blue_green.health_checks')" class="text-[11px] text-red-400 mt-1" data-test="error-blue_green.health_checks">{{ errorFor('blue_green.health_checks') }}</p>
            <p v-else class="text-[11px] text-gray-500 mt-1">{{ t('deployStrategy.blueGreen.healthChecksHint') }}</p>
          </div>
          <div>
            <label class="label">{{ t('deployStrategy.blueGreen.keepPrevious') }}</label>
            <input
              v-model.number="form.blue_green.keep_previous_minutes" type="number" min="0" max="1440" class="input font-mono"
              data-test="blue-green-keep-previous" :class="errorFor('blue_green.keep_previous_minutes') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('blue_green.keep_previous_minutes')" class="text-[11px] text-red-400 mt-1" data-test="error-blue_green.keep_previous_minutes">{{ errorFor('blue_green.keep_previous_minutes') }}</p>
            <p v-else class="text-[11px] text-gray-500 mt-1">{{ t('deployStrategy.blueGreen.keepPreviousHint') }}</p>
          </div>
        </div>
      </fieldset>

      <!-- Canary options -->
      <fieldset v-if="form.strategy === 'canary'" :disabled="locked" class="mt-4 space-y-3" data-test="canary-options">
        <div>
          <label class="label">{{ t('deployStrategy.canary.steps') }}</label>
          <div class="flex flex-wrap items-center gap-1.5">
            <template v-for="(step, i) in form.canary.steps" :key="i">
              <span v-if="i" class="text-gray-500 text-xs">→</span>
              <div class="relative flex items-center">
                <input
                  v-model.number="form.canary.steps[i]" type="number" min="1" max="100"
                  class="input font-mono text-center w-20! pr-6!"
                  :class="errorFor(`canary.steps.${i}`) ? 'border-red-500/70' : ''"
                  :title="errorFor(`canary.steps.${i}`) ?? undefined"
                  :aria-label="t('deployStrategy.canary.stepLabel', { n: i + 1 })"
                  :data-test="`canary-step-${i}`"
                />
                <span class="absolute right-2 text-[11px] text-gray-400 pointer-events-none">%</span>
                <button
                  v-if="form.canary.steps.length > 1 && !locked" type="button"
                  class="absolute -top-1.5 -right-1.5 w-4 h-4 rounded-full bg-gray-700 text-gray-300 hover:bg-red-700 hover:text-white flex items-center justify-center"
                  :title="t('deployStrategy.canary.removeStep')" :data-test="`canary-remove-step-${i}`"
                  @click="removeStep(i)"
                ><X class="w-2.5 h-2.5" /></button>
              </div>
            </template>
            <button
              v-if="form.canary.steps.length < LIMITS.steps.max && !locked" type="button"
              class="inline-flex items-center gap-1 text-xs px-2 py-1.5 rounded-md border border-gray-700 text-gray-400 hover:text-gray-100 hover:border-gray-500 transition-colors"
              data-test="canary-add-step" @click="addStep"
            ><Plus class="w-3 h-3" />{{ t('deployStrategy.canary.addStep') }}</button>
          </div>
          <p v-if="errorFor('canary.steps')" class="text-[11px] text-red-400 mt-1" data-test="error-canary.steps">{{ errorFor('canary.steps') }}</p>
          <template v-for="(step, i) in form.canary.steps" :key="`e${i}`">
            <p v-if="errorFor(`canary.steps.${i}`) && errorFor(`canary.steps.${i}`) !== errorFor('canary.steps')" class="text-[11px] text-red-400 mt-1" :data-test="`error-canary.steps.${i}`">
              {{ t('deployStrategy.canary.stepLabel', { n: i + 1 }) }}: {{ errorFor(`canary.steps.${i}`) }}
            </p>
          </template>
          <div class="flex flex-wrap items-center gap-1.5 mt-2">
            <span class="text-[11px] text-gray-500">{{ t('deployStrategy.canary.presets') }}</span>
            <button
              v-for="preset in STEP_PRESETS" :key="preset.join('-')" type="button" :disabled="locked"
              class="text-[11px] font-mono px-2 py-0.5 rounded-sm border border-gray-800 text-gray-400 hover:text-gray-100 hover:border-gray-600 transition-colors disabled:opacity-50"
              :data-test="`canary-preset-${preset.join('-')}`" @click="usePreset(preset)"
            >{{ preset.join(' → ') }} %</button>
          </div>
          <p class="text-[11px] text-gray-500 mt-1.5">{{ t('deployStrategy.canary.stepsHint') }}</p>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="label">{{ t('deployStrategy.canary.interval') }}</label>
            <input
              v-model.number="form.canary.interval_minutes" type="number" min="1" max="1440" class="input font-mono"
              data-test="canary-interval" :class="errorFor('canary.interval_minutes') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('canary.interval_minutes')" class="text-[11px] text-red-400 mt-1" data-test="error-canary.interval_minutes">{{ errorFor('canary.interval_minutes') }}</p>
          </div>
          <div>
            <label class="label">{{ t('deployStrategy.canary.errorRate') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input
              v-model="form.canary.error_rate_threshold" type="number" min="0" max="100" step="0.1" class="input font-mono"
              :placeholder="t('deployStrategy.canary.notChecked')"
              data-test="canary-error-rate" :class="errorFor('canary.error_rate_threshold') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('canary.error_rate_threshold')" class="text-[11px] text-red-400 mt-1" data-test="error-canary.error_rate_threshold">{{ errorFor('canary.error_rate_threshold') }}</p>
          </div>
          <div>
            <label class="label">{{ t('deployStrategy.canary.p95') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input
              v-model="form.canary.p95_threshold_ms" type="number" min="1" max="600000" class="input font-mono"
              :placeholder="t('deployStrategy.canary.notChecked')"
              data-test="canary-p95" :class="errorFor('canary.p95_threshold_ms') ? 'border-red-500/70' : ''"
            />
            <p v-if="errorFor('canary.p95_threshold_ms')" class="text-[11px] text-red-400 mt-1" data-test="error-canary.p95_threshold_ms">{{ errorFor('canary.p95_threshold_ms') }}</p>
          </div>
        </div>

        <!-- Thresholds are judged on the project's span metrics: without tracing, time only -->
        <div
          v-if="settings.tracing === false"
          class="flex items-start gap-2.5 bg-sky-900/20 border border-sky-800/40 rounded-md px-3 py-2.5"
          data-test="canary-no-tracing"
        >
          <Activity class="w-4 h-4 text-sky-400 mt-0.5 shrink-0" />
          <div class="text-xs text-sky-200 space-y-1.5">
            <p>{{ t('deployStrategy.canary.noTracing') }}</p>
            <button
              v-if="tracingLink" type="button" class="text-sky-300 hover:text-sky-100 underline underline-offset-2"
              data-test="canary-open-tracing" @click="emit('open-tracing')"
            >{{ t('deployStrategy.canary.openTracing') }}</button>
          </div>
        </div>
        <p v-else class="flex items-start gap-1.5 text-[11px] text-gray-500">
          <Info class="w-3.5 h-3.5 shrink-0 mt-px" />{{ t('deployStrategy.canary.thresholdsHint') }}
        </p>
      </fieldset>

      <p v-if="form.strategy !== 'direct' && eligible" class="text-[11px] text-gray-500 mt-3">{{ t('deployStrategy.firstDeployHint') }}</p>

      <p v-if="generalError" class="text-xs text-red-400 mt-3" data-test="deploy-strategy-error">{{ generalError }}</p>

      <div v-if="canUpdate && eligible" class="flex justify-end gap-2 mt-4">
        <button v-if="dirty" type="button" class="btn-secondary text-xs" :disabled="saving" data-test="deploy-strategy-reset" @click="reset">
          {{ t('deployStrategy.reset') }}
        </button>
        <button type="button" class="btn-primary text-xs" :disabled="!canSave" data-test="deploy-strategy-save" @click="save">
          {{ saving ? t('common.saving') : t('deployStrategy.save') }}
        </button>
      </div>
    </template>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
</style>
