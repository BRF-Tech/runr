<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus, Trash2, Flame } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { useRuntimesStore } from '@/stores/runtimes'
import { sandboxAdminApi } from '../../api/sandboxes'
import { apiError } from '../../utils'
import TermText from '@/components/TermText.vue'

const props = defineProps({
  // GET /sandbox-regions rows (the regions section loads them)
  regions: { type: Array, default: () => [] },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()
const runtimesStore = useRuntimesStore()

const pools   = ref([])
const sizes   = ref({})
const loading = ref(false)
const error   = ref(null)
const adding  = ref(false)
const saving  = ref(false)

const form = reactive({ region: '', runtime: 'python', version: '', size: 2, cpu: '500m', memory: 512, disk: 1024 })

const canManage = computed(() => props.regions.some(r => r.can_manage))
const manageableRegions = computed(() => props.regions.filter(r => r.can_manage && r.enabled !== false))

const runtimes = computed(() => runtimesStore.runtimes
  .filter(r => !Array.isArray(r.regions) || !form.region || r.regions.includes(form.region)))
const versions = computed(() => (runtimes.value.find(r => r.id === form.runtime)?.versions ?? [])
  .filter(v => !(v.eol_at && new Date(v.eol_at) <= new Date())))

watch(manageableRegions, (list) => {
  if (!form.region && list.length) form.region = list[0].region.id
}, { immediate: true })

watch([versions, () => form.runtime], () => {
  if (!versions.value.find(v => v.version === form.version)) {
    form.version = versions.value.find(v => v.is_default)?.version ?? versions.value[0]?.version ?? ''
  }
}, { immediate: true })

async function load() {
  loading.value = true
  try {
    const { data } = await sandboxAdminApi.pools()
    pools.value = data?.data ?? []
    sizes.value = Object.fromEntries(pools.value.map(p => [p.id, p.size]))
    error.value = null
  } catch (e) {
    error.value = apiError(e, t('sandboxes.settings.pools.failed'))
  } finally {
    loading.value = false
  }
}

async function create() {
  saving.value = true
  try {
    await sandboxAdminApi.createPool({
      region:  form.region,
      runtime: form.runtime,
      version: form.version || undefined,
      size:    Number(form.size),
      cpu:     String(form.cpu).trim() || undefined,
      memory:  Number(form.memory) || undefined,
      disk:    Number(form.disk) || undefined,
    })
    toast.success(t('sandboxes.settings.pools.created'))
    adding.value = false
    await load()
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.pools.failed')))
  } finally {
    saving.value = false
  }
}

async function update(pool, payload) {
  try {
    const { data } = await sandboxAdminApi.updatePool(pool.id, payload)
    const updated = data?.data
    if (updated) {
      const idx = pools.value.findIndex(p => p.id === pool.id)
      if (idx !== -1) pools.value[idx] = updated
      sizes.value[pool.id] = updated.size
    }
    toast.success(t('sandboxes.settings.pools.updated'))
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.pools.failed')))
  }
}

async function remove(pool) {
  const ok = await confirm({
    title:        t('sandboxes.settings.pools.deleteTitle'),
    message:      t('sandboxes.settings.pools.deleteConfirm', { runtime: `${pool.runtime} ${pool.runtime_version ?? ''}`.trim(), region: pool.region?.name ?? '' }),
    destructive:  true,
    confirmLabel: t('sandboxes.actions.delete'),
  })
  if (!ok) return
  try {
    await sandboxAdminApi.deletePool(pool.id)
    toast.success(t('sandboxes.settings.pools.deleted'))
    pools.value = pools.value.filter(p => p.id !== pool.id)
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.settings.pools.failed')))
  }
}

onMounted(() => {
  load()
  runtimesStore.fetch()
})
</script>

<template>
  <section data-section="pools">
    <div class="flex items-start justify-between gap-3 mb-3">
      <div>
        <h4 class="text-sm font-semibold text-gray-100">{{ t('sandboxes.settings.pools.title') }}</h4>
        <p class="text-xs text-gray-400 max-w-2xl">{{ t('sandboxes.settings.pools.subtitle') }}</p>
      </div>
      <button v-if="canManage && !adding" class="btn-secondary flex items-center gap-1.5 text-xs shrink-0" data-action="add-pool" @click="adding = true">
        <Plus class="w-3.5 h-3.5" />{{ t('sandboxes.settings.pools.add') }}
      </button>
    </div>

    <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

    <!-- Add form -->
    <form v-if="adding" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 mb-3" data-pool-form @submit.prevent="create">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div>
          <label class="label">{{ t('sandboxes.settings.pools.region') }}</label>
          <select v-model="form.region" class="input" name="pool_region">
            <option v-for="r in manageableRegions" :key="r.region.id" :value="r.region.id">{{ r.region.name }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('sandboxes.settings.pools.runtime') }}</label>
          <select v-model="form.runtime" class="input" name="pool_runtime">
            <option v-for="r in runtimes" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('sandboxes.settings.pools.version') }}</label>
          <select v-model="form.version" class="input" name="pool_version">
            <option v-for="v in versions" :key="v.version" :value="v.version">{{ v.version }}</option>
          </select>
        </div>
        <div>
          <label class="label">{{ t('sandboxes.settings.pools.size') }}</label>
          <input v-model.number="form.size" class="input font-mono" type="number" min="0" name="pool_size" />
        </div>
        <div>
          <label class="label">{{ t('sandboxes.settings.pools.cpu') }}</label>
          <input v-model="form.cpu" class="input font-mono" type="text" name="pool_cpu" />
        </div>
        <div>
          <label class="label"><TermText path="sandboxes.settings.pools.memory" /></label>
          <input v-model.number="form.memory" class="input font-mono" type="number" min="64" name="pool_memory" />
        </div>
        <div>
          <label class="label"><TermText path="sandboxes.settings.pools.disk" /></label>
          <input v-model.number="form.disk" class="input font-mono" type="number" min="64" name="pool_disk" />
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button type="button" class="btn-secondary text-xs" @click="adding = false">{{ t('sandboxes.actions.cancel') }}</button>
        <button type="submit" class="btn-primary text-xs" :disabled="saving || !form.region || !form.runtime" data-action="create-pool">{{ t('sandboxes.actions.add') }}</button>
      </div>
    </form>

    <p v-if="!pools.length && !loading && !adding" class="text-sm text-gray-400">{{ t('sandboxes.settings.pools.empty') }}</p>

    <div class="space-y-2">
      <div
        v-for="p in pools" :key="p.id"
        :data-pool-row="p.id"
        class="rounded-xl border border-gray-800 bg-gray-900/60 px-4 py-3 flex flex-wrap items-center gap-4"
        :class="!p.is_enabled && 'opacity-60'"
      >
        <div class="flex items-center gap-2 min-w-[220px] flex-1">
          <Flame class="w-4 h-4 text-orange-400" />
          <div>
            <p class="text-sm font-semibold text-gray-100">{{ p.runtime }} <span class="font-mono text-xs text-gray-400">{{ p.runtime_version }}</span></p>
            <p class="text-[11px] font-mono text-gray-500">{{ p.region?.name }} · {{ p.sandbox_class || '—' }} · {{ p.cpu_m }}m · {{ p.memory_mb }} MiB · {{ p.disk_mb }} MiB</p>
          </div>
        </div>
        <div class="text-[11px] font-mono text-gray-400 w-44">
          <p><span class="text-emerald-400">{{ p.warm ?? 0 }}</span> {{ t('sandboxes.settings.pools.warm') }} · {{ p.starting ?? 0 }} {{ t('sandboxes.settings.pools.starting') }}</p>
          <p>{{ p.hits ?? 0 }} {{ t('sandboxes.settings.pools.hits') }} · {{ p.misses ?? 0 }} {{ t('sandboxes.settings.pools.misses') }}</p>
        </div>
        <div class="flex items-center gap-2">
          <label class="text-[11px] text-gray-500">{{ t('sandboxes.settings.pools.size') }}</label>
          <input v-model.number="sizes[p.id]" class="input font-mono w-20! h-8!" type="number" min="0" :disabled="!canManage" :name="`size-${p.id}`" />
          <button v-if="canManage" class="btn-secondary px-2.5! py-1! text-xs" :disabled="sizes[p.id] === p.size" data-action="save-pool" @click="update(p, { size: Number(sizes[p.id]) })">{{ t('sandboxes.actions.save') }}</button>
        </div>
        <label class="flex items-center gap-1.5 text-xs text-gray-300">
          <input type="checkbox" :checked="p.is_enabled" :disabled="!canManage" :name="`enabled-${p.id}`" @change="update(p, { is_enabled: $event.target.checked })" />
          {{ t('sandboxes.settings.pools.enabled') }}
        </label>
        <button v-if="canManage" class="text-gray-500 hover:text-red-400" :title="t('sandboxes.actions.delete')" @click="remove(p)">
          <Trash2 class="w-4 h-4" />
        </button>
      </div>
    </div>
  </section>
</template>
