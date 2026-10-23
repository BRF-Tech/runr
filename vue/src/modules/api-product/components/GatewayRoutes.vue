<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { projectsApi } from '@/api/projects'
import { environmentsApi } from '@/api/environments'
import { apiProductApi } from '../api/apiProduct'

/**
 * The gateway's routes and how callers authenticate on each: public, an API key of a consumer,
 * or a JWT the issuer's JWKS verifies; "only through the gateway" closes the own URL of the
 * workload the route leads to. A route leads to a project's own workload (production) or to one of
 * its environments (docs/environments.md#gateway-routes) that runs in the gateway's region.
 */
const props = defineProps({ gateway: { type: Object, required: true } })
const emit = defineEmits(['updated'])
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

// ── New route: project → its environments in the gateway's region → path, and its
// authentication: a key or JWT route is created with it in one call, never public for a moment ──
const adding = ref(false)
const projects = ref([])
const environments = ref([])
const blankRoute = () => ({
  project_id: '', environment: 'production', path: '',
  auth_mode: 'public', gateway_only: false, jwks_url: '', issuer: '', audience: '', subject_claim: 'sub', require_consumer: false,
})
const newRoute = reactive(blankRoute())
const addErrors = ref({})
const addSaving = ref(false)

// production and the environments that run in the gateway's region
const routable = computed(() => environments.value.filter((e) => (e.knative_region_id ?? e.region?.id) === props.gateway.knative_region_id))

async function openAdd() {
  adding.value = true
  addErrors.value = {}
  if (!projects.value.length) {
    try {
      const { data } = await projectsApi.list()
      projects.value = data.data ?? data ?? []
    } catch { projects.value = [] }
  }
}

watch(() => newRoute.project_id, async (id) => {
  environments.value = []
  newRoute.environment = 'production'
  if (!id) return
  try {
    const { data } = await environmentsApi.list(id)
    environments.value = data.data ?? []
    const first = routable.value[0]
    if (first) newRoute.environment = first.is_primary ? 'production' : first.name
  } catch { environments.value = [] }
})

async function addRoute() {
  addSaving.value = true
  addErrors.value = {}
  try {
    const payload = { project_id: newRoute.project_id, path: newRoute.path }
    if (newRoute.environment && newRoute.environment !== 'production') payload.environment = newRoute.environment
    // the route's authentication comes with it (a public route sends none)
    if (newRoute.auth_mode !== 'public') payload.auth_mode = newRoute.auth_mode
    if (newRoute.auth_mode === 'jwt') payload.jwt = jwtOf(newRoute)
    if (newRoute.gateway_only) payload.gateway_only = true
    await apiProductApi.addRoute(props.gateway.id, payload)
    const { data } = await apiProductApi.gateway(props.gateway.id)
    emit('updated', data.data)
    toast.success(t('apiProduct.routes.added', { path: newRoute.path }))
    Object.assign(newRoute, blankRoute())
    adding.value = false
  } catch (e) {
    addErrors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    addSaving.value = false
  }
}

async function removeRoute(route) {
  const ok = await confirm({
    title: t('apiProduct.routes.remove'),
    message: t('apiProduct.routes.removeConfirm', { path: route.path }),
    destructive: true,
    confirmLabel: t('apiProduct.routes.remove'),
  })
  if (!ok) return
  try {
    await apiProductApi.removeRoute(props.gateway.id, route.id)
    const { data } = await apiProductApi.gateway(props.gateway.id)
    emit('updated', data.data)
    toast.success(t('apiProduct.routes.removed', { path: route.path }))
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

const addErr = (field) => (addErrors.value?.[field] || [])[0]

const MODES = ['public', 'key', 'jwt']

/** The JWT settings of a form (the new route's, or a route's draft) as the API takes them. */
function jwtOf(form) {
  return {
    jwks_url: form.jwks_url,
    issuer: form.issuer || null,
    audience: form.audience || null,
    subject_claim: form.subject_claim || null,
    require_consumer: form.require_consumer,
  }
}

const drafts = reactive({})
const saving = ref(null)
const errors = ref({})

function draftOf(route) {
  return {
    auth_mode: route.auth_mode || 'public',
    gateway_only: !!route.gateway_only,
    jwks_url: route.jwt?.jwks_url ?? '',
    issuer: route.jwt?.issuer ?? '',
    audience: route.jwt?.audience ?? '',
    subject_claim: route.jwt?.subject_claim ?? 'sub',
    require_consumer: !!route.jwt?.require_consumer,
  }
}

watch(() => props.gateway, (g) => {
  for (const r of g.routes ?? []) drafts[r.id] = draftOf(r)
}, { immediate: true })

async function save(route) {
  const d = drafts[route.id]
  saving.value = route.id
  errors.value = {}
  const payload = { auth_mode: d.auth_mode, gateway_only: d.gateway_only }
  if (d.auth_mode === 'jwt') payload.jwt = jwtOf(d)
  try {
    await apiProductApi.updateRoute(props.gateway.id, route.id, payload)
    const { data } = await apiProductApi.gateway(props.gateway.id)
    emit('updated', data.data)
    toast.success(t('apiProduct.routes.saved', { path: route.path }))
  } catch (e) {
    errors.value = { [route.id]: e.response?.data?.errors ?? {} }
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    saving.value = null
  }
}

const err = (routeId, field) => (errors.value?.[routeId]?.[field] || [])[0]
</script>

<template>
  <div class="space-y-3" data-test="api-routes">
    <div class="flex items-start justify-between gap-3">
      <p class="text-[11px] text-gray-500">{{ t('apiProduct.routes.hint') }}</p>
      <button v-if="!adding" type="button" class="btn-secondary text-xs px-3 py-1.5 shrink-0" data-test="route-add-open" @click="openAdd">
        <Plus class="w-3.5 h-3.5" />{{ t('apiProduct.routes.add') }}
      </button>
    </div>

    <!-- New route: to a project's own workload or to one of its environments in this region -->
    <form v-if="adding" class="bg-gray-900 border border-gray-800 rounded-md p-3 space-y-3" data-test="route-add" @submit.prevent="addRoute">
      <div class="grid gap-3 md:grid-cols-3">
        <div>
          <label class="label">{{ t('apiProduct.routes.project') }}</label>
          <select v-model="newRoute.project_id" class="input" required data-test="route-add-project">
            <option value="" disabled>—</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
          <p v-if="addErr('project_id')" class="text-[11px] text-red-400 mt-1">{{ addErr('project_id') }}</p>
        </div>
        <div>
          <label class="label">{{ t('apiProduct.routes.environment') }}</label>
          <select v-model="newRoute.environment" class="input" :disabled="!routable.length" data-test="route-add-environment">
            <option v-for="e in routable" :key="e.id" :value="e.is_primary ? 'production' : e.name">{{ e.name }}</option>
          </select>
          <p v-if="newRoute.project_id && environments.length && !routable.length" class="text-[11px] text-amber-400/80 mt-1" data-test="route-add-no-environment">
            {{ t('apiProduct.routes.noEnvironmentHere') }}
          </p>
          <p v-if="addErr('environment')" class="text-[11px] text-red-400 mt-1" data-test="route-add-environment-error">{{ addErr('environment') }}</p>
        </div>
        <div>
          <label class="label">{{ t('apiProduct.routes.path') }}</label>
          <input v-model="newRoute.path" class="input font-mono" placeholder="/orders" required data-test="route-add-path">
          <p v-if="addErr('path')" class="text-[11px] text-red-400 mt-1" data-test="route-add-path-error">{{ addErr('path') }}</p>
        </div>

        <!-- its authentication, created with it -->
        <div>
          <label class="label">{{ t('apiProduct.routes.auth') }}</label>
          <select v-model="newRoute.auth_mode" class="input" data-test="route-add-auth">
            <option v-for="m in MODES" :key="m" :value="m">{{ t(`apiProduct.routes.modes.${m}`) }}</option>
          </select>
          <p v-if="addErr('auth_mode')" class="text-[11px] text-red-400 mt-1">{{ addErr('auth_mode') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-200 md:col-span-2 md:mt-5">
          <input v-model="newRoute.gateway_only" type="checkbox" data-test="route-add-gateway-only">
          {{ t('apiProduct.routes.gatewayOnly') }}
        </label>
        <p v-if="addErr('gateway_only')" class="text-[11px] text-red-400 md:col-span-3" data-test="route-add-gateway-only-error">{{ addErr('gateway_only') }}</p>

        <template v-if="newRoute.auth_mode === 'jwt'">
          <div class="md:col-span-3">
            <label class="label">{{ t('apiProduct.routes.jwksUrl') }}</label>
            <input v-model="newRoute.jwks_url" class="input font-mono" placeholder="https://id.example.com/.well-known/jwks.json" required data-test="route-add-jwks">
            <p v-if="addErr('jwt.jwks_url')" class="text-[11px] text-red-400 mt-1" data-test="route-add-jwks-error">{{ addErr('jwt.jwks_url') }}</p>
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.issuer') }}</label>
            <input v-model="newRoute.issuer" class="input font-mono" placeholder="https://id.example.com">
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.audience') }}</label>
            <input v-model="newRoute.audience" class="input font-mono">
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.subjectClaim') }}</label>
            <input v-model="newRoute.subject_claim" class="input font-mono" placeholder="sub">
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-200 md:col-span-3">
            <input v-model="newRoute.require_consumer" type="checkbox">
            {{ t('apiProduct.routes.requireConsumer') }}
          </label>
        </template>
      </div>
      <p v-if="newRoute.auth_mode !== 'public'" class="text-[11px] text-gray-500" data-test="route-add-auth-hint">
        {{ t(`apiProduct.routes.explain.${newRoute.auth_mode}`) }} {{ t('apiProduct.routes.createdClosed') }}
      </p>
      <div class="flex justify-end gap-2">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="adding = false">{{ t('common.cancel') }}</button>
        <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="addSaving || !routable.length" data-test="route-add-save">{{ t('apiProduct.routes.add') }}</button>
      </div>
    </form>

    <p v-if="!gateway.routes?.length" class="text-xs text-gray-500">{{ t('apiProduct.routes.none') }}</p>

    <div v-for="route in gateway.routes" :key="route.id" class="bg-gray-900 border border-gray-800 rounded-md p-3 space-y-3" :data-route="route.path">
      <div class="flex flex-wrap items-center gap-2">
        <span class="font-mono text-sm text-gray-100">{{ route.path }}</span>
        <span class="text-[11px] text-gray-500">→ {{ route.project?.name }}</span>
        <span
          v-if="route.environment"
          class="px-1.5 py-0.5 rounded-sm text-[10px] font-medium border border-sky-800/40 bg-sky-900/30 text-sky-300"
          data-test="route-environment"
        >{{ route.environment }}</span>
        <span v-if="route.methods?.length" class="text-[11px] text-gray-500">{{ route.methods.join(', ') }}</span>
        <a :href="route.url" target="_blank" rel="noopener" class="ml-auto text-[11px] text-sky-400 hover:text-sky-300 font-mono">{{ route.url }}</a>
        <button type="button" class="p-1 text-gray-400 hover:text-red-400" :title="t('apiProduct.routes.remove')" data-test="route-remove" @click="removeRoute(route)">
          <Trash2 class="w-3.5 h-3.5" />
        </button>
      </div>

      <div v-if="drafts[route.id]" class="grid gap-3 md:grid-cols-3">
        <div>
          <label class="label">{{ t('apiProduct.routes.auth') }}</label>
          <select v-model="drafts[route.id].auth_mode" class="input" data-test="route-auth">
            <option v-for="m in MODES" :key="m" :value="m">{{ t(`apiProduct.routes.modes.${m}`) }}</option>
          </select>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-200 md:col-span-2 md:mt-5">
          <input v-model="drafts[route.id].gateway_only" type="checkbox" data-test="route-gateway-only">
          {{ t('apiProduct.routes.gatewayOnly') }}
        </label>

        <template v-if="drafts[route.id].auth_mode === 'jwt'">
          <div class="md:col-span-3">
            <label class="label">{{ t('apiProduct.routes.jwksUrl') }}</label>
            <input v-model="drafts[route.id].jwks_url" class="input font-mono" placeholder="https://id.example.com/.well-known/jwks.json" data-test="route-jwks">
            <p v-if="err(route.id, 'jwt.jwks_url')" class="text-[11px] text-red-400 mt-1" data-test="route-jwks-error">{{ err(route.id, 'jwt.jwks_url') }}</p>
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.issuer') }}</label>
            <input v-model="drafts[route.id].issuer" class="input font-mono" placeholder="https://id.example.com">
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.audience') }}</label>
            <input v-model="drafts[route.id].audience" class="input font-mono">
          </div>
          <div>
            <label class="label">{{ t('apiProduct.routes.subjectClaim') }}</label>
            <input v-model="drafts[route.id].subject_claim" class="input font-mono" placeholder="sub">
          </div>
          <label class="flex items-center gap-2 text-sm text-gray-200 md:col-span-3">
            <input v-model="drafts[route.id].require_consumer" type="checkbox">
            {{ t('apiProduct.routes.requireConsumer') }}
          </label>
        </template>
      </div>

      <p v-if="drafts[route.id]?.auth_mode !== 'public'" class="text-[11px] text-gray-500">{{ t(`apiProduct.routes.explain.${drafts[route.id]?.auth_mode}`) }}</p>
      <p v-if="drafts[route.id]?.gateway_only" class="text-[11px] text-amber-400/80">{{ t('apiProduct.routes.gatewayOnlyHint') }}</p>

      <button type="button" class="btn-primary text-xs px-3 py-1.5" :disabled="saving === route.id" data-test="route-save" @click="save(route)">
        {{ t('apiProduct.common.save') }}
      </button>
    </div>
  </div>
</template>
