<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { STRATEGIES, normalizeStrategy } from '@/utils/deployStrategy'
import { Split } from 'lucide-vue-next'

const { t, locale } = useI18n()

const props = defineProps({
  projectName:  { type: String, required: true },
  versions:     { type: Array, default: () => [] },
  deploying:    { type: Boolean, default: false },
  // The project's deploy strategy (ProjectResource deploy_strategy); an environment's is its
  // deploy_strategy in `environments` (the project's unless it sets its own)
  strategy:     { type: String, default: 'direct' },
  // The project's environments (GET …/environments, production included) and the selected one
  // (null: production): the deploy goes there (docs/environments.md)
  environments: { type: Array, default: () => [] },
  environment:  { type: String, default: null },
})

const emit = defineEmits(['deploy', 'cancel'])

const selectedVersionId = ref(props.versions[0]?.id ?? null)
const targetEnvironment = ref(props.environment ?? 'production')
// An older version than the one the environment runs: optionally with the config it ran with
// (docs/versioning.md#rollback); off keeps the configuration of now
const restoreConfig     = ref(false)

const hasEnvironments = computed(() => props.environments.length > 1)

const liveVersionId = computed(() => {
  const env = props.environments.find(e => (targetEnvironment.value === 'production' ? e.is_primary : e.name === targetEnvironment.value))
  return env?.deployment?.version?.id ?? null
})

// Versions are listed newest first: one after the live one is older
const isOlderThanLive = computed(() => {
  if (!liveVersionId.value || !selectedVersionId.value) return false
  const live = props.versions.findIndex(v => v.id === liveVersionId.value)
  const picked = props.versions.findIndex(v => v.id === selectedVersionId.value)
  return live !== -1 && picked > live
})

// The strategy of the environment it deploys to (docs/deploy-strategies.md#environments). With a
// strategy, one version may deploy differently (e.g. directly, for an emergency fix); '' keeps the
// environment's strategy. The options always come from the environment's settings.
const targetEnvironmentEntry = computed(() => (targetEnvironment.value === 'production'
  ? null
  : props.environments.find(e => !e.is_primary && e.name === targetEnvironment.value) ?? null))
const projectStrategy  = computed(() => normalizeStrategy(targetEnvironmentEntry.value?.deploy_strategy ?? props.strategy))
const strategyOverride = ref('')
const overrideChoices  = computed(() => STRATEGIES.filter(s => s !== projectStrategy.value))
// Another environment: its own strategy, the one-off choice starts over
watch(targetEnvironment, () => { strategyOverride.value = '' })

function doDeploy() {
  const payload = { versionId: selectedVersionId.value, strategy: strategyOverride.value || null }
  if (hasEnvironments.value || props.environment) payload.environment = targetEnvironment.value
  if (isOlderThanLive.value && restoreConfig.value) payload.restoreConfig = true
  emit('deploy', payload)
}

function fmt(dateTime) {
  if (!dateTime) return '—'
  return new Date(dateTime).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('cancel')" />
    <div class="relative card w-full max-w-lg">
      <div class="flex items-center justify-between mb-5">
        <h2 class="text-base font-semibold">{{ t('project.deploy') }} — {{ projectName }}</h2>
        <button class="text-gray-400 hover:text-gray-100" @click="$emit('cancel')">✕</button>
      </div>

      <!-- Environment (docs/environments.md) -->
      <div v-if="hasEnvironments" class="mb-4">
        <label class="label mb-1.5" for="deploy-environment">{{ t('environments.deploy.environment') }}</label>
        <select id="deploy-environment" v-model="targetEnvironment" class="input w-full" data-testid="deploy-environment">
          <option v-for="env in environments" :key="env.id" :value="env.is_primary ? 'production' : env.name">
            {{ env.name }}{{ env.deployment?.version ? ` · ${env.deployment.version.label}` : '' }}
          </option>
        </select>
      </div>

      <p class="text-sm text-gray-400 mb-4">{{ t('project.selectVersion') }}</p>

      <div class="space-y-1.5 max-h-48 overflow-y-auto pr-1">
        <label
          v-for="v in versions" :key="v.id"
          class="flex items-center gap-3 px-3 py-2.5 rounded-md transition-colors border"
          :class="[
            v.build_status && v.build_status !== 'ready' ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer',
            selectedVersionId === v.id ? 'border-brand-500/50' : 'hover:bg-gray-800 border-transparent'
          ]"
          :style="selectedVersionId === v.id ? 'background:rgb(var(--brand-600) / 0.15);border-color:rgb(var(--brand-500) / 0.5)' : ''"
          :title="v.build_status && v.build_status !== 'ready' ? t('build.notReady') : ''"
        >
          <input
            type="radio" :value="v.id" v-model="selectedVersionId" class="accent-brand-500"
            :disabled="v.build_status && v.build_status !== 'ready'"
          />
          <div class="flex-1 min-w-0">
            <span class="font-mono text-sm" style="color:rgb(var(--brand-500))">{{ v.label }}</span>
            <span class="text-gray-400 text-xs ml-2 truncate">{{ v.commit_message ?? '' }}</span>
            <span
              v-if="v.build_status === 'pending' || v.build_status === 'building'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-yellow-500/20 text-yellow-400"
            >{{ t('build.building') }}</span>
            <span
              v-else-if="v.build_status === 'failed'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-red-500/20 text-red-400"
            >{{ t('build.failed') }}</span>
            <span
              v-else-if="v.build_status === 'pruned'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-gray-500/20 text-gray-400"
              :title="t('build.prunedHint')"
            >{{ t('build.pruned') }}</span>
            <span
              v-else-if="v.build_status === 'cancelled'"
              class="text-[10px] ml-1 px-1 rounded-sm bg-gray-500/20 text-gray-400"
              :title="v.build_error || t('build.cancelledHint')"
            >{{ t('build.cancelled') }}</span>
          </div>
          <span class="text-xs text-gray-400 shrink-0">{{ fmt(v.created_at) }}</span>
        </label>
      </div>

      <!-- Deploy strategy of the environment it deploys to, with a one-off override -->
      <div v-if="projectStrategy !== 'direct'" class="mt-4 rounded-md border border-gray-800 bg-gray-900/60 px-3 py-2.5" data-test="deploy-strategy-choice">
        <p class="flex items-center gap-1.5 text-xs text-gray-300">
          <Split class="w-3.5 h-3.5 text-brand-400" />
          {{ t('deployStrategy.deployUses', { strategy: t(`deployStrategy.names.${strategyOverride || projectStrategy}`) }) }}
        </p>
        <label class="label mt-2.5">{{ t('deployStrategy.overrideLabel') }}</label>
        <select v-model="strategyOverride" class="input text-sm" data-test="deploy-strategy-override">
          <option value="">{{ targetEnvironmentEntry
            ? t('deployStrategy.environmentDefault', { env: targetEnvironmentEntry.name, strategy: t(`deployStrategy.names.${projectStrategy}`) })
            : t('deployStrategy.projectDefault', { strategy: t(`deployStrategy.names.${projectStrategy}`) }) }}</option>
          <option v-for="s in overrideChoices" :key="s" :value="s">{{ t(`deployStrategy.names.${s}`) }}</option>
        </select>
        <p class="text-[11px] mt-1.5" :class="strategyOverride === 'direct' ? 'text-yellow-400' : 'text-gray-500'">
          {{ strategyOverride === 'direct' ? t('deployStrategy.overrideDirectHint') : t('deployStrategy.firstDeployHint') }}
        </p>
      </div>

      <!-- An older version: with the config it ran with (#626) -->
      <label v-if="isOlderThanLive" class="flex items-start gap-2 mt-4 text-sm cursor-pointer" data-testid="deploy-restore-config">
        <input v-model="restoreConfig" type="checkbox" class="accent-brand-500 mt-0.5" />
        <span>
          <span class="text-gray-200">{{ t('environments.deploy.restoreConfig') }}</span>
          <span class="block text-xs text-gray-400 mt-0.5">{{ t('environments.deploy.restoreConfigHint') }}</span>
        </span>
      </label>

      <div class="flex gap-3 mt-5 pt-4 border-t border-gray-800/60">
        <button class="btn-secondary flex-1" @click="$emit('cancel')">{{ t('common.cancel') }}</button>
        <button
          class="btn-primary flex-1"
          :disabled="deploying || !selectedVersionId"
          @click="doDeploy"
        >{{ deploying ? t('project.deploying') : t('project.deploy') }}</button>
      </div>
    </div>
  </div>
</template>
