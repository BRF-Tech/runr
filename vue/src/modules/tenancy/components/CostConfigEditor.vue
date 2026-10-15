<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { tenantsApi } from '../api/tenants'
import { knativeRegionsApi } from '@/api/knativeRegions'
import { Plus, Pencil, Settings, DollarSign, Cpu, HardDrive, Calendar, Archive, Trash2, Database } from 'lucide-vue-next'
import TermText from '@/components/TermText.vue'

const { t } = useI18n()
const configs = ref([])
const regions = ref([])
const loading = ref(false)
const editing = ref(null)

const currencies = ['USD', 'EUR', 'TRY', 'GBP', 'JPY', 'CHF', 'CAD', 'AUD']

const sortedConfigs = computed(() =>
  [...configs.value].sort((a, b) => {
    if (a.is_active !== b.is_active) return a.is_active ? -1 : 1
    return new Date(b.updated_at) - new Date(a.updated_at)
  })
)

const availableRegions = computed(() => {
  if (!editing.value) return []
  const usedIds = configs.value
    .filter(c => c.id !== editing.value.id && c.region_id && c.is_active)
    .map(c => c.region_id)
  return regions.value.filter(r => !usedIds.includes(r.id))
})

async function load() {
  loading.value = true
  try {
    const [{ data: c }, { data: r }] = await Promise.all([
      tenantsApi.costConfigs(),
      knativeRegionsApi.list(),
    ])
    configs.value = Array.isArray(c) ? c : []
    regions.value = Array.isArray(r) ? r : r?.data ?? []
  } catch { /* silent */ } finally {
    loading.value = false
  }
}

function startEdit(config) {
  if (!config.is_active) return
  editing.value = { ...config }
}

async function save() {
  if (!editing.value) return
  try {
    if (editing.value.id) {
      await tenantsApi.updateCostConfig(editing.value.id, editing.value)
    } else {
      await tenantsApi.createCostConfig(editing.value)
    }
    editing.value = null
    await load()
  } catch { /* silent */ }
}

function addNew() {
  editing.value = {
    region_id: null,
    cpu_price_per_mc_ms: '0.0000000001',
    memory_price_per_mib_ms: '0.0000000002',
    cpu_price_per_core_month: '20.00',
    memory_price_per_gb_month: '5.00',
    multiplier_http: '5.0',
    multiplier_queue: '2.0',
    multiplier_schedule: '1.0',
    kv_price_per_gb_hour: '0',
    object_price_per_gb_hour: '0',
    postgres_price_per_gb_hour: '0',
    postgres_instance_price_per_hour: '0',
    currency: 'USD',
  }
}

async function remove(id) {
  try {
    await tenantsApi.deleteCostConfig(id)
    await load()
  } catch { /* silent */ }
}

function openAddForTutorial() { addNew() }
defineExpose({ openAddForTutorial })

onMounted(load)
</script>

<template>
  <div>
    <!-- Header -->
    <div class="flex items-center justify-between mb-4">
      <p class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('cost.configTitle') }}</p>
      <button class="btn-primary text-xs flex items-center gap-1.5" @click="addNew">
        <Plus class="w-3.5 h-3.5" />{{ t('cost.addConfig') }}
      </button>
    </div>

    <!-- Edit form -->
    <div v-if="editing" class="rounded-xl border border-gray-800 bg-gray-900/60 px-5 py-4 mb-5 space-y-5">
      <p class="text-sm font-semibold text-gray-100">{{ t('cost.configTitle') }}</p>

      <!-- Region selector -->
      <div>
        <label class="label">{{ t('tenancy.region') }}</label>
        <select v-model="editing.region_id" class="input font-mono" :disabled="!!editing.id">
          <option :value="null">{{ t('cost.globalDefault') }}</option>
          <option v-for="r in availableRegions" :key="r.id" :value="r.id">{{ r.name }}</option>
          <option v-if="editing.id && editing.region_id" :value="editing.region_id" disabled>
            {{ configs.find(c => c.id === editing.id)?.region?.name }}
          </option>
        </select>
      </div>

      <!-- Monthly pricing -->
      <div data-tour="billing-monthly">
        <div class="flex items-center gap-2 mb-3">
          <Calendar class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
          <span class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('cost.monthlyPricing') }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label flex items-center gap-1.5">
              <Cpu class="w-3 h-3 text-sky-400" />{{ t('cost.cpuMonthly') }}
            </label>
            <input v-model="editing.cpu_price_per_core_month" type="text" class="input font-mono" />
          </div>
          <div>
            <label class="label flex items-center gap-1.5">
              <HardDrive class="w-3 h-3 text-violet-400" />{{ t('cost.memoryMonthly') }}
            </label>
            <input v-model="editing.memory_price_per_gb_month" type="text" class="input font-mono" />
          </div>
        </div>
      </div>

      <!-- Per-invocation pricing -->
      <div data-tour="billing-pricing">
        <div class="flex items-center gap-2 mb-3">
          <DollarSign class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
          <span class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('cost.invocationPricing') }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label flex items-center gap-1.5">
              <Cpu class="w-3 h-3 text-sky-400" />{{ t('cost.cpuPrice') }}
            </label>
            <input v-model="editing.cpu_price_per_mc_ms" type="text" class="input font-mono" />
          </div>
          <div>
            <label class="label flex items-center gap-1.5">
              <HardDrive class="w-3 h-3 text-violet-400" /><TermText path="cost.memoryPrice" />
            </label>
            <input v-model="editing.memory_price_per_mib_ms" type="text" class="input font-mono" />
          </div>
        </div>
      </div>

      <!-- Bindings: storage per GB-hour, dedicated Postgres instance hour (docs/bindings.md) -->
      <div data-tour="billing-bindings">
        <div class="flex items-center gap-2 mb-3">
          <Database class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
          <span class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('cost.bindingPricing') }}</span>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label">{{ t('cost.kvPrice') }}</label>
            <input v-model="editing.kv_price_per_gb_hour" type="text" class="input font-mono" />
          </div>
          <div>
            <label class="label">{{ t('cost.objectPrice') }}</label>
            <input v-model="editing.object_price_per_gb_hour" type="text" class="input font-mono" />
          </div>
          <div>
            <label class="label">{{ t('cost.postgresPrice') }}</label>
            <input v-model="editing.postgres_price_per_gb_hour" type="text" class="input font-mono" />
          </div>
          <div>
            <label class="label">{{ t('cost.postgresInstancePrice') }}</label>
            <input v-model="editing.postgres_instance_price_per_hour" type="text" class="input font-mono" />
          </div>
        </div>
      </div>

      <!-- Multipliers section -->
      <div data-tour="billing-multipliers">
        <div class="flex items-center gap-2 mb-3">
          <Settings class="w-3.5 h-3.5" style="color:rgb(var(--brand-500))" />
          <span class="text-xs text-gray-100 font-semibold uppercase tracking-wider">{{ t('cost.multiplier') }}</span>
        </div>
        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="label">HTTP</label>
            <div class="relative">
              <input v-model="editing.multiplier_http" type="text" class="input font-mono pr-8" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">×</span>
            </div>
          </div>
          <div>
            <label class="label">Queue</label>
            <div class="relative">
              <input v-model="editing.multiplier_queue" type="text" class="input font-mono pr-8" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">×</span>
            </div>
          </div>
          <div>
            <label class="label">Schedule</label>
            <div class="relative">
              <input v-model="editing.multiplier_schedule" type="text" class="input font-mono pr-8" />
              <span class="absolute right-3 top-1/2 -translate-y-1/2 text-[10px] text-gray-400">×</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Currency -->
      <div data-tour="billing-currency">
        <label class="label">{{ t('cost.currency') }}</label>
        <select v-model="editing.currency" class="input font-mono">
          <option v-for="c in currencies" :key="c" :value="c">{{ c }}</option>
        </select>
      </div>

      <div class="flex gap-2 pt-1">
        <button class="btn-primary text-xs" @click="save">{{ t('common.save') }}</button>
        <button class="btn-secondary text-xs" @click="editing = null">{{ t('common.cancel') }}</button>
      </div>
    </div>

    <!-- Loading -->
    <div v-if="loading" class="flex justify-center py-8">
      <div class="animate-spin h-5 w-5 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>

    <!-- Config list -->
    <div v-else-if="sortedConfigs.length" class="space-y-2">
      <div v-for="c in sortedConfigs" :key="c.id"
        class="rounded-xl border px-4 py-3 transition-all"
        :class="c.is_active
          ? 'border-gray-800 bg-gray-900/60 cursor-pointer hover:border-gray-700'
          : 'border-gray-800/50 bg-gray-950/40 opacity-60'"
        @click="startEdit(c)">
        <div class="flex items-center gap-3">
          <div class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 border"
            :class="c.is_active ? 'bg-gray-800/60 border-gray-700/60' : 'bg-gray-900/40 border-gray-800/40'">
            <component :is="c.is_active ? Settings : Archive" class="w-4 h-4 text-gray-400" />
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2">
              <span class="text-sm font-semibold text-gray-100">
                {{ c.region?.name ?? t('cost.globalDefault') }}
              </span>
              <span v-if="c.is_active"
                class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-emerald-900/40 border border-emerald-800/40 text-emerald-400">
                {{ t('cost.current') }}
              </span>
              <span v-else
                class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-gray-800/40 border border-gray-700/40 text-gray-500">
                {{ t('cost.archived') }}
              </span>
            </div>
            <div v-if="Number(c.cpu_price_per_core_month) > 0" class="text-[10px] text-gray-400 font-mono mt-0.5">
              {{ c.cpu_price_per_core_month }}/core · {{ c.memory_price_per_gb_month }}/GB
            </div>
          </div>
          <div class="flex items-center gap-2 text-[11px] text-gray-400 font-mono">
            <span class="px-1.5 py-0.5 rounded bg-gray-800 border border-gray-700">HTTP ×{{ c.multiplier_http }}</span>
            <span class="px-1.5 py-0.5 rounded bg-gray-800 border border-gray-700">{{ c.currency }}</span>
          </div>
          <Pencil v-if="c.is_active" class="w-3.5 h-3.5 text-gray-400 shrink-0" />
          <button @click.stop="remove(c.id)"
            class="p-1 rounded hover:bg-red-900/30 transition-colors shrink-0"
            :title="t('common.delete')">
            <Trash2 class="w-3.5 h-3.5 text-gray-500 hover:text-red-400" />
          </button>
        </div>
      </div>
    </div>

    <!-- Empty state -->
    <div v-else class="py-10 text-center">
      <div class="w-12 h-12 rounded-xl bg-gray-900 border border-gray-800 flex items-center justify-center mx-auto mb-3">
        <Settings class="w-6 h-6 text-gray-400" />
      </div>
      <p class="text-sm text-gray-400">{{ t('cost.noConfigs') }}</p>
    </div>
  </div>
</template>
