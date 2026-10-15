<script setup>
// Private connections of a project to Managed PostgreSQL through Skupper v2.
// The pasted link token and the credentials go straight to the region (Kubernetes); RunR's
// database keeps only name, routing key, port and env prefix.
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { Database, Plus, Trash2, RefreshCw } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'

const props = defineProps({ project: { type: Object, required: true } })
// Connecting and disconnecting: connections.manage on the project (docs/permissions.md)
const canManage = computed(() => usePermissionsStore().can('connections.manage', props.project.id))

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const bindings = ref([])
const statuses = reactive({})
const loading = ref(false)
const showForm = ref(false)
const saving = ref(false)
const errors = ref({})

const emptyForm = () => ({
  name: '', routing_key: '', port: 5432, env_prefix: '', sslmode: 'require',
  database: '', username: '', password: '', link_token: '',
})
const form = reactive(emptyForm())

async function load() {
  loading.value = true
  try {
    const { data } = await projectsApi.privateConnections(props.project.id)
    bindings.value = data?.data ?? []
    bindings.value.forEach(b => loadStatus(b))
  } catch { bindings.value = [] } finally {
    loading.value = false
  }
}

async function loadStatus(b) {
  statuses[b.id] = { state: 'loading' }
  try {
    const { data } = await projectsApi.privateConnectionStatus(props.project.id, b.id)
    statuses[b.id] = data?.status ?? { state: 'error' }
  } catch (e) {
    statuses[b.id] = { state: 'error', message: e?.response?.data?.message }
  }
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const payload = { ...form, env_prefix: form.env_prefix || null, port: Number(form.port) || 5432 }
    await projectsApi.createPrivateConnection(props.project.id, payload)
    toast.success(t('privateConnections.created'))
    Object.assign(form, emptyForm())
    showForm.value = false
    await load()
  } catch (e) {
    errors.value = e?.response?.data?.errors ?? {}
    toast.error(e?.response?.data?.message ?? t('privateConnections.createFailed'))
  } finally {
    saving.value = false
  }
}

async function remove(b) {
  const ok = await confirm({
    title: t('privateConnections.delete'),
    message: t('privateConnections.deleteConfirm', { name: b.name }),
    destructive: true,
    confirmLabel: t('privateConnections.delete'),
  })
  if (!ok) return
  try {
    await projectsApi.deletePrivateConnection(props.project.id, b.id)
    toast.success(t('privateConnections.deleted'))
    await load()
  } catch (e) {
    toast.error(e?.response?.data?.message ?? t('privateConnections.deleteFailed'))
  }
}

function stateCls(state) {
  const base = 'px-1.5 py-0.5 rounded text-[10px] font-medium border'
  return {
    ready: `${base} bg-emerald-900/40 border-emerald-800/40 text-emerald-400`,
    pending: `${base} bg-amber-900/40 border-amber-800/40 text-amber-400`,
    error: `${base} bg-red-900/40 border-red-800/40 text-red-400`,
  }[state] || `${base} bg-gray-800/40 border-gray-700/40 text-gray-400`
}

function fieldError(key) {
  return errors.value?.[key]?.[0]
}

onMounted(load)
</script>

<template>
  <div class="py-5 space-y-4">
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-sm font-semibold text-gray-100">{{ t('privateConnections.title') }}</h3>
        <p class="text-xs text-gray-400 mt-1 max-w-2xl">{{ t('privateConnections.intro') }}</p>
      </div>
      <button v-if="!showForm && canManage" type="button" class="btn btn-primary text-xs shrink-0" @click="showForm = true">
        <Plus class="w-3.5 h-3.5" /> {{ t('privateConnections.add') }}
      </button>
    </div>

    <!-- Add form -->
    <form v-if="showForm" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" @submit.prevent="save">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
          <label class="label">{{ t('privateConnections.name') }}</label>
          <input v-model="form.name" class="input font-mono text-xs" placeholder="orders-db" required />
          <p v-if="fieldError('name')" class="text-xs text-red-400 mt-1">{{ fieldError('name') }}</p>
        </div>
        <div>
          <label class="label"><TermText path="privateConnections.routingKey" /></label>
          <input v-model="form.routing_key" class="input font-mono text-xs" required />
          <p v-if="fieldError('routing_key')" class="text-xs text-red-400 mt-1">{{ fieldError('routing_key') }}</p>
        </div>
        <div>
          <label class="label">{{ t('privateConnections.envPrefix') }}</label>
          <input v-model="form.env_prefix" class="input font-mono text-xs" placeholder="ORDERS_" />
          <p v-if="fieldError('env_prefix')" class="text-xs text-red-400 mt-1">{{ fieldError('env_prefix') }}</p>
        </div>
        <div>
          <label class="label">{{ t('privateConnections.database') }}</label>
          <input v-model="form.database" class="input font-mono text-xs" required />
        </div>
        <div>
          <label class="label">{{ t('privateConnections.username') }}</label>
          <input v-model="form.username" class="input font-mono text-xs" autocomplete="off" required />
        </div>
        <div>
          <label class="label">{{ t('privateConnections.password') }}</label>
          <input v-model="form.password" type="password" class="input font-mono text-xs" autocomplete="new-password" required />
        </div>
        <div>
          <label class="label">{{ t('privateConnections.port') }}</label>
          <input v-model="form.port" type="number" min="1" max="65535" class="input font-mono text-xs" />
        </div>
        <div>
          <label class="label">{{ t('privateConnections.sslmode') }}</label>
          <select v-model="form.sslmode" class="input text-xs">
            <option v-for="m in ['require', 'prefer', 'verify-ca', 'verify-full', 'allow', 'disable']" :key="m" :value="m">{{ m }}</option>
          </select>
        </div>
      </div>
      <div>
        <label class="label">{{ t('privateConnections.linkToken') }}</label>
        <textarea v-model="form.link_token" class="input h-32 resize-y font-mono text-xs leading-relaxed"
          :placeholder="t('privateConnections.linkTokenHint')" required />
        <p v-if="fieldError('link_token')" class="text-xs text-red-400 mt-1">{{ fieldError('link_token') }}</p>
        <p v-if="fieldError('project')" class="text-xs text-red-400 mt-1">{{ fieldError('project') }}</p>
      </div>
      <p class="text-[11px] text-gray-400">{{ t('privateConnections.secretNote') }}</p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn btn-secondary text-xs" @click="showForm = false">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn btn-primary text-xs" :disabled="saving">{{ t('privateConnections.connect') }}</button>
      </div>
    </form>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <div v-else-if="!bindings.length && !showForm" class="py-10 text-center">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Database class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('privateConnections.empty') }}</p>
    </div>

    <div v-else class="space-y-2">
      <div v-for="b in bindings" :key="b.id" class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <Database class="w-4 h-4 text-sky-400 shrink-0" />
            <span class="text-sm font-semibold text-gray-100">{{ b.name }}</span>
            <span :class="stateCls(statuses[b.id]?.state)">{{ t('privateConnections.state.' + (statuses[b.id]?.state ?? 'loading')) }}</span>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" class="p-1 text-gray-400 hover:text-gray-200" :title="t('privateConnections.refresh')" @click="loadStatus(b)">
              <RefreshCw class="w-3.5 h-3.5" />
            </button>
            <button v-if="canManage" type="button" class="p-1 text-gray-400 hover:text-red-400" :title="t('privateConnections.delete')" @click="remove(b)">
              <Trash2 class="w-3.5 h-3.5" />
            </button>
          </div>
        </div>
        <div class="mt-1.5 text-[11px] text-gray-400 font-mono flex flex-wrap gap-x-4 gap-y-1">
          <span>{{ b.host }}:{{ b.port }}</span>
          <span>{{ t('privateConnections.routingKey') }}: {{ b.routing_key }}</span>
          <span>sslmode={{ b.sslmode }}</span>
          <span>{{ b.env_prefix }}DATABASE_URL, {{ b.env_prefix }}PGHOST …</span>
        </div>
        <div v-if="statuses[b.id] && statuses[b.id].state !== 'loading'" class="mt-1 text-[11px] text-gray-500 font-mono flex flex-wrap gap-x-4">
          <span v-if="statuses[b.id].link">{{ t('privateConnections.link') }}: {{ statuses[b.id].link.status }}<template v-if="statuses[b.id].link.message"> — {{ statuses[b.id].link.message }}</template></span>
          <span v-if="statuses[b.id].listener">{{ t('privateConnections.listener') }}: {{ statuses[b.id].listener.status }}<template v-if="!statuses[b.id].listener.has_matching_connector"> ({{ t('privateConnections.noConnector') }})</template></span>
          <span v-if="statuses[b.id].message" class="text-red-400">{{ statuses[b.id].message }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
