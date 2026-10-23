<script setup>
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import { environmentsApi } from '@/api/environments'
import { useSettingsStore } from '@/stores/settings'
import TermText from '@/components/TermText.vue'
import WarmerSchedule from '@/components/project/WarmerSchedule.vue'
import { defaultWarmerRows, scheduleForm, scaleSchedulePayload, warmerScheduleError } from '@/utils/warmSchedule'

/**
 * Create or change an environment of a project (docs/environments.md): its name (fixed once
 * created), region, the git branch that deploys it, approval of promotions into it, whether the
 * project's secret variables reach it, and its overrides over the project's config — env vars (an
 * empty value removes the project's variable there), resources and scale, and its timed warmer
 * (docs/scaling.md#timed-warmer): the project's schedule, one of its own, or none. Overrides the
 * form does not show (schedule, queue stream, deploy strategy) are kept. The production
 * environment (the project itself) only takes the approval setting here.
 */
const props = defineProps({
  projectId:   { type: String, required: true },
  // null: a new environment
  environment: { type: Object, default: null },
  // Env vars may only be changed with env.edit
  canEditEnv:  { type: Boolean, default: true },
  // The project (its config: the timed warmer's defaults, size and trigger)
  project:     { type: Object, default: null },
})

const emit = defineEmits(['saved', 'close'])

const { t } = useI18n()
const settingsStore = useSettingsStore()

const isNew     = computed(() => !props.environment)
const isPrimary = computed(() => !!props.environment?.is_primary)
const overrides = props.environment?.overrides ?? {}

const form = ref({
  name:              props.environment?.name ?? '',
  knative_region_id: props.environment?.own_region ? props.environment.knative_region_id : '',
  git_branch:        props.environment?.git_branch ?? '',
  requires_approval: props.environment?.requires_approval ?? false,
  inherit_secrets:   props.environment?.inherit_secrets ?? true,
  auto_deploy:       props.environment?.auto_deploy ?? true,
  cpu:               overrides.cpu ?? '',
  memory_mb:         overrides.memory_mb ?? '',
  min_scale:         overrides.min_scale ?? '',
  max_scale:         overrides.max_scale ?? '',
  timeout_seconds:   overrides.timeout_seconds ?? '',
  // '' = the project's, 'on' / 'off'
  scale_to_zero:     overrides.scale_to_zero === undefined || overrides.scale_to_zero === null ? '' : (overrides.scale_to_zero ? 'on' : 'off'),
})
// Timed warmer: 'inherit' (the project's schedule), 'own' (rows of its own) or 'off' (none here)
const projectConfig = computed(() => props.project?.config ?? {})
const projectRows = computed(() => scheduleForm(projectConfig.value.scale_schedule))
const warmerMode = ref(!Array.isArray(overrides.scale_schedule) ? 'inherit' : (overrides.scale_schedule.length ? 'own' : 'off'))
const warmerRows = ref(scheduleForm(overrides.scale_schedule) ?? projectRows.value ?? defaultWarmerRows(Number(projectConfig.value.max_scale ?? 1)))
const showWarmer = computed(() => (projectConfig.value.trigger_type ?? 'http') !== 'schedule')
const warmerMaxScale = computed(() => num(form.value.max_scale) ?? Number(projectConfig.value.max_scale ?? 1))
const warmerInvalid = computed(() => showWarmer.value && warmerMode.value === 'own' && !!warmerScheduleError(warmerRows.value, warmerMaxScale.value))
// Overrides the form does not edit: kept as they are
const KEPT = ['schedule', 'queue_stream', 'deploy_strategy']

// Env var overrides as rows; a null value (removes the project's variable) shows as empty
const envRows = ref(Object.entries(overrides.env_vars ?? {}).map(([name, value]) => ({ name, value: value ?? '' })))
const envHidden = !!overrides.env_vars_hidden

const saving = ref(false)
const errors = ref({})
const error  = ref(null)

const regions = computed(() => (settingsStore.data.regions ?? []).filter(r => !r.is_demo))
const overridesLocked = computed(() => !isNew.value && (envHidden || !props.canEditEnv))

function addEnvRow() { envRows.value = [...envRows.value, { name: '', value: '' }] }
function removeEnvRow(i) { envRows.value = envRows.value.filter((_, j) => j !== i) }

const num = v => (v === '' || v === null || v === undefined ? null : Number(v))

function payload() {
  if (isPrimary.value) return { requires_approval: form.value.requires_approval }

  const o = {}
  for (const key of KEPT) {
    if (overrides[key] !== undefined && overrides[key] !== null) o[key] = overrides[key]
  }
  if (showWarmer.value && warmerMode.value === 'own') o.scale_schedule = scaleSchedulePayload(warmerRows.value) ?? []
  else if (showWarmer.value && warmerMode.value === 'off') o.scale_schedule = []
  else if (!showWarmer.value && Array.isArray(overrides.scale_schedule)) o.scale_schedule = overrides.scale_schedule
  if (form.value.cpu) o.cpu = String(form.value.cpu)
  for (const key of ['memory_mb', 'min_scale', 'max_scale', 'timeout_seconds']) {
    if (num(form.value[key]) !== null) o[key] = num(form.value[key])
  }
  if (form.value.scale_to_zero) o.scale_to_zero = form.value.scale_to_zero === 'on'
  if (props.canEditEnv && !envHidden) {
    const env = {}
    for (const row of envRows.value) {
      const name = row.name.trim()
      if (name) env[name] = row.value === '' ? null : row.value
    }
    if (Object.keys(env).length || !isNew.value) o.env_vars = env
  }

  const data = {
    knative_region_id: form.value.knative_region_id || null,
    git_branch:        form.value.git_branch.trim() || null,
    requires_approval: form.value.requires_approval,
    inherit_secrets:   form.value.inherit_secrets,
    auto_deploy:       form.value.auto_deploy,
  }
  // The overrides are saved as a whole: without the env vars at hand (hidden, or no env.edit)
  // an existing environment keeps the ones it has
  if (isNew.value || !overridesLocked.value) data.overrides = o
  if (isNew.value) data.name = form.value.name.trim().toLowerCase()

  return data
}

async function save() {
  if (saving.value) return
  saving.value = true
  errors.value = {}
  error.value  = null
  try {
    const { data } = isNew.value
      ? await environmentsApi.create(props.projectId, payload())
      : await environmentsApi.update(props.projectId, props.environment.is_primary ? 'production' : props.environment.name, payload())
    emit('saved', data.data ?? data)
  } catch (e) {
    const body = e.response?.data ?? {}
    errors.value = body.errors ?? {}
    error.value  = Object.keys(errors.value).length ? null : (body.message ?? t('common.error'))
  } finally {
    saving.value = false
  }
}

const fieldError = key => errors.value[key]?.[0] ?? errors.value[`overrides.${key}`]?.[0] ?? null
// The API's errors of single warmer rows (overrides.scale_schedule.N.cron / .min_scale)
const warmerRowErrors = computed(() => Object.fromEntries(Object.entries(errors.value)
  .filter(([key]) => key.startsWith('overrides.scale_schedule.'))
  .map(([key, list]) => [key, list?.[0]])))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="environment-form">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('close')" />
    <form class="relative card w-full max-w-2xl max-h-[90vh] overflow-y-auto" @submit.prevent="save">
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-semibold">{{ isNew ? t('environments.create') : t('environments.editTitle', { name: environment.name }) }}</h2>
        <button type="button" class="text-gray-400 hover:text-gray-100" @click="$emit('close')">✕</button>
      </div>

      <div class="space-y-4">
        <!-- Name (fixed once created) -->
        <div v-if="isNew">
          <label class="label mb-1.5" for="env-name">{{ t('environments.form.name') }}</label>
          <input id="env-name" v-model="form.name" class="input w-full font-mono" maxlength="20" placeholder="staging" required data-testid="env-name" />
          <p class="text-[11px] text-gray-400 mt-1">{{ t('environments.form.nameHint') }}</p>
          <p v-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
        </div>

        <label class="flex items-center gap-2 text-sm cursor-pointer">
          <input v-model="form.requires_approval" type="checkbox" class="accent-brand-500" data-testid="env-requires-approval" />
          {{ t('environments.form.requiresApproval') }}
        </label>

        <template v-if="!isPrimary">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="label mb-1.5" for="env-region">{{ t('environments.form.region') }}</label>
              <select id="env-region" v-model="form.knative_region_id" class="input w-full">
                <option value="">{{ t('environments.form.regionDefault') }}</option>
                <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
              </select>
              <p v-if="fieldError('knative_region_id')" class="text-xs text-red-400 mt-1">{{ fieldError('knative_region_id') }}</p>
            </div>
            <div>
              <label class="label mb-1.5" for="env-branch"><TermText path="environments.form.gitBranch" /></label>
              <input id="env-branch" v-model="form.git_branch" class="input w-full font-mono" maxlength="255" placeholder="develop" data-testid="env-branch" />
              <p class="text-[11px] text-gray-400 mt-1">{{ t('environments.form.gitBranchHint') }}</p>
              <p v-if="fieldError('git_branch')" class="text-xs text-red-400 mt-1">{{ fieldError('git_branch') }}</p>
            </div>
          </div>

          <div class="flex items-center gap-6 flex-wrap">
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="form.auto_deploy" type="checkbox" class="accent-brand-500" />
              {{ t('environments.form.autoDeploy') }}
            </label>
            <label class="flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="form.inherit_secrets" type="checkbox" class="accent-brand-500" />
              {{ t('environments.form.inheritSecrets') }}
            </label>
          </div>

          <!-- Overrides of the project's config -->
          <div class="pt-2 border-t border-gray-800/60">
            <h3 class="text-sm font-semibold text-gray-200">{{ t('environments.form.overrides') }}</h3>
            <p class="text-[11px] text-gray-400 mt-0.5 mb-3">{{ overridesLocked ? t('environments.form.overridesLocked') : t('environments.form.overridesHint') }}</p>

            <div class="grid grid-cols-3 gap-3">
              <div>
                <label class="label mb-1" for="env-cpu">{{ t('environments.form.cpu') }}</label>
                <input id="env-cpu" v-model="form.cpu" class="input w-full font-mono" placeholder="250m" />
                <p v-if="fieldError('cpu')" class="text-xs text-red-400 mt-1">{{ fieldError('cpu') }}</p>
              </div>
              <div>
                <label class="label mb-1" for="env-memory">{{ t('environments.form.memory') }}</label>
                <input id="env-memory" v-model="form.memory_mb" type="number" min="64" class="input w-full" />
                <p v-if="fieldError('memory_mb')" class="text-xs text-red-400 mt-1">{{ fieldError('memory_mb') }}</p>
              </div>
              <div>
                <label class="label mb-1" for="env-timeout">{{ t('environments.form.timeout') }}</label>
                <input id="env-timeout" v-model="form.timeout_seconds" type="number" min="1" class="input w-full" />
                <p v-if="fieldError('timeout_seconds')" class="text-xs text-red-400 mt-1">{{ fieldError('timeout_seconds') }}</p>
              </div>
              <div>
                <label class="label mb-1" for="env-min">{{ t('environments.form.minScale') }}</label>
                <input id="env-min" v-model="form.min_scale" type="number" min="0" max="10" class="input w-full" data-testid="env-min-scale" />
                <p v-if="fieldError('min_scale')" class="text-xs text-red-400 mt-1">{{ fieldError('min_scale') }}</p>
              </div>
              <div>
                <label class="label mb-1" for="env-max">{{ t('environments.form.maxScale') }}</label>
                <input id="env-max" v-model="form.max_scale" type="number" min="1" max="1000" class="input w-full" />
                <p v-if="fieldError('max_scale')" class="text-xs text-red-400 mt-1">{{ fieldError('max_scale') }}</p>
              </div>
              <div>
                <label class="label mb-1" for="env-stz">{{ t('environments.form.scaleToZero') }}</label>
                <select id="env-stz" v-model="form.scale_to_zero" class="input w-full">
                  <option value="">{{ t('environments.form.inherit') }}</option>
                  <option value="on">{{ t('environments.form.on') }}</option>
                  <option value="off">{{ t('environments.form.off') }}</option>
                </select>
              </div>
            </div>

            <!-- Env vars over the project's (env.edit) -->
            <div v-if="canEditEnv" class="mt-4">
              <div class="flex items-center justify-between mb-1.5">
                <span class="label">{{ t('environments.form.envVars') }}</span>
                <button v-if="!envHidden" type="button" class="btn-secondary text-xs px-2.5 py-1" data-testid="env-add-var" @click="addEnvRow">
                  <Plus class="w-3.5 h-3.5" />{{ t('environments.form.addVar') }}
                </button>
              </div>
              <p class="text-[11px] text-gray-400 mb-2">{{ t('environments.form.envVarsHint') }}</p>
              <p v-if="envHidden" class="text-xs text-gray-400">{{ t('environments.form.envVarsHidden') }}</p>
              <div v-for="(row, i) in envRows" :key="i" class="flex items-center gap-2 mb-1.5">
                <input v-model="row.name" class="input flex-1 font-mono text-xs" :placeholder="t('environments.form.varName')" :data-testid="`env-var-name-${i}`" />
                <input v-model="row.value" class="input flex-1 font-mono text-xs" :placeholder="t('environments.form.varValue')" :data-testid="`env-var-value-${i}`" />
                <button type="button" class="text-gray-400 hover:text-red-400" :title="t('environments.form.removeVar')" @click="removeEnvRow(i)">
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </div>
              <p v-if="fieldError('env_vars')" class="text-xs text-red-400 mt-1">{{ fieldError('env_vars') }}</p>
            </div>
            <!-- Timed warmer of the environment (docs/scaling.md#timed-warmer) -->
            <div v-if="showWarmer" class="mt-4" data-testid="env-warmer">
              <label class="label mb-1.5" for="env-warmer-mode">{{ t('environments.form.warmer') }}</label>
              <select id="env-warmer-mode" v-model="warmerMode" class="input w-full" :disabled="overridesLocked" data-testid="env-warmer-mode">
                <option value="inherit">{{ projectRows ? t('environments.form.warmerInherit', { count: projectRows.length }, projectRows.length) : t('environments.form.warmerInheritNone') }}</option>
                <option value="own">{{ t('environments.form.warmerOwn') }}</option>
                <option value="off">{{ t('environments.form.warmerOff') }}</option>
              </select>
              <p class="text-[11px] text-gray-400 mt-1">{{ t('environments.form.warmerHint') }}</p>
              <WarmerSchedule
                v-if="warmerMode === 'own'"
                class="mt-3"
                :rows="warmerRows"
                :max-scale="warmerMaxScale"
                :scale-to-zero="form.scale_to_zero ? form.scale_to_zero === 'on' : projectConfig.scale_to_zero !== false"
                :region-id="form.knative_region_id || environment?.knative_region_id || project?.knative_region_id || null"
                :cpu="form.cpu || projectConfig.cpu || null"
                :memory-mb="num(form.memory_mb) ?? projectConfig.memory_mb ?? null"
                :trigger-type="projectConfig.trigger_type || 'http'"
                :warm-scale="environment?.warm_scale ?? null"
                @update:rows="warmerRows = $event"
              />
              <p v-if="fieldError('scale_schedule')" class="text-xs text-red-400 mt-1">{{ fieldError('scale_schedule') }}</p>
              <p v-for="(message, key) in warmerRowErrors" :key="key" class="text-xs text-red-400 mt-1">{{ message }}</p>
            </div>
            <p v-if="errors.overrides?.[0]" class="text-xs text-red-400 mt-2">{{ errors.overrides[0] }}</p>
          </div>
        </template>
      </div>

      <p v-if="error" class="text-xs text-red-400 mt-3">{{ error }}</p>

      <div class="flex gap-3 mt-5 pt-4 border-t border-gray-800/60">
        <button type="button" class="btn-secondary flex-1" @click="$emit('close')">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary flex-1" :disabled="saving || (isNew && !form.name.trim()) || warmerInvalid" data-testid="env-save">
          {{ saving ? t('common.saving') : t('environments.form.save') }}
        </button>
      </div>
    </form>
  </div>
</template>
