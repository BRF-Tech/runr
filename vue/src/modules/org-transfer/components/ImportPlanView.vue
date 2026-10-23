<script setup>
// The dry-run plan of an import (docs/org-export.md#import): where each source region goes, the
// projects with their name here (slug collisions get a suggested rename), runtime / residency /
// capacity / quota issues, the bindings with or without data, the organization items and the
// manual steps (DNS of the domains, secrets to enter again). "Check again" sends the choices and
// shows the new plan; Import applies it — refused while the plan has blocking issues. A failed
// import keeps its plan: applying again resumes it (finished entries are not created twice).
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, Play, Lock, Unlock } from 'lucide-vue-next'
import { orgTransferApi, unwrap } from '../api'
import { useConfirm } from '@/composables/useConfirm'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const { confirm } = useConfirm()

const props = defineProps({
  transfer: { type: Object, required: true },
  regions:  { type: Array, default: () => [] },
})
const emit = defineEmits(['changed'])

const plan = computed(() => props.transfer.plan ?? {})
const readOnly = computed(() => ['applying', 'completed'].includes(props.transfer.status))

// The choices sent with "check again" and "import" — taken from the plan once, then the user's
const regionMap    = ref({})
const names        = ref({})
const bindingNames = ref({})
const skipped      = ref([])
const passphrase   = ref('')
const options      = ref({ restore_data: true, images: true, invite_members: false, deploy: false })
const history      = ref(true)    // import the run history of the package
const skipData     = ref([])      // source bindings whose data is left out
const busy         = ref(null)   // 'plan' | 'apply'
const error        = ref(null)

let optionsSet = false
function adopt(p) {
  for (const r of p?.regions ?? []) {
    if (!(r.source_id in regionMap.value)) regionMap.value[r.source_id] = r.target_id ?? ''
  }
  for (const pr of p?.projects ?? []) {
    if (!(pr.source_id in names.value)) names.value[pr.source_id] = pr.target_name ?? pr.name
    if (pr.status === 'skip' && !skipped.value.includes(pr.source_id)) skipped.value.push(pr.source_id)
  }
  for (const b of p?.bindings ?? []) {
    if (!(b.source_id in bindingNames.value)) bindingNames.value[b.source_id] = b.target_name ?? b.name
  }
  for (const b of p?.bindings ?? []) {
    if (b.data && b.data_restore === false && !skipData.value.includes(b.source_id) && !optionsSet) skipData.value.push(b.source_id)
  }
  if (!optionsSet && p?.contents) {
    options.value.restore_data = (p.contents.binding_data ?? 0) > 0
    options.value.images = (p.contents.images ?? 0) > 0
    if (p.history) history.value = p.history.import !== false
    optionsSet = true
  }
}
watch(plan, adopt, { immediate: true })

const regionOptions = (r) => (r.options?.length ? r.options : props.regions.map(x => ({ id: x.id, name: x.name })))
const blocking  = computed(() => Number(plan.value.blocking) || 0)
const canApply  = computed(() => !readOnly.value && !busy.value && blocking.value === 0 && plan.value.can_apply !== false)
const secrets   = computed(() => plan.value.contents?.secrets ?? 'absent')
const counts    = computed(() => props.transfer.report?.counts ?? null)
const applying  = computed(() => props.transfer.status === 'applying')
const resumable = computed(() => props.transfer.status === 'failed')

function choices() {
  const renames = {}
  for (const p of plan.value.projects ?? []) {
    const name = String(names.value[p.source_id] ?? '').trim()
    if (name && name !== p.name) renames[p.source_id] = name
  }
  const bindingRenames = {}
  for (const b of plan.value.bindings ?? []) {
    const name = String(bindingNames.value[b.source_id] ?? '').trim()
    if (name && name !== b.name) bindingRenames[b.source_id] = name
  }
  const regionMapOut = Object.fromEntries(Object.entries(regionMap.value).filter(([, v]) => !!v))
  return {
    region_map:      regionMapOut,
    renames,
    binding_renames: bindingRenames,
    skip_projects:   [...skipped.value],
    restore_data:    !!options.value.restore_data,
    skip_binding_data: [...skipData.value],
    ...(plan.value.history ? { history: !!history.value } : {}),
    ...(passphrase.value ? { passphrase: passphrase.value } : {}),
  }
}

async function recheck() {
  busy.value = 'plan'
  error.value = null
  try {
    emit('changed', unwrap(await orgTransferApi.plan(props.transfer.id, choices())))
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = null
  }
}

async function apply() {
  if (!canApply.value) return
  const ok = await confirm({
    title:        t('orgTransfer.import.applyTitle'),
    message:      t('orgTransfer.import.applyConfirm'),
    confirmLabel: t('orgTransfer.import.apply'),
  })
  if (!ok) return
  busy.value = 'apply'
  error.value = null
  try {
    emit('changed', unwrap(await orgTransferApi.apply(props.transfer.id, { ...choices(), ...options.value })))
    passphrase.value = ''
  } catch (e) {
    // 422 plan_blocked answers with the transfer and its fresh plan
    if (e.response?.data?.code === 'plan_blocked' && e.response.data.data) emit('changed', e.response.data.data)
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    busy.value = null
  }
}

const levelClass = (level) => ({
  error:   'bg-red-950/40 text-red-300 border-red-800/60',
  warning: 'bg-amber-950/30 text-amber-300 border-amber-800/60',
  manual:  'bg-sky-950/30 text-sky-300 border-sky-800/60',
}[level] ?? 'bg-gray-800 text-gray-300 border-gray-700')

const statusText = (s) => (s ? t(`orgTransfer.import.itemStatusValue.${s}`) : '')

// What the history of the package holds, summed over its projects
const historyTotals = computed(() => {
  const h = plan.value.history
  if (!h) return null
  const sum = { deployments: 0, runs: 0, events: 0, dropped: 0 }
  for (const p of h.projects ?? []) {
    sum.deployments += Number(p.deployments) || 0
    sum.runs += Number(p.workflow_runs) || 0
    sum.events += Number(p.trigger_events) || 0
    for (const n of Object.values(p.dropped_by_retention ?? {})) sum.dropped += Number(n) || 0
  }
  return { ...sum, usage: Number(h.usage_records) || 0, bills: Number(h.bills) || 0 }
})
const generalIssues = computed(() => plan.value.issues ?? [])
</script>

<template>
  <div data-testid="import-plan" :data-status="transfer.status">
    <h4 class="text-sm font-semibold text-gray-100 mb-1">{{ t('orgTransfer.import.planTitle') }}</h4>
    <p v-if="plan.source" class="text-xs text-gray-400 mb-2" data-testid="plan-source">
      {{ t('orgTransfer.import.source', { organization: plan.source.organization, slug: plan.source.slug, version: plan.source.runr_version, schema: plan.source.schema_version, at: plan.source.exported_at ? new Date(plan.source.exported_at).toLocaleString() : '—' }) }}
    </p>
    <p v-if="plan.contents" class="text-xs text-gray-500 mb-3" data-testid="plan-contents">
      {{ t('orgTransfer.import.contentLine', {
        projects: plan.contents.projects ?? 0, versions: plan.contents.versions ?? 0, bindings: plan.contents.bindings ?? 0,
        members: plan.contents.members ?? 0, roles: plan.contents.roles ?? 0, groups: plan.contents.groups ?? 0,
        secrets: t(`orgTransfer.import.secretsState.${secrets}`),
      }) }}
    </p>

    <!-- Region mapping -->
    <div v-if="plan.regions?.length" class="mb-4">
      <p class="label">{{ t('orgTransfer.import.regions') }}</p>
      <div v-for="r in plan.regions" :key="r.source_id" class="flex items-center gap-2 text-xs mb-1" :data-region="r.source_id">
        <span class="w-40 font-mono text-gray-300 truncate" :title="r.source_name">{{ r.source_name }}</span>
        <span class="text-gray-600">→</span>
        <select v-model="regionMap[r.source_id]" class="input py-1! text-xs flex-1" :disabled="readOnly" data-testid="plan-region">
          <option value="">{{ t('orgTransfer.import.regionNone') }}</option>
          <option v-for="o in regionOptions(r)" :key="o.id" :value="o.id">{{ o.name }}</option>
        </select>
      </div>
    </div>

    <!-- Projects -->
    <p class="label">{{ t('orgTransfer.import.projects') }}</p>
    <div class="space-y-2 mb-4">
      <div v-for="p in plan.projects ?? []" :key="p.source_id" class="rounded border border-gray-800 p-2" :data-plan-project="p.source_id">
        <div class="flex flex-wrap items-center gap-2 text-xs">
          <span class="font-mono text-gray-200">{{ p.name }}</span>
          <span class="text-gray-500">{{ p.service_type }}<template v-if="p.runtime"> · {{ p.runtime }} {{ p.runtime_version }}</template> · v{{ p.versions }}</span>
          <span class="text-gray-500">{{ p.source_region }} → {{ p.target_region ?? '—' }}</span>
          <span class="ml-auto text-gray-400" data-testid="plan-project-status">{{ statusText(p.status) }}</span>
        </div>
        <div class="flex items-center gap-2 mt-1.5">
          <label class="text-[11px] text-gray-500 w-24">{{ t('orgTransfer.import.targetName') }}</label>
          <input v-model="names[p.source_id]" class="input py-1! text-xs flex-1 font-mono" :disabled="readOnly || p.status === 'imported'" data-testid="plan-target-name">
          <label class="flex items-center gap-1 text-[11px] text-gray-400">
            <input v-model="skipped" type="checkbox" :value="p.source_id" :disabled="readOnly || p.status === 'imported'" data-testid="plan-skip">{{ t('orgTransfer.import.skip') }}
          </label>
        </div>
        <p v-if="p.collision && p.suggested_name" class="text-[11px] text-amber-400 mt-1">→ {{ p.suggested_name }}</p>
        <div v-if="p.issues?.length" class="flex flex-wrap gap-1 mt-1.5">
          <span v-for="(issue, i) in p.issues" :key="i" class="text-[11px] px-1.5 py-0.5 rounded border" :class="levelClass(issue.level)" :data-level="issue.level" :data-code="issue.code" data-testid="plan-issue">
            {{ t(`orgTransfer.import.level.${issue.level}`) }}: {{ issue.message }}
          </span>
        </div>
      </div>
    </div>

    <!-- Bindings -->
    <div v-if="plan.bindings?.length" class="mb-4">
      <p class="label">{{ t('orgTransfer.import.bindings') }}</p>
      <div v-for="b in plan.bindings" :key="b.source_id" class="rounded border border-gray-800 p-2 mb-1.5 text-xs" :data-plan-binding="b.source_id">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-mono text-gray-200">{{ b.name }}</span>
          <span class="text-gray-500">{{ b.type }} · {{ b.plan }}</span>
          <span class="text-gray-500">{{ b.data ? `${t('orgTransfer.import.withData')} (${formatBytes(b.data_bytes)})` : t('orgTransfer.import.noData') }}</span>
          <span class="ml-auto text-gray-400">{{ statusText(b.status) }}</span>
        </div>
        <div class="flex items-center gap-2 mt-1.5">
          <label class="text-[11px] text-gray-500 w-24">{{ t('orgTransfer.import.targetName') }}</label>
          <input v-model="bindingNames[b.source_id]" class="input py-1! text-xs flex-1 font-mono" :disabled="readOnly || b.status === 'imported'" data-testid="plan-binding-name">
          <label v-if="b.data" class="flex items-center gap-1 text-[11px] text-gray-400">
            <input v-model="skipData" type="checkbox" :value="b.source_id" :disabled="readOnly" data-testid="plan-skip-data">{{ t('orgTransfer.import.skipData') }}
          </label>
        </div>
        <p v-if="b.data_redis_version" class="text-[11px] text-gray-500 mt-1" data-testid="plan-redis-version">
          {{ t('orgTransfer.import.redisVersions', { source: b.data_redis_version, target: b.target_redis_version ?? '—' }) }}
        </p>
        <div v-if="b.issues?.length" class="flex flex-wrap gap-1 mt-1.5">
          <span v-for="(issue, i) in b.issues" :key="i" class="text-[11px] px-1.5 py-0.5 rounded border" :class="levelClass(issue.level)" :data-level="issue.level" data-testid="plan-issue">
            {{ t(`orgTransfer.import.level.${issue.level}`) }}: {{ issue.message }}
          </span>
        </div>
      </div>
    </div>

    <!-- Organization -->
    <div v-if="plan.organization" class="mb-4 text-xs text-gray-400" data-testid="plan-organization">
      <p class="label">{{ t('orgTransfer.import.organization') }}</p>
      <p v-for="r in plan.organization.roles ?? []" :key="`r-${r.key}`">{{ r.name }} <span class="text-gray-600">· {{ statusText(r.status) }}</span></p>
      <p v-for="g in plan.organization.groups ?? []" :key="`g-${g.name}`">{{ g.name }} <span class="text-gray-600">· {{ statusText(g.status) }}</span></p>
      <p v-for="m in plan.organization.members ?? []" :key="`m-${m.email}`" class="font-mono">{{ m.email }} <span class="text-gray-600">· {{ m.role }} · {{ statusText(m.status) }}</span></p>
      <p v-for="c in plan.organization.alert_channels ?? []" :key="`c-${c.name}`">{{ c.name }} ({{ c.type }}) <span class="text-gray-600">· {{ statusText(c.status) }}</span></p>
    </div>

    <!-- Issues of the whole import (not of one project or binding) -->
    <div v-if="generalIssues.length" class="flex flex-wrap gap-1 mb-4" data-testid="plan-general-issues">
      <span v-for="(issue, i) in generalIssues" :key="i" class="text-[11px] px-1.5 py-0.5 rounded border" :class="levelClass(issue.level)" :data-level="issue.level" :data-code="issue.code">
        {{ t(`orgTransfer.import.level.${issue.level}`) }}: {{ issue.message }}
      </span>
    </div>

    <!-- Run history of the package -->
    <div v-if="historyTotals" class="mb-4 text-xs text-gray-400" data-testid="plan-history">
      <p class="label">{{ t('orgTransfer.import.history') }}</p>
      <p>{{ t('orgTransfer.import.historyLine', historyTotals) }}</p>
      <p v-if="historyTotals.dropped" class="text-amber-400">{{ t('orgTransfer.import.historyDropped', { n: historyTotals.dropped }) }}</p>
      <p class="text-gray-500">{{ t('orgTransfer.import.historyHint') }}</p>
    </div>

    <!-- Manual steps -->
    <div v-if="plan.manual_steps?.length" class="mb-4 rounded-lg border border-sky-900/60 bg-sky-950/10 p-3" data-testid="plan-manual-steps">
      <p class="text-xs font-medium text-sky-300 mb-1">{{ t('orgTransfer.import.manualSteps') }}</p>
      <div v-for="step in plan.manual_steps" :key="step.code" class="text-xs text-gray-300 mb-1" :data-step="step.code">
        <p>{{ step.message }}</p>
        <p v-if="step.items?.length" class="font-mono text-gray-500">{{ step.items.join(', ') }}</p>
      </div>
    </div>

    <!-- Secrets of the package -->
    <div v-if="secrets !== 'absent' && !readOnly" class="mb-4" data-testid="plan-secrets" :data-state="secrets">
      <p class="text-xs mb-1 flex items-center gap-1" :class="secrets === 'locked' ? 'text-amber-300' : 'text-emerald-400'">
        <component :is="secrets === 'locked' ? Lock : Unlock" class="w-3.5 h-3.5" />
        {{ secrets === 'locked' ? t('orgTransfer.import.secretsLocked') : t('orgTransfer.import.secretsUnlocked') }}
      </p>
      <label class="label" for="import-passphrase">{{ t('orgTransfer.import.passphrase') }}</label>
      <input id="import-passphrase" v-model="passphrase" type="password" class="input" autocomplete="off" data-testid="import-passphrase">
    </div>

    <p v-if="blocking > 0" class="text-xs text-red-400 mb-2" data-testid="plan-blocking">{{ t('orgTransfer.import.blocking', { n: blocking }) }}</p>
    <p v-else-if="plan.warnings" class="text-xs text-amber-400 mb-2">{{ t('orgTransfer.import.warnings', { n: plan.warnings }) }}</p>
    <p v-if="error" class="text-sm text-red-400 mb-2" data-testid="plan-error">{{ error }}</p>

    <!-- Apply -->
    <div v-if="!readOnly" class="space-y-1.5 text-xs text-gray-300 mb-3">
      <label v-if="(plan.contents?.binding_data ?? 0) > 0" class="flex items-center gap-2"><input v-model="options.restore_data" type="checkbox" data-testid="import-restore-data">{{ t('orgTransfer.import.restoreData') }}</label>
      <label v-if="(plan.contents?.images ?? 0) > 0" class="flex items-center gap-2"><input v-model="options.images" type="checkbox" data-testid="import-images">{{ t('orgTransfer.import.importImages') }}</label>
      <label v-if="plan.history" class="flex items-center gap-2"><input v-model="history" type="checkbox" data-testid="import-history">{{ t('orgTransfer.import.importHistory') }}</label>
      <label v-if="(plan.organization?.members ?? []).some(m => m.status !== 'member')" class="flex items-center gap-2"><input v-model="options.invite_members" type="checkbox" data-testid="import-invite-members">{{ t('orgTransfer.import.inviteMembers') }}</label>
      <label class="flex items-center gap-2"><input v-model="options.deploy" type="checkbox" data-testid="import-deploy">{{ t('orgTransfer.import.deploy') }}</label>
    </div>
    <div v-if="!readOnly" class="flex justify-end gap-2">
      <button type="button" class="btn-secondary" :disabled="!!busy" data-testid="plan-recheck" @click="recheck">
        <RefreshCw class="w-4 h-4" />{{ busy === 'plan' ? t('orgTransfer.import.rechecking') : t('orgTransfer.import.recheck') }}
      </button>
      <button type="button" class="btn-primary" :disabled="!canApply" data-testid="import-apply" @click="apply">
        <Play class="w-4 h-4" />{{ resumable ? t('orgTransfer.import.resumeApply') : t('orgTransfer.import.apply') }}
      </button>
    </div>

    <!-- Running / done -->
    <div v-if="applying" class="mt-3" data-testid="import-progress">
      <div class="flex justify-between text-xs text-gray-300 mb-1">
        <span>{{ t('orgTransfer.import.applying') }}<template v-if="transfer.phase"> · {{ t(`orgTransfer.phase.${transfer.phase}`) }}</template></span>
        <span>{{ transfer.progress ?? 0 }}%</span>
      </div>
      <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: (transfer.progress ?? 0) + '%' }" />
      </div>
      <p v-if="transfer.message" class="text-[11px] text-gray-500 mt-1">{{ transfer.message }}</p>
    </div>
    <p v-if="resumable && transfer.message" class="text-xs text-red-400 mt-2" data-testid="import-failed-message">{{ transfer.message }}</p>
    <p v-if="counts" class="text-xs text-gray-300 mt-3" data-testid="import-counts">
      {{ t('orgTransfer.import.counts', { created: counts.created ?? 0, exists: counts.exists ?? 0, skipped: counts.skipped ?? 0, failed: counts.failed ?? 0, manual: counts.manual ?? 0 }) }}
    </p>
  </div>
</template>
