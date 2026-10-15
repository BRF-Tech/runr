<script setup>
// Region → Data services (docs/bindings.md "Region setup"): the services bindings run on —
// KV Redis (RunR's managed KV Redis or the region Redis), object storage (Garage, or an external
// S3 endpoint), Postgres (CloudNativePG + the shared cluster). Changes need
// regions.data_services.manage; install / uninstall are queued, the tab polls until done.
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { bindingsApi } from '@/api/bindings'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { statusBadgeClass } from '@/utils/formatters'
import { bindingStatusClass } from '@/utils/bindings'
import { KeyRound, HardDrive, Database, RefreshCw, AlertTriangle, Activity } from 'lucide-vue-next'

const props = defineProps({
  regionId: { type: String, required: true },
  region:   { type: Object, default: null },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const perms = usePermissionsStore()

const SERVICES = ['kv', 'object', 'postgres']
const ICONS = { kv: KeyRound, object: HardDrive, postgres: Database }

const state = ref(null)
const apiManage = ref(true)
const loading = ref(false)
const checking = ref(false)
const busy = reactive({})
const errors = reactive({})

const forms = reactive({
  kv: { mode: 'managed', maxmemory_mb: 512, storage: '2Gi' },
  object: {
    mode: 'managed', storage: '20Gi',
    provider: 's3', endpoint: '', region: '', path_style: true, vendor: 'Other', bucket_prefix: '',
    admin_endpoint: '', admin_token: '', access_key_id: '', secret_access_key: '',
  },
  postgres: { instances: 1, storage: '10Gi', backups: false },
})

const canManage = computed(() => apiManage.value && perms.can('regions.data_services.manage'))
const services = computed(() => state.value?.services ?? {})
const regionRedis = computed(() => state.value?.region_redis ?? {})
const objectReady = computed(() => services.value.object?.status === 'ready')

let pollTimer = null

function syncForms() {
  const kv = services.value.kv
  if (kv?.installed) {
    forms.kv.mode = kv.mode ?? 'managed'
    if (kv.config?.maxmemory_mb) forms.kv.maxmemory_mb = kv.config.maxmemory_mb
    if (kv.config?.storage) forms.kv.storage = kv.config.storage
  }
  const obj = services.value.object
  if (obj?.installed) {
    forms.object.mode = obj.mode ?? 'managed'
    if (obj.config?.storage) forms.object.storage = obj.config.storage
    if (obj.mode === 'external') {
      forms.object.provider = obj.config?.provider ?? 's3'
      forms.object.endpoint = obj.config?.endpoint ?? ''
      forms.object.region = obj.config?.s3_region ?? ''
      forms.object.bucket_prefix = obj.config?.bucket_prefix ?? ''
    }
  }
  const pg = services.value.postgres
  if (pg?.installed) {
    if (pg.config?.instances) forms.postgres.instances = pg.config.instances
    if (pg.config?.storage) forms.postgres.storage = pg.config.storage
    forms.postgres.backups = !!pg.config?.backups
  }
}

async function load(live = false) {
  if (!state.value) loading.value = true
  if (live) checking.value = true
  try {
    const { data } = await bindingsApi.dataServices(props.regionId, live)
    const first = !state.value
    state.value = data?.data ?? null
    apiManage.value = data?.abilities?.manage !== false
    if (first) syncForms()
  } catch {
    if (!state.value) state.value = null
  } finally {
    loading.value = false
    checking.value = false
  }
  clearTimeout(pollTimer)
  if (Object.values(services.value).some(s => ['installing', 'uninstalling'].includes(s?.status))) {
    pollTimer = setTimeout(() => load(), 3000)
  }
}

function payload(service) {
  const f = forms[service]
  if (service === 'kv') {
    return f.mode === 'region' ? { mode: 'region' } : { mode: 'managed', maxmemory_mb: Number(f.maxmemory_mb) || undefined, storage: f.storage || undefined }
  }
  if (service === 'object') {
    if (f.mode === 'managed') return { mode: 'managed', storage: f.storage || undefined }
    const body = {
      mode: 'external', provider: f.provider, endpoint: f.endpoint.trim(), region: f.region.trim() || undefined,
      path_style: !!f.path_style, bucket_prefix: f.bucket_prefix.trim() || undefined,
    }
    if (f.provider === 'garage') {
      body.admin_endpoint = f.admin_endpoint.trim()
      if (f.admin_token) body.admin_token = f.admin_token
    } else {
      body.vendor = f.vendor.trim() || undefined
      if (f.access_key_id) body.access_key_id = f.access_key_id.trim()
      if (f.secret_access_key) body.secret_access_key = f.secret_access_key
    }
    return body
  }
  return { instances: Number(f.instances) || 1, storage: f.storage || undefined, backups: !!f.backups }
}

async function install(service) {
  busy[service] = true
  errors[service] = null
  try {
    await bindingsApi.installDataService(props.regionId, service, payload(service))
    toast.success(t('bindings.services.installQueued', { service: t(`bindings.services.${service}`) }))
    if (service === 'object') {
      forms.object.admin_token = ''
      forms.object.secret_access_key = ''
    }
    await load()
  } catch (e) {
    const data = e?.response?.data
    errors[service] = data?.errors ? Object.values(data.errors).flat().join(' ') : (data?.message ?? t('bindings.services.failed'))
    toast.error(data?.message ?? t('bindings.services.failed'))
  } finally {
    busy[service] = false
  }
}

async function uninstall(service) {
  const name = t(`bindings.services.${service}`)
  const ok = await confirm({
    title: t('bindings.services.uninstallTitle', { service: name }),
    message: t('bindings.services.uninstallConfirm', { service: name }),
    destructive: true,
    confirmLabel: t('bindings.services.uninstall'),
  })
  if (!ok) return
  busy[service] = true
  errors[service] = null
  try {
    await bindingsApi.uninstallDataService(props.regionId, service)
    toast.success(t('bindings.services.uninstallQueued', { service: name }))
    await load()
  } catch (e) {
    errors[service] = e?.response?.data?.message ?? t('bindings.services.failed')
    toast.error(errors[service])
  } finally {
    busy[service] = false
  }
}

function statusOf(service) {
  return services.value[service]?.status ?? 'not_installed'
}

function inFlight(service) {
  return ['installing', 'uninstalling'].includes(statusOf(service))
}

onMounted(() => load())
onBeforeUnmount(() => clearTimeout(pollTimer))
</script>

<template>
  <div class="space-y-4" data-data-services>
    <div class="flex items-start justify-between gap-4">
      <div>
        <h3 class="text-sm font-semibold text-gray-100">{{ t('bindings.services.title') }}</h3>
        <p class="text-xs text-gray-400 mt-1 max-w-2xl">{{ t('bindings.services.intro', { namespace: state?.namespace ?? 'runr-data' }) }}</p>
      </div>
      <button type="button" class="btn btn-secondary text-xs shrink-0" :disabled="checking || !state" data-action="live" @click="load(true)">
        <Activity class="w-3.5 h-3.5" :class="checking && 'animate-pulse'" /> {{ t('bindings.services.live') }}
      </button>
    </div>

    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <p v-else-if="!state" class="text-xs text-gray-400 py-6 text-center">{{ t('bindings.services.unavailable') }}</p>

    <template v-else>
      <p v-if="!canManage" class="text-[11px] text-gray-500">{{ t('bindings.services.manageOnly') }}</p>

      <div v-for="service in SERVICES" :key="service" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" :data-service="service">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div class="flex items-center gap-2">
            <component :is="ICONS[service]" class="w-4 h-4 text-sky-400" />
            <span class="text-sm font-semibold text-gray-100">{{ t(`bindings.services.${service}`) }}</span>
            <span :class="statusBadgeClass(statusOf(service))" data-service-status>{{ t(`bindings.services.status.${statusOf(service)}`) }}</span>
            <span v-if="services[service]?.installed && services[service]?.mode" class="text-[11px] text-gray-400">{{ t(`bindings.services.modes.${services[service].mode}`) }}</span>
            <span v-if="service === 'object' && services.object?.config?.scoped_keys === false"
              class="text-[10px] px-1.5 py-0.5 rounded border border-amber-800/40 bg-amber-900/30 text-amber-300" :title="t('bindings.services.sharedKeyHint')" data-shared-key>
              {{ t('bindings.services.sharedKey') }}
            </span>
          </div>
          <span class="text-[11px] text-gray-500">{{ t('bindings.services.bindingsCount', { count: services[service]?.bindings_count ?? 0 }) }}</span>
        </div>

        <p class="text-[11px] text-gray-400">{{ t(`bindings.services.${service}Hint`) }}</p>
        <p v-if="services[service]?.status_message" class="text-[11px] font-mono whitespace-pre-wrap"
          :class="statusOf(service) === 'error' ? 'text-red-400' : 'text-gray-400'" data-service-message>{{ services[service].status_message }}</p>

        <!-- Health (live check) -->
        <div v-if="services[service]?.health" class="flex items-center gap-2 text-[11px]">
          <span class="text-gray-500">{{ t('bindings.services.health') }}</span>
          <span :class="bindingStatusClass(services[service].health.state)">{{ services[service].health.state }}</span>
          <span v-if="services[service].health.message" class="text-gray-400">{{ services[service].health.message }}</span>
        </div>

        <!-- Config summary -->
        <div v-if="services[service]?.config" class="text-[11px] text-gray-400 font-mono flex flex-wrap gap-x-4 gap-y-1">
          <span v-if="services[service].config.host">{{ services[service].config.host }}</span>
          <span v-if="services[service].config.endpoint">{{ services[service].config.endpoint }}</span>
          <span v-if="services[service].config.storage">{{ t('bindings.services.storage') }}: {{ services[service].config.storage }}</span>
          <span v-if="services[service].config.instances">{{ t('bindings.services.instances') }}: {{ services[service].config.instances }}</span>
          <span v-if="service === 'kv' && services.kv.config.acl === false">{{ t('bindings.details.mode_db') }}</span>
          <span v-if="service === 'postgres' && services.postgres.config.backups">{{ t('bindings.services.backups') }}</span>
        </div>

        <!-- Forms -->
        <fieldset v-if="canManage" class="space-y-3" :disabled="busy[service] || inFlight(service)">
          <!-- KV -->
          <template v-if="service === 'kv'">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="label">{{ t('bindings.services.mode') }}</label>
                <select v-model="forms.kv.mode" class="input text-xs" data-field="kv_mode">
                  <option value="managed">{{ t('bindings.services.modes.managed') }}</option>
                  <option value="region">{{ t('bindings.services.modes.region') }}</option>
                </select>
              </div>
              <template v-if="forms.kv.mode === 'managed'">
                <div>
                  <label class="label">{{ t('bindings.services.maxmemory_mb') }}</label>
                  <input v-model="forms.kv.maxmemory_mb" type="number" min="64" class="input font-mono text-xs" data-field="kv_maxmemory" />
                </div>
                <div>
                  <label class="label">{{ t('bindings.services.storage') }}</label>
                  <input v-model="forms.kv.storage" class="input font-mono text-xs" placeholder="2Gi" data-field="kv_storage" />
                </div>
              </template>
            </div>
            <p v-if="forms.kv.mode === 'region' && !regionRedis.has_password" class="text-xs text-amber-400 flex items-center gap-1" data-kv-password-warning>
              <AlertTriangle class="w-3.5 h-3.5" /> {{ t('bindings.services.regionRedisPassword') }}
            </p>
            <p v-else-if="forms.kv.mode === 'region'" class="text-[11px] text-gray-400">{{ t('bindings.services.regionRedisHint') }}</p>
          </template>

          <!-- Object storage -->
          <template v-else-if="service === 'object'">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="label">{{ t('bindings.services.mode') }}</label>
                <select v-model="forms.object.mode" class="input text-xs" data-field="object_mode">
                  <option value="managed">{{ t('bindings.services.modes.managed') }}</option>
                  <option value="external">{{ t('bindings.services.modes.external') }}</option>
                </select>
              </div>
              <div v-if="forms.object.mode === 'managed'">
                <label class="label">{{ t('bindings.services.storage') }}</label>
                <input v-model="forms.object.storage" class="input font-mono text-xs" placeholder="20Gi" data-field="object_storage" />
              </div>
              <template v-else>
                <div>
                  <label class="label">{{ t('bindings.services.provider') }}</label>
                  <select v-model="forms.object.provider" class="input text-xs" data-field="object_provider">
                    <option value="s3">{{ t('bindings.services.providers.s3') }}</option>
                    <option value="garage">{{ t('bindings.services.providers.garage') }}</option>
                  </select>
                </div>
                <div>
                  <label class="label">{{ t('bindings.services.endpoint') }}</label>
                  <input v-model="forms.object.endpoint" class="input font-mono text-xs" placeholder="https://s3.example.com" data-field="object_endpoint" />
                </div>
                <div>
                  <label class="label">{{ t('bindings.services.s3Region') }}</label>
                  <input v-model="forms.object.region" class="input font-mono text-xs" placeholder="us-east-1" data-field="object_region" />
                </div>
                <div>
                  <label class="label">{{ t('bindings.services.bucketPrefix') }}</label>
                  <input v-model="forms.object.bucket_prefix" class="input font-mono text-xs" placeholder="acme-" data-field="object_bucket_prefix" />
                </div>
                <div class="flex items-end">
                  <label class="flex items-center gap-2 text-xs text-gray-300">
                    <input v-model="forms.object.path_style" type="checkbox" data-field="object_path_style" />
                    {{ t('bindings.services.pathStyle') }}
                  </label>
                </div>
                <template v-if="forms.object.provider === 'garage'">
                  <div>
                    <label class="label">{{ t('bindings.services.adminEndpoint') }}</label>
                    <input v-model="forms.object.admin_endpoint" class="input font-mono text-xs" placeholder="https://garage-admin.example.com" data-field="object_admin_endpoint" />
                  </div>
                  <div>
                    <label class="label">{{ t('bindings.services.adminToken') }}</label>
                    <input v-model="forms.object.admin_token" type="password" autocomplete="new-password" class="input font-mono text-xs" data-field="object_admin_token" />
                  </div>
                </template>
                <template v-else>
                  <div>
                    <label class="label">{{ t('bindings.services.vendor') }}</label>
                    <input v-model="forms.object.vendor" class="input font-mono text-xs" placeholder="AWS" data-field="object_vendor" />
                  </div>
                  <div>
                    <label class="label">{{ t('bindings.services.accessKeyId') }}</label>
                    <input v-model="forms.object.access_key_id" autocomplete="off" class="input font-mono text-xs" data-field="object_access_key_id" />
                  </div>
                  <div>
                    <label class="label">{{ t('bindings.services.secretAccessKey') }}</label>
                    <input v-model="forms.object.secret_access_key" type="password" autocomplete="new-password" class="input font-mono text-xs" data-field="object_secret_access_key" />
                  </div>
                </template>
              </template>
            </div>
            <p v-if="forms.object.mode === 'external' && services.object?.installed" class="text-[11px] text-gray-500">{{ t('bindings.services.keepSecret') }}</p>
            <p v-if="forms.object.mode === 'external' && forms.object.provider === 's3'" class="text-[11px] text-amber-400">{{ t('bindings.services.sharedKeyHint') }}</p>
          </template>

          <!-- Postgres -->
          <template v-else>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
              <div>
                <label class="label">{{ t('bindings.services.instances') }}</label>
                <input v-model="forms.postgres.instances" type="number" min="1" max="5" class="input font-mono text-xs" data-field="pg_instances" />
              </div>
              <div>
                <label class="label">{{ t('bindings.services.storage') }}</label>
                <input v-model="forms.postgres.storage" class="input font-mono text-xs" placeholder="10Gi" data-field="pg_storage" />
              </div>
              <div class="flex items-end">
                <label class="flex items-center gap-2 text-xs" :class="objectReady ? 'text-gray-300' : 'text-gray-500'">
                  <input v-model="forms.postgres.backups" type="checkbox" :disabled="!objectReady" data-field="pg_backups" />
                  {{ t('bindings.services.backups') }}
                </label>
              </div>
            </div>
            <p v-if="!objectReady" class="text-[11px] text-gray-500">{{ t('bindings.services.backupsNeedObject') }}</p>
          </template>

          <p v-if="errors[service]" class="text-xs text-red-400" data-service-error>{{ errors[service] }}</p>

          <div class="flex justify-end gap-2">
            <button v-if="services[service]?.installed" type="button" class="btn btn-secondary text-xs text-red-400" data-action="uninstall"
              :disabled="busy[service] || inFlight(service)" @click="uninstall(service)">
              {{ t('bindings.services.uninstall') }}
            </button>
            <button type="button" class="btn btn-primary text-xs" data-action="install"
              :disabled="busy[service] || inFlight(service)" @click="install(service)">
              <RefreshCw v-if="inFlight(service)" class="w-3.5 h-3.5 animate-spin" />
              {{ services[service]?.installed ? t('bindings.services.reconfigure') : t('bindings.services.install') }}
            </button>
          </div>
        </fieldset>
      </div>
    </template>
  </div>
</template>
