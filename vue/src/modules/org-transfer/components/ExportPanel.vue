<script setup>
// Export card of Administration → Export / Import (docs/org-export.md): what goes into the package
// (projects, members, images, binding data, secrets encrypted with a passphrase), where it goes
// (download, an object binding, an external S3 bucket) and the size estimate, which is shown
// before the export can start. The passphrase and S3 keys are sent with the start request only.
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { PackageOpen, Download, FileText } from 'lucide-vue-next'
import { orgTransferApi, unwrap, exportPayload, estimatePayload, MIN_PASSPHRASE, ACTIVE_STATUSES } from '../api'
import { useToast } from '@/composables/useToast'
import { formatBytes } from '@/utils/ai'

const { t } = useI18n()
const toast = useToast()

const props = defineProps({
  transfer: { type: Object, default: null },   // the export this card follows
  projects: { type: Array, default: () => [] },
  bindings: { type: Array, default: () => [] },
})
const emit = defineEmits(['started', 'download', 'report'])

const form = ref({
  allProjects:    true,
  projects:       [],
  includeMembers: false,
  includeImages:  false,
  bindingData:    [],
  includeSecrets: false,
  includeHistory: false,
  passphrase:     '',
  passphrase2:    '',
  destination:    { type: 'download', bindingId: '', prefix: 'runr-exports', endpoint: '', bucket: '', region: '', accessKeyId: '', secretAccessKey: '', pathStyle: true },
})
const estimate    = ref(null)
const estimating  = ref(false)
const estimateErr = ref(null)
const starting    = ref(false)
const startErr    = ref(null)

const dataBindings   = computed(() => props.bindings.filter(b => b.type !== 'external'))
const objectBindings = computed(() => props.bindings.filter(b => b.type === 'object'))
const running  = computed(() => !!props.transfer && ACTIVE_STATUSES.includes(props.transfer.status))
const finished = computed(() => props.transfer?.status === 'completed')
const failed   = computed(() => props.transfer?.status === 'failed')

const passphraseError = computed(() => {
  if (!form.value.includeSecrets) return null
  if (form.value.passphrase.length < MIN_PASSPHRASE) return t('orgTransfer.export.passphraseShort', { min: MIN_PASSPHRASE })
  if (form.value.passphrase !== form.value.passphrase2) return t('orgTransfer.export.passphraseMismatch')
  return null
})
const destinationOk = computed(() => {
  const d = form.value.destination
  if (d.type === 'binding') return !!d.bindingId
  if (d.type === 's3') return !!d.endpoint && !!d.bucket && !!d.accessKeyId && !!d.secretAccessKey
  return true
})
const projectsOk = computed(() => form.value.allProjects || form.value.projects.length > 0)
const canEstimate = computed(() => projectsOk.value && !estimating.value)
const canStart = computed(() => !!estimate.value && !passphraseError.value && destinationOk.value && projectsOk.value && !starting.value && !running.value)

// Another choice of what goes in: the estimate is asked again before the export starts
watch(
  () => [form.value.allProjects, [...form.value.projects], form.value.includeMembers, form.value.includeImages, JSON.stringify(form.value.bindingData)],
  () => { estimate.value = null },
)

function allBindings(on) {
  form.value.bindingData = on ? 'all' : []
}

function bindingChecked(id) {
  return form.value.bindingData === 'all' || (Array.isArray(form.value.bindingData) && form.value.bindingData.includes(id))
}

function toggleBinding(id, on) {
  let list = form.value.bindingData === 'all' ? dataBindings.value.map(b => b.id) : [...form.value.bindingData]
  list = on ? [...new Set([...list, id])] : list.filter(x => x !== id)
  form.value.bindingData = list
}

async function runEstimate() {
  estimating.value = true
  estimateErr.value = null
  try {
    estimate.value = unwrap(await orgTransferApi.estimate(estimatePayload(form.value)))
  } catch (e) {
    estimateErr.value = e.response?.data?.message ?? t('orgTransfer.export.estimateFailed')
  } finally {
    estimating.value = false
  }
}

async function start() {
  if (!canStart.value) return
  starting.value = true
  startErr.value = null
  try {
    const transfer = unwrap(await orgTransferApi.startExport(exportPayload(form.value)))
    // not kept once the export runs: neither the passphrase nor the S3 secret
    form.value.passphrase = ''
    form.value.passphrase2 = ''
    form.value.destination.secretAccessKey = ''
    toast.success(t('orgTransfer.export.started'))
    emit('started', transfer)
  } catch (e) {
    startErr.value = e.response?.data?.message ?? t('orgTransfer.export.startFailed')
  } finally {
    starting.value = false
  }
}

function destinationText(d) {
  if (!d) return ''
  if (d.type === 'binding') return `${d.binding_name ?? d.binding_id}: ${d.key ?? ''}`
  if (d.type === 's3') return `${d.endpoint}/${d.bucket}/${d.key ?? ''}`
  return ''
}
</script>

<template>
  <div class="card" data-testid="export-panel">
    <div class="flex items-center gap-2 mb-3 pb-3 border-b border-gray-800">
      <PackageOpen class="w-4 h-4" style="color:rgb(var(--brand-500))" />
      <h3 class="text-sm font-semibold text-gray-100">{{ t('orgTransfer.export.title') }}</h3>
    </div>
    <p class="text-xs text-gray-400 mb-4">{{ t('orgTransfer.export.description') }}</p>

    <!-- The running / finished export -->
    <div v-if="transfer && (running || finished || failed)" class="mb-4 rounded-lg border border-gray-800 p-3" data-testid="export-progress" :data-status="transfer.status">
      <div class="flex justify-between text-xs text-gray-300 mb-1">
        <span data-testid="export-status">
          {{ t(`orgTransfer.status.${transfer.status}`) }}<template v-if="transfer.phase"> · {{ t(`orgTransfer.phase.${transfer.phase}`) }}</template>
        </span>
        <span>{{ transfer.progress ?? 0 }}%</span>
      </div>
      <div class="h-2 bg-gray-800 rounded-full overflow-hidden">
        <div class="h-full rounded-full transition-all duration-300" style="background:rgb(var(--brand-500))" :style="{ width: (transfer.progress ?? 0) + '%' }" data-testid="export-progress-bar" />
      </div>
      <p v-if="transfer.message" class="text-[11px] mt-1" :class="failed ? 'text-red-400' : 'text-gray-500'" data-testid="export-message">{{ transfer.message }}</p>
      <div v-if="finished" class="mt-2 flex flex-wrap items-center gap-2 text-xs">
        <span class="text-emerald-400">{{ t('orgTransfer.export.ready') }}</span>
        <span v-if="transfer.package" class="font-mono text-gray-400">{{ transfer.package.filename }} · {{ formatBytes(transfer.package.size_bytes) }}</span>
        <span v-if="transfer.destination && transfer.destination.type !== 'download'" class="text-gray-400" data-testid="export-written-to">
          {{ t('orgTransfer.export.writtenTo', { target: destinationText(transfer.destination) }) }}
        </span>
        <button v-if="transfer.package?.downloadable" type="button" class="btn-primary text-xs ml-auto" data-testid="export-download" @click="emit('download', transfer)">
          <Download class="w-3.5 h-3.5" />{{ t('orgTransfer.export.downloadPackage') }}
        </button>
        <button v-if="transfer.report" type="button" class="btn-secondary text-xs" data-testid="export-report" @click="emit('report', transfer)">
          <FileText class="w-3.5 h-3.5" />{{ t('orgTransfer.list.report') }}
        </button>
      </div>
    </div>

    <!-- Projects -->
    <label class="label">{{ t('orgTransfer.export.projects') }}</label>
    <div class="flex gap-4 text-xs text-gray-300 mb-2">
      <label class="flex items-center gap-1.5"><input v-model="form.allProjects" type="radio" :value="true" data-testid="export-all-projects">{{ t('orgTransfer.export.allProjects') }}</label>
      <label class="flex items-center gap-1.5"><input v-model="form.allProjects" type="radio" :value="false" data-testid="export-some-projects">{{ t('orgTransfer.export.someProjects') }}</label>
    </div>
    <div v-if="!form.allProjects" class="max-h-40 overflow-y-auto rounded border border-gray-800 p-2 mb-3 space-y-1" data-testid="export-project-list">
      <p v-if="!projects.length" class="text-xs text-gray-500">{{ t('orgTransfer.export.noProjects') }}</p>
      <label v-for="p in projects" :key="p.id" class="flex items-center gap-2 text-xs text-gray-300" :data-project="p.id">
        <input v-model="form.projects" type="checkbox" :value="p.id">
        <span class="font-mono">{{ p.name }}</span>
      </label>
    </div>

    <div class="space-y-2 my-3 text-xs text-gray-300">
      <label class="flex items-start gap-2"><input v-model="form.includeMembers" type="checkbox" class="mt-0.5" data-testid="export-members">{{ t('orgTransfer.export.members') }}</label>
      <label class="flex items-start gap-2"><input v-model="form.includeImages" type="checkbox" class="mt-0.5" data-testid="export-images">{{ t('orgTransfer.export.images') }}</label>
      <label class="flex items-start gap-2"><input v-model="form.includeHistory" type="checkbox" class="mt-0.5" data-testid="export-history">{{ t('orgTransfer.export.history') }}</label>
    </div>

    <!-- Binding data -->
    <label class="label">{{ t('orgTransfer.export.bindingData') }}</label>
    <p class="text-[11px] text-gray-500 mb-2">{{ t('orgTransfer.export.bindingDataHint') }}</p>
    <p v-if="!dataBindings.length" class="text-xs text-gray-500 mb-3">{{ t('orgTransfer.export.noBindings') }}</p>
    <div v-else class="rounded border border-gray-800 p-2 mb-3 space-y-1" data-testid="export-binding-list">
      <label class="flex items-center gap-2 text-xs text-gray-300">
        <input type="checkbox" :checked="form.bindingData === 'all'" data-testid="export-binding-all" @change="allBindings($event.target.checked)">
        {{ t('orgTransfer.export.allBindings') }}
      </label>
      <label v-for="b in dataBindings" :key="b.id" class="flex items-center gap-2 text-xs text-gray-300" :data-binding="b.id">
        <input type="checkbox" :checked="bindingChecked(b.id)" @change="toggleBinding(b.id, $event.target.checked)">
        <span class="font-mono">{{ b.name }}</span>
        <span class="text-gray-500">{{ b.type }}</span>
        <span class="ml-auto font-mono text-gray-500">{{ formatBytes(b.usage_bytes) }}</span>
      </label>
    </div>

    <!-- Secrets -->
    <label class="flex items-start gap-2 text-xs text-gray-300">
      <input v-model="form.includeSecrets" type="checkbox" class="mt-0.5" data-testid="export-secrets">{{ t('orgTransfer.export.secrets') }}
    </label>
    <p class="text-[11px] text-gray-500 mb-2 ml-5">{{ t('orgTransfer.export.secretsHint') }}</p>
    <div v-if="form.includeSecrets" class="grid gap-2 sm:grid-cols-2 mb-1 ml-5">
      <div>
        <label class="label" for="export-passphrase">{{ t('orgTransfer.export.passphrase') }}</label>
        <input id="export-passphrase" v-model="form.passphrase" type="password" class="input" autocomplete="new-password" data-testid="export-passphrase">
      </div>
      <div>
        <label class="label" for="export-passphrase-repeat">{{ t('orgTransfer.export.passphraseRepeat') }}</label>
        <input id="export-passphrase-repeat" v-model="form.passphrase2" type="password" class="input" autocomplete="new-password" data-testid="export-passphrase-repeat">
      </div>
    </div>
    <p v-if="form.includeSecrets" class="text-[11px] ml-5 mb-3" :class="passphraseError ? 'text-amber-400' : 'text-gray-500'" data-testid="export-passphrase-hint">
      {{ passphraseError ?? t('orgTransfer.export.passphraseHint', { min: MIN_PASSPHRASE }) }}
    </p>

    <!-- Destination -->
    <label class="label mt-3" for="export-destination">{{ t('orgTransfer.export.destination') }}</label>
    <select id="export-destination" v-model="form.destination.type" class="input mb-2" data-testid="export-destination">
      <option value="download">{{ t('orgTransfer.export.destDownload') }}</option>
      <option value="binding">{{ t('orgTransfer.export.destBinding') }}</option>
      <option value="s3">{{ t('orgTransfer.export.destS3') }}</option>
    </select>
    <div v-if="form.destination.type === 'binding'" class="grid gap-2 sm:grid-cols-2 mb-3">
      <div>
        <label class="label" for="export-binding-target">{{ t('orgTransfer.export.bindingPick') }}</label>
        <select id="export-binding-target" v-model="form.destination.bindingId" class="input" data-testid="export-binding-target">
          <option value="" disabled>{{ objectBindings.length ? '—' : t('orgTransfer.export.noObjectBindings') }}</option>
          <option v-for="b in objectBindings" :key="b.id" :value="b.id">{{ b.name }}</option>
        </select>
      </div>
      <div>
        <label class="label" for="export-binding-prefix">{{ t('orgTransfer.export.prefix') }}</label>
        <input id="export-binding-prefix" v-model="form.destination.prefix" class="input" data-testid="export-prefix">
      </div>
    </div>
    <div v-if="form.destination.type === 's3'" class="grid gap-2 sm:grid-cols-2 mb-1" data-testid="export-s3">
      <div class="sm:col-span-2">
        <label class="label" for="export-s3-endpoint">{{ t('orgTransfer.export.s3Endpoint') }}</label>
        <input id="export-s3-endpoint" v-model="form.destination.endpoint" class="input" placeholder="https://s3.example.com" data-testid="export-s3-endpoint">
      </div>
      <div>
        <label class="label" for="export-s3-bucket">{{ t('orgTransfer.export.s3Bucket') }}</label>
        <input id="export-s3-bucket" v-model="form.destination.bucket" class="input" data-testid="export-s3-bucket">
      </div>
      <div>
        <label class="label" for="export-s3-region">{{ t('orgTransfer.export.s3Region') }}</label>
        <input id="export-s3-region" v-model="form.destination.region" class="input" placeholder="us-east-1" data-testid="export-s3-region">
      </div>
      <div>
        <label class="label" for="export-s3-key">{{ t('orgTransfer.export.s3AccessKey') }}</label>
        <input id="export-s3-key" v-model="form.destination.accessKeyId" class="input" autocomplete="off" data-testid="export-s3-access-key">
      </div>
      <div>
        <label class="label" for="export-s3-secret">{{ t('orgTransfer.export.s3SecretKey') }}</label>
        <input id="export-s3-secret" v-model="form.destination.secretAccessKey" type="password" class="input" autocomplete="off" data-testid="export-s3-secret-key">
      </div>
      <div>
        <label class="label" for="export-s3-prefix">{{ t('orgTransfer.export.prefix') }}</label>
        <input id="export-s3-prefix" v-model="form.destination.prefix" class="input" data-testid="export-s3-prefix">
      </div>
      <label class="flex items-center gap-2 text-xs text-gray-300 mt-5"><input v-model="form.destination.pathStyle" type="checkbox" data-testid="export-s3-path-style">{{ t('orgTransfer.export.s3PathStyle') }}</label>
      <p class="sm:col-span-2 text-[11px] text-gray-500">{{ t('orgTransfer.export.s3Hint') }}</p>
    </div>

    <!-- Size estimate first, then the export -->
    <div class="mt-4 rounded-lg border border-gray-800 p-3" data-testid="export-estimate-box">
      <div class="flex items-center gap-2">
        <span class="text-xs font-medium text-gray-300">{{ t('orgTransfer.export.estimate') }}</span>
        <button type="button" class="btn-secondary text-xs ml-auto" :disabled="!canEstimate" data-testid="export-estimate" @click="runEstimate">
          {{ estimating ? t('orgTransfer.export.estimating') : t('orgTransfer.export.estimate') }}
        </button>
      </div>
      <p v-if="estimateErr" class="text-xs text-red-400 mt-1">{{ estimateErr }}</p>
      <div v-if="estimate" class="text-xs text-gray-400 mt-2 space-y-0.5" data-testid="export-estimate-result">
        <p>{{ t('orgTransfer.export.estimateLine', { projects: estimate.projects ?? 0, versions: estimate.versions ?? 0, code: formatBytes(estimate.code_bytes) }) }}</p>
        <p v-if="estimate.binding_data_bytes">{{ t('orgTransfer.export.estimateData', { size: formatBytes(estimate.binding_data_bytes) }) }}</p>
        <p v-if="estimate.images?.count">{{ t('orgTransfer.export.estimateImages', { count: estimate.images.count }) }}</p>
        <p class="text-gray-200">{{ t('orgTransfer.export.estimateTotal', { size: formatBytes(estimate.total_bytes) }) }}</p>
        <p v-for="(w, i) in estimate.warnings ?? []" :key="i" class="text-amber-400">{{ w }}</p>
        <p v-for="b in (estimate.bindings ?? []).filter(x => x.data_supported === false)" :key="b.id" class="text-gray-500">{{ b.name }}: {{ t('orgTransfer.export.dataUnsupported') }}</p>
      </div>
    </div>

    <p v-if="startErr" class="text-sm text-red-400 mt-3" data-testid="export-error">{{ startErr }}</p>
    <div class="flex justify-end mt-4">
      <button type="button" class="btn-primary" :disabled="!canStart" data-testid="export-start" @click="start">
        <PackageOpen class="w-4 h-4" />{{ t('orgTransfer.export.start') }}
      </button>
    </div>
  </div>
</template>
