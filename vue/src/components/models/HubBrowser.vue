<script setup>
// Hugging Face Hub search through the RunR API (server-side proxy): text, task, library and sort.
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Search, Lock, Download, Heart } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { formatCount, HUB_LIBRARIES as LIBRARIES } from '@/utils/ai'

const { t } = useI18n()
const ai = useAiStore()

const emit = defineEmits(['open'])

const filters = reactive({ search: '', task: '', library: '', sort: 'downloads' })
const results   = ref([])
const nextCursor = ref(null)
const loading   = ref(false)
const searched  = ref(false)
const offline   = ref(null)   // message when the console has no Hub access (503)
const error     = ref(null)

const taskOptions = computed(() => ai.tasks.map(tk => tk.task))

async function search({ more = false } = {}) {
  loading.value = true
  error.value   = null
  try {
    const params = { limit: 20, sort: filters.sort }
    if (filters.search.trim()) params.search = filters.search.trim()
    if (filters.task) params.task = filters.task
    if (filters.library) params.library = filters.library
    if (more && nextCursor.value) params.cursor = nextCursor.value
    const { data } = await aiApi.hubSearch(params)
    const items = data?.data ?? []
    results.value = more ? [...results.value, ...items] : items
    nextCursor.value = data?.next_cursor ?? null
    offline.value = null
  } catch (e) {
    if (e?.response?.status === 503) offline.value = e.response.data?.message ?? t('ai.hub.offline')
    else error.value = e?.response?.data?.message ?? t('common.error')
    if (!more) results.value = []
  } finally {
    loading.value = false
    searched.value = true
  }
}

onMounted(() => search())
</script>

<template>
  <div class="space-y-4" data-testid="hub-browser">
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="search()">
      <div class="flex-1 min-w-[16rem]">
        <label class="label" for="hub-search">{{ t('ai.hub.search') }}</label>
        <div class="relative">
          <Search class="w-4 h-4 text-gray-500 absolute left-3 top-1/2 -translate-y-1/2" />
          <input id="hub-search" v-model="filters.search" class="input pl-9" :placeholder="t('ai.hub.searchPlaceholder')" data-testid="hub-search" />
        </div>
      </div>
      <div class="w-56">
        <label class="label" for="hub-task">{{ t('ai.hub.task') }}</label>
        <select id="hub-task" v-model="filters.task" class="input" data-testid="hub-task" @change="search()">
          <option value="">{{ t('ai.hub.anyTask') }}</option>
          <option v-for="task in taskOptions" :key="task" :value="task">{{ t(`ai.tasks.${task}`, task) }}</option>
        </select>
      </div>
      <div class="w-48">
        <label class="label" for="hub-library">{{ t('ai.hub.library') }}</label>
        <select id="hub-library" v-model="filters.library" class="input" @change="search()">
          <option value="">{{ t('ai.hub.anyLibrary') }}</option>
          <option v-for="lib in LIBRARIES" :key="lib" :value="lib">{{ lib }}</option>
        </select>
      </div>
      <div class="w-40">
        <label class="label" for="hub-sort">{{ t('ai.hub.sort') }}</label>
        <select id="hub-sort" v-model="filters.sort" class="input" @change="search()">
          <option v-for="s in ['downloads', 'likes', 'trending', 'updated']" :key="s" :value="s">{{ t(`ai.hub.sorts.${s}`) }}</option>
        </select>
      </div>
      <button type="submit" class="btn-primary h-[38px]" :disabled="loading" data-testid="hub-search-submit">{{ t('ai.hub.searchButton') }}</button>
    </form>

    <div v-if="offline" class="rounded-lg border border-amber-800/40 bg-amber-900/20 px-4 py-3 text-xs text-amber-300" data-testid="hub-offline">
      {{ offline }} {{ t('ai.hub.offlineHint') }}
    </div>
    <p v-else-if="error" class="text-xs text-red-400">{{ error }}</p>

    <div v-if="loading && !results.length" class="py-10 text-center text-xs text-gray-400">{{ t('common.loading') }}</div>
    <p v-else-if="searched && !results.length && !offline && !error" class="py-10 text-center text-xs text-gray-400">{{ t('ai.hub.noResults') }}</p>

    <div v-else class="grid grid-cols-1 lg:grid-cols-2 gap-2">
      <button v-for="m in results" :key="m.id" type="button"
        class="text-left rounded-lg border border-gray-800 bg-gray-900/50 hover:border-gray-700 px-4 py-3 transition-colors"
        :data-hub-model="m.id" @click="emit('open', m.id)">
        <div class="flex items-center gap-2">
          <span class="font-mono text-sm text-gray-100 truncate">{{ m.id }}</span>
          <Lock v-if="m.gated || m.private" class="w-3.5 h-3.5 text-amber-400 shrink-0" :title="t('ai.hub.gated')" />
        </div>
        <div class="flex flex-wrap items-center gap-3 mt-1.5 text-[11px] text-gray-400">
          <span v-if="m.task" class="px-1.5 py-0.5 rounded bg-gray-800 text-gray-300">{{ t(`ai.tasks.${m.task}`, m.task) }}</span>
          <span v-if="m.library" class="font-mono">{{ m.library }}</span>
          <span class="flex items-center gap-1"><Download class="w-3 h-3" />{{ formatCount(m.downloads) }}</span>
          <span class="flex items-center gap-1"><Heart class="w-3 h-3" />{{ formatCount(m.likes) }}</span>
        </div>
      </button>
    </div>

    <div v-if="nextCursor" class="flex justify-center">
      <button class="btn-secondary text-xs" :disabled="loading" @click="search({ more: true })">{{ t('ai.hub.more') }}</button>
    </div>
  </div>
</template>
