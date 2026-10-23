<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Pencil, Trash2, KeyRound, Rocket, GitBranch, MapPin, ShieldCheck, ExternalLink, ArrowRight, SlidersHorizontal, Flame } from 'lucide-vue-next'
import { environmentsApi } from '@/api/environments'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { usePermissionsStore } from '@/stores/permissions'
import StatusBadge from '@/components/StatusBadge.vue'
import EnvironmentFormModal from '@/components/project/EnvironmentFormModal.vue'
import EnvironmentSecretsModal from '@/components/project/EnvironmentSecretsModal.vue'
import PromoteDialog from '@/components/project/PromoteDialog.vue'
import PromotionList from '@/components/project/PromotionList.vue'
import DeployStrategyModal from '@/components/project/DeployStrategyModal.vue'
import { isLegacyServiceType } from '@/utils/projectHelpers'
import { normalizeStrategy } from '@/utils/deployStrategy'

/**
 * The environments tab (docs/environments.md): the pipeline — dev → staging → … → production, the
 * project's own workload last — with each environment's region, URL, git branch, approval and the
 * version it runs; create / change / delete environments and their secrets (environments.manage,
 * env vars env.edit), promote one's version to the next (environments.promote), and the project's
 * promotions with the ones waiting for approval. Each environment's deploy strategy — the
 * project's unless it sets its own (docs/deploy-strategies.md#environments) — opens from its card,
 * which also names its timed warmer (the project's schedule, its own or none) and what it keeps
 * warm now.
 */
const props = defineProps({
  project: { type: Object, required: true },
})

const emit = defineEmits(['changed'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const environments = ref([])
const abilities    = ref({ manage: false, promote: false, approve: false })
const promotions   = ref([])
const loading      = ref(true)
const loadError    = ref(null)

const editing     = ref(null)    // environment being changed, or 'new'
const secretsOf   = ref(null)
const promoteFrom = ref(null)
const strategyOf  = ref(null)    // environment whose deploy strategy is open

const canEditEnv = computed(() => usePermissionsStore().can('env.edit', props.project.id))

async function load() {
  loadError.value = null
  try {
    const [envs, promos] = await Promise.all([
      environmentsApi.list(props.project.id),
      environmentsApi.promotions(props.project.id, { limit: 20 }),
    ])
    environments.value = envs.data.data ?? []
    abilities.value    = { manage: false, promote: false, approve: false, ...(envs.data.abilities ?? {}) }
    promotions.value   = promos.data.data ?? []
  } catch (e) {
    loadError.value = e.response?.data?.message ?? t('environments.loadFailed')
  } finally {
    loading.value = false
  }
}

onMounted(load)

const nameOf = env => (env.is_primary ? 'production' : env.name)

// Deploy strategies are a function's (not of the legacy service types)
const showStrategies = computed(() => !isLegacyServiceType(props.project.service_type))
const strategyName = env => t(`deployStrategy.names.${normalizeStrategy(env.deploy_strategy ?? props.project.deploy_strategy)}`)

// The timed warmer of an environment (docs/scaling.md#timed-warmer): whose schedule, and what it keeps warm now
function warmerLabel(env) {
  const source = env.scale_schedule
    ? t(env.scale_schedule_inherited ? 'environments.warmer.inherited' : 'environments.warmer.own')
    : t('environments.warmer.off')
  const applied = env.warm_scale?.applied
  return applied === undefined || applied === null ? source : `${source} · ${t('environments.warmer.now', { count: applied }, Number(applied))}`
}

async function onStrategySaved() {
  await load()
  emit('changed')
}

// The last environment of the pipeline has nowhere to go (unless one is named in the dialog)
function canPromote(env) {
  return abilities.value.promote && !!env.deployment && environments.value.length > 1
}

async function onSaved(env) {
  const created = editing.value === 'new'
  editing.value = null
  toast.success(t(created ? 'environments.created' : 'environments.saved', { name: env?.name ?? '' }))
  await load()
  emit('changed')
}

async function remove(env) {
  const ok = await confirm({
    title:        t('environments.deleteTitle'),
    message:      t('environments.deleteConfirm', { name: env.name }),
    confirmLabel: t('common.delete'),
    destructive:  true,
  })
  if (!ok) return
  try {
    await environmentsApi.destroy(props.project.id, env.name)
    toast.success(t('environments.deleted', { name: env.name }))
    await load()
    emit('changed')
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  }
}

async function onPromoted(promotion) {
  promoteFrom.value = null
  toast.success(promotion.status === 'pending_approval'
    ? t('environments.promoteDialog.requested', { environment: promotion.target })
    : t('environments.promoteDialog.deploying', { version: promotion.version?.label ?? '?', environment: promotion.target }))
  await load()
  emit('changed')
}
</script>

<template>
  <div class="space-y-6" data-testid="project-environments">
    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
      <div class="min-w-0">
        <div class="flex items-center gap-2">
          <div class="w-0.5 h-5 rounded-full shrink-0" style="background:linear-gradient(180deg,rgb(var(--brand-500)),rgb(var(--brand-700)))"></div>
          <span class="text-[15px] font-semibold text-gray-100">{{ t('environments.title') }}</span>
        </div>
        <p class="text-xs text-gray-400 mt-1.5 ml-[10px] max-w-3xl">{{ t('environments.intro') }}</p>
      </div>
      <button v-if="abilities.manage" class="btn-primary text-xs px-3 py-1.5 shrink-0" data-testid="environment-create" @click="editing = 'new'">
        <Plus class="w-3.5 h-3.5" />{{ t('environments.create') }}
      </button>
    </div>

    <div v-if="loading" class="flex items-center justify-center py-12">
      <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <div v-else-if="loadError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
      <p class="text-xs text-red-400">{{ loadError }}</p>
    </div>

    <template v-else>
      <!-- The pipeline -->
      <div class="flex items-stretch gap-2 overflow-x-auto pb-1">
        <template v-for="(env, i) in environments" :key="env.id">
          <div class="rounded-md border border-gray-800 p-4 min-w-64 flex-1 flex flex-col gap-2" :data-testid="`environment-card-${nameOf(env)}`">
            <div class="flex items-center gap-2">
              <span class="font-semibold text-gray-100">{{ env.name }}</span>
              <span v-if="env.is_primary" class="text-[10px] px-1.5 py-0.5 rounded-sm bg-gray-800 text-gray-400 border border-gray-700">{{ t('environments.primary') }}</span>
              <span v-if="env.requires_approval" class="flex items-center gap-1 text-[10px] px-1.5 py-0.5 rounded-sm bg-amber-900/30 text-amber-300 border border-amber-800/50" :title="t('environments.approvalRequiredHint')">
                <ShieldCheck class="w-3 h-3" />{{ t('environments.approvalRequired') }}
              </span>
            </div>

            <div class="flex items-center gap-2 text-sm">
              <StatusBadge v-if="env.deployment" :status="env.deployment.status" />
              <span v-if="env.deployment?.version" class="font-mono" style="color:rgb(var(--brand-500))">{{ env.deployment.version.label }}</span>
              <span v-if="!env.deployment" class="text-xs text-gray-400">{{ t('environments.notDeployed') }}</span>
            </div>

            <div class="space-y-1 text-xs text-gray-400">
              <p v-if="env.region" class="flex items-center gap-1.5"><MapPin class="w-3 h-3" />{{ env.region.name }}<span v-if="env.own_region"> · {{ t('environments.ownRegion') }}</span></p>
              <p v-if="env.git_branch" class="flex items-center gap-1.5"><GitBranch class="w-3 h-3" />{{ t('environments.branch', { branch: env.git_branch }) }}</p>
              <a v-if="env.url" :href="env.url" target="_blank" rel="noopener noreferrer" class="flex items-center gap-1.5 font-mono text-brand-400 hover:text-brand-300 hover:underline truncate">
                <ExternalLink class="w-3 h-3 shrink-0" />{{ env.url }}
              </a>
              <button
                v-if="showStrategies"
                type="button"
                class="flex items-center gap-1.5 hover:text-gray-100"
                :title="t('deployStrategy.environment.settingsHint')"
                :data-testid="`environment-strategy-${nameOf(env)}`"
                @click="strategyOf = env"
              ><SlidersHorizontal class="w-3 h-3" />{{ t('deployStrategy.environment.settings') }}: <span class="text-gray-200">{{ strategyName(env) }}</span></button>
              <p v-if="showStrategies && env.scale_schedule !== undefined" class="flex items-center gap-1.5" :data-testid="`environment-warmer-${nameOf(env)}`">
                <Flame class="w-3 h-3" />{{ t('environments.warmer.label') }}: <span class="text-gray-200">{{ warmerLabel(env) }}</span>
              </p>
            </div>

            <div class="flex items-center gap-1.5 mt-auto pt-2 flex-wrap">
              <button
                v-if="canPromote(env)"
                class="btn-secondary text-xs px-2.5 py-1"
                :data-testid="`environment-promote-${nameOf(env)}`"
                @click="promoteFrom = env"
              ><Rocket class="w-3.5 h-3.5" />{{ t('environments.promote') }}</button>
              <button
                v-if="abilities.manage"
                class="btn-secondary text-xs px-2.5 py-1"
                :data-testid="`environment-edit-${nameOf(env)}`"
                @click="editing = env"
              ><Pencil class="w-3.5 h-3.5" />{{ t('environments.edit') }}</button>
              <button
                v-if="!env.is_primary"
                class="btn-secondary text-xs px-2.5 py-1"
                :data-testid="`environment-secrets-${nameOf(env)}`"
                @click="secretsOf = env"
              ><KeyRound class="w-3.5 h-3.5" />{{ t('environments.secretsAction') }}</button>
              <button
                v-if="abilities.manage && !env.is_primary"
                class="text-gray-400 hover:text-red-400 ml-auto"
                :title="t('common.delete')"
                :data-testid="`environment-delete-${nameOf(env)}`"
                @click="remove(env)"
              ><Trash2 class="w-4 h-4" /></button>
            </div>
          </div>
          <div v-if="i < environments.length - 1" class="flex items-center text-gray-600 shrink-0">
            <ArrowRight class="w-4 h-4" />
          </div>
        </template>
      </div>

      <!-- Promotions of the project -->
      <div>
        <h3 class="text-sm font-semibold text-gray-200 mb-2">{{ t('environments.promotions.title') }}</h3>
        <PromotionList :promotions="promotions" @changed="load" />
      </div>
    </template>

    <EnvironmentFormModal
      v-if="editing"
      :project-id="project.id"
      :environment="editing === 'new' ? null : editing"
      :can-edit-env="canEditEnv"
      :project="project"
      @saved="onSaved"
      @close="editing = null"
    />

    <EnvironmentSecretsModal
      v-if="secretsOf"
      :project-id="project.id"
      :environment="secretsOf"
      :can-edit="canEditEnv"
      @close="secretsOf = null"
    />

    <DeployStrategyModal
      v-if="strategyOf"
      :project="project"
      :environment="nameOf(strategyOf)"
      @saved="onStrategySaved"
      @close="strategyOf = null"
    />

    <PromoteDialog
      v-if="promoteFrom"
      :project-id="project.id"
      :source="promoteFrom"
      :environments="environments"
      @promoted="onPromoted"
      @close="promoteFrom = null"
    />
  </div>
</template>
