<script setup>
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import PromotionList from '@/components/project/PromotionList.vue'
import { environmentsApi } from '@/api/environments'
import { useApprovalsStore } from '@/stores/approvals'
import { useJobEvents } from '@/composables/useJobEvents'

/**
 * The approvals inbox (docs/environments.md#approvals): promotions of every project the user sees
 * that wait for an approver — approve (with an optional comment) or reject; the one who requested
 * a promotion withdraws it instead. "All" lists every recent promotion. It reloads when a
 * promotion changes anywhere (the environment.promotion broadcast).
 */
const { t } = useI18n()
const approvals = useApprovalsStore()

const filter     = ref('pending_approval')
const promotions = ref([])
const loading    = ref(true)
const error      = ref(null)

async function load() {
  loading.value = true
  error.value   = null
  try {
    const { data } = await environmentsApi.inbox({ status: filter.value })
    promotions.value = data.data ?? []
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

async function onChanged() {
  await load()
  approvals.fetchCount()
}

onMounted(load)
watch(filter, load)
// A promotion was requested, decided or ended elsewhere (PromotionNotifier's broadcast)
useJobEvents({ 'environment.promotion': () => load() })
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />

    <main class="flex-1 w-full max-w-5xl mx-auto px-6 py-6" data-testid="approvals-view">
      <div class="flex items-start justify-between gap-4 mb-5">
        <div>
          <h1 class="text-lg font-semibold text-gray-100">{{ t('environments.approvals.title') }}</h1>
          <p class="text-xs text-gray-400 mt-1 max-w-2xl">{{ t('environments.approvals.intro') }}</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
          <select v-model="filter" class="input w-auto text-xs" data-testid="approvals-filter" :aria-label="t('environments.approvals.filter')">
            <option value="pending_approval">{{ t('environments.approvals.pending') }}</option>
            <option value="all">{{ t('environments.approvals.all') }}</option>
          </select>
          <button class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load">
            <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />
          </button>
        </div>
      </div>

      <div v-if="loading && !promotions.length" class="flex items-center justify-center py-12">
        <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
      </div>
      <div v-else-if="error" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3">
        <p class="text-xs text-red-400">{{ error }}</p>
      </div>
      <template v-else>
        <p v-if="!promotions.length && filter === 'pending_approval'" class="text-sm text-gray-400 py-12 text-center" data-testid="approvals-empty">
          {{ t('environments.approvals.empty') }}
        </p>
        <PromotionList v-else :promotions="promotions" show-project @changed="onChanged" />
      </template>
    </main>
  </div>
</template>
