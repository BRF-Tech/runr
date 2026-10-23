<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Ban, KeyRound, RotateCw } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { apiProductApi } from '../api/apiProduct'
import KeySecret from './KeySecret.vue'

/**
 * Keys of one consumer. A new or rotated key is shown once (KeySecret); afterwards only its
 * prefix. A key can be limited to some routes and methods, get a plan of its own and expire.
 */
const props = defineProps({
  consumer: { type: Object, required: true },
  gateway: { type: Object, required: true },
  plans: { type: Array, default: () => [] },
})
const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']
const OVERLAPS = [{ minutes: 0, key: 'none' }, { minutes: 60, key: 'hour' }, { minutes: 1440, key: 'day' }, { minutes: 10080, key: 'week' }]

const keys = ref([])
const loading = ref(true)
const busy = ref(false)
const secret = ref(null)
const overlap = ref(1440)
const form = reactive({ name: '', plan_id: '', expires_at: '', routes: {} })

async function load() {
  loading.value = true
  try {
    const { data } = await apiProductApi.keys(props.consumer.id)
    keys.value = data.data ?? []
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    loading.value = false
  }
}

/** form.routes: routeId → null (every method) or a list of methods; no entry = not scoped. */
function toggleRoute(routeId) {
  if (routeId in form.routes) delete form.routes[routeId]
  else form.routes[routeId] = null
}

function toggleMethod(routeId, method) {
  const list = form.routes[routeId] ?? []
  form.routes[routeId] = list.includes(method) ? list.filter((m) => m !== method) : [...list, method]
  if (!form.routes[routeId].length) form.routes[routeId] = null
}

async function create() {
  busy.value = true
  try {
    const payload = { name: form.name }
    const scopes = Object.entries(form.routes).map(([route_id, methods]) => ({ route_id, ...(methods ? { methods } : {}) }))
    if (scopes.length) payload.scopes = scopes
    if (form.plan_id) payload.plan_id = form.plan_id
    if (form.expires_at) payload.expires_at = new Date(form.expires_at).toISOString()
    const { data } = await apiProductApi.createKey(props.consumer.id, payload)
    secret.value = { key: data.data.key, name: data.data.name }
    Object.assign(form, { name: '', plan_id: '', expires_at: '', routes: {} })
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  } finally {
    busy.value = false
  }
}

async function rotate(key) {
  const ok = await confirm({
    title: t('apiProduct.keys.rotateTitle'),
    message: overlap.value
      ? t('apiProduct.keys.rotateMessage', { prefix: key.prefix, overlap: t(`apiProduct.keys.overlaps.${OVERLAPS.find((o) => o.minutes === overlap.value)?.key ?? 'day'}`) })
      : t('apiProduct.keys.rotateNowMessage', { prefix: key.prefix }),
    confirmLabel: t('apiProduct.keys.rotate'),
  })
  if (!ok) return
  try {
    const { data } = await apiProductApi.rotateKey(key.id, { overlap_minutes: overlap.value })
    secret.value = { key: data.data.key, name: data.data.name }
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

async function revoke(key) {
  const ok = await confirm({
    title: t('apiProduct.keys.revokeTitle'),
    message: t('apiProduct.keys.revokeMessage', { prefix: key.prefix }),
    destructive: true,
    confirmLabel: t('apiProduct.keys.revoke'),
  })
  if (!ok) return
  try {
    await apiProductApi.revokeKey(key.id)
    await load()
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('apiProduct.common.error'))
  }
}

const routePath = (id) => props.gateway.routes?.find((r) => r.id === id)?.path ?? id
const fmt = (iso) => (iso ? new Date(iso).toLocaleString() : '—')

onMounted(load)
</script>

<template>
  <div class="p-3 space-y-3" data-test="api-keys">
    <KeySecret v-if="secret" :value="secret.key" :name="secret.name" @close="secret = null" />

    <div v-if="loading" class="text-xs text-gray-500">{{ t('apiProduct.common.loading') }}</div>
    <p v-else-if="!keys.length" class="text-xs text-gray-500">{{ t('apiProduct.keys.none') }}</p>
    <table v-else class="w-full text-xs">
      <thead class="text-gray-500 text-left">
        <tr>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.name') }}</th>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.prefix') }}</th>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.routes') }}</th>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.statusCol') }}</th>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.expires') }}</th>
          <th class="py-1 font-medium">{{ t('apiProduct.keys.lastUsed') }}</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr v-for="k in keys" :key="k.id" class="border-t border-gray-800" :data-key="k.prefix">
          <td class="py-1.5 text-gray-200">{{ k.name }}</td>
          <td class="py-1.5 font-mono text-gray-400">{{ k.prefix }}…</td>
          <td class="py-1.5 text-gray-400">{{ k.scopes?.length ? k.scopes.map((s) => routePath(s.route_id) + (s.methods ? ` (${s.methods.join(', ')})` : '')).join('; ') : t('apiProduct.keys.allRoutes') }}</td>
          <td class="py-1.5" :class="k.status === 'active' ? 'text-emerald-400' : 'text-gray-500'" data-test="key-status">{{ t(`apiProduct.keys.status.${k.status}`) }}</td>
          <td class="py-1.5 text-gray-400">{{ fmt(k.expires_at) }}</td>
          <td class="py-1.5 text-gray-400">{{ fmt(k.last_used_at) }}</td>
          <td class="py-1.5 text-right whitespace-nowrap">
            <template v-if="k.status === 'active' && consumer.can_manage">
              <button type="button" class="text-gray-400 hover:text-sky-300 mr-2" :title="t('apiProduct.keys.rotate')" data-test="key-rotate" @click="rotate(k)"><RotateCw class="w-3.5 h-3.5" /></button>
              <button type="button" class="text-gray-400 hover:text-red-400" :title="t('apiProduct.keys.revoke')" data-test="key-revoke" @click="revoke(k)"><Ban class="w-3.5 h-3.5" /></button>
            </template>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="keys.some((k) => k.status === 'active') && consumer.can_manage" class="flex items-center gap-2 text-[11px] text-gray-500">
      {{ t('apiProduct.keys.overlap') }}
      <select v-model.number="overlap" class="input h-7! w-36! text-xs" data-test="key-overlap">
        <option v-for="o in OVERLAPS" :key="o.key" :value="o.minutes">{{ t(`apiProduct.keys.overlaps.${o.key}`) }}</option>
      </select>
    </div>

    <form v-if="consumer.can_manage" class="space-y-2 border-t border-gray-800 pt-3" data-test="key-form" @submit.prevent="create">
      <div class="grid gap-3 md:grid-cols-4 items-end">
        <div>
          <label class="label">{{ t('apiProduct.keys.name') }}</label>
          <input v-model="form.name" class="input" required maxlength="120" placeholder="production" data-test="key-name">
        </div>
        <div>
          <label class="label">{{ t('apiProduct.keys.ownPlan') }}</label>
          <select v-model="form.plan_id" class="input">
            <option value="">{{ t('apiProduct.keys.consumerPlan') }}</option>
            <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('apiProduct.keys.expires') }}</label>
          <input v-model="form.expires_at" type="date" class="input">
        </div>
        <button type="submit" class="btn-primary text-xs px-3 py-2 inline-flex items-center gap-1 justify-center" :disabled="busy || !form.name" data-test="key-create">
          <KeyRound class="w-3.5 h-3.5" />{{ t('apiProduct.keys.create') }}
        </button>
      </div>
      <div class="space-y-1">
        <p class="text-[11px] text-gray-500">{{ t('apiProduct.keys.scopeHint') }}</p>
        <div v-for="r in gateway.routes" :key="r.id" class="flex flex-wrap items-center gap-2 text-xs text-gray-300">
          <label class="flex items-center gap-1.5"><input type="checkbox" :checked="r.id in form.routes" :data-test="`key-route-${r.path}`" @change="toggleRoute(r.id)"> <span class="font-mono">{{ r.path }}</span></label>
          <template v-if="r.id in form.routes">
            <label v-for="m in METHODS" :key="m" class="flex items-center gap-1 text-[11px] text-gray-400">
              <input type="checkbox" :checked="form.routes[r.id]?.includes(m)" @change="toggleMethod(r.id, m)">{{ m }}
            </label>
          </template>
        </div>
      </div>
    </form>
  </div>
</template>
