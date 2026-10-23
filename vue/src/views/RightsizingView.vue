<script setup>
// The organization's open rightsizing recommendations (GET /rightsizing, docs/monitoring.md#rightsizing),
// largest monthly saving first — the projects the member may read the metrics of. Apply (a new
// version, the next deploy runs it) and dismiss need projects.update on the project.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Gauge } from 'lucide-vue-next'
import AppHeader from '@/components/AppHeader.vue'
import { rightsizingApi } from '@/api/rightsizing'
import { usePermissionsStore } from '@/stores/permissions'
import { useToast } from '@/composables/useToast'
import { useConfirm } from '@/composables/useConfirm'
import { freedText, money, reasonsText, savingsText, sizeText } from '@/utils/rightsizing'

const { t, locale } = useI18n()
const perms = usePermissionsStore()
const toast = useToast()
const { confirm } = useConfirm()

const items = ref([])
const summary = ref(null)
const loading = ref(true)
const busy = ref(null)

const summaryText = computed(() => {
  const s = summary.value
  if (!s || !s.count) return ''
  if (s.monthly_savings !== null && s.monthly_savings !== undefined && s.currency) {
    return t('rightsizing.page.summary', { count: s.count, amount: money(s.monthly_savings, s.currency, locale.value) })
  }
  return t('rightsizing.page.summaryResources', { count: s.count, memory: s.freed_memory_mb ?? 0, cpu: s.freed_cpu_m ?? 0 })
})

async function load() {
  loading.value = true
  try {
    const { data } = await rightsizingApi.list()
    items.value = Array.isArray(data?.data) ? data.data : []
    summary.value = data?.summary ?? null
  } catch {
    items.value = []
    summary.value = null
  } finally {
    loading.value = false
  }
}

function drop(projectId) {
  items.value = items.value.filter(r => r.project_id !== projectId)
  if (summary.value) summary.value = { ...summary.value, count: items.value.length }
}

async function apply(r) {
  const ok = await confirm({
    title: t('rightsizing.title'),
    message: t('rightsizing.confirmApply', { project: r.project?.name ?? r.project_id, current: sizeText(r.current), recommended: sizeText(r.recommended) }),
    confirmLabel: t('rightsizing.apply'),
  })
  if (!ok) return
  busy.value = r.project_id
  try {
    const { data } = await rightsizingApi.apply(r.project_id)
    toast.success(t('rightsizing.applied', { version: data.version?.label ?? '' }), 8000)
    drop(r.project_id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}

async function dismiss(r, snoozeDays = null) {
  busy.value = r.project_id
  try {
    await rightsizingApi.dismiss(r.project_id, snoozeDays)
    toast.success(snoozeDays ? t('rightsizing.snoozed') : t('rightsizing.dismissed'))
    drop(r.project_id)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('common.error'))
  } finally {
    busy.value = null
  }
}

onMounted(load)
defineExpose({ load, apply, dismiss })
</script>

<template>
  <div class="min-h-screen flex flex-col">
    <AppHeader />
    <main class="flex-1 w-full px-6 py-8 space-y-4">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <Gauge class="w-5 h-5 text-gray-400" />
          <h1 class="text-xl font-semibold text-gray-100 tracking-wide">{{ t('rightsizing.page.title') }}</h1>
        </div>
        <p v-if="summaryText" class="text-sm text-gray-300" data-test="rightsizing-summary">{{ summaryText }}</p>
      </div>
      <p class="text-sm text-gray-400">{{ t('rightsizing.page.subtitle') }}</p>

      <div v-if="loading" class="card h-32 animate-pulse bg-gray-900/50" />

      <div v-else-if="items.length === 0" class="card text-center text-sm text-gray-400 py-12" data-test="rightsizing-empty">
        {{ t('rightsizing.page.empty') }}
      </div>

      <div v-else class="card p-0 overflow-x-auto">
        <table class="w-full text-sm" data-test="rightsizing-table">
          <thead>
            <tr class="text-left text-xs text-gray-400 border-b border-gray-800">
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.project') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.now') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.recommended') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.why') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.frees') }}</th>
              <th class="px-4 py-2 font-medium">{{ t('rightsizing.page.monthly') }}</th>
              <th class="px-4 py-2" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in items" :key="r.id" class="border-b border-gray-800/60 last:border-0 align-top" :data-rightsizing-row="r.project?.name ?? r.project_id">
              <td class="px-4 py-3">
                <RouterLink :to="`/projects/${r.project_id}`" class="text-gray-100 hover:underline">{{ r.project?.name ?? r.project_id }}</RouterLink>
                <p v-if="r.blocked" class="text-[11px] text-amber-400 mt-0.5">{{ t('rightsizing.blocked', { message: r.blocked.message }) }}</p>
              </td>
              <td class="px-4 py-3 font-mono text-gray-300 whitespace-nowrap">{{ sizeText(r.current) }}</td>
              <td class="px-4 py-3 font-mono text-sky-300 whitespace-nowrap">{{ sizeText(r.recommended) }}</td>
              <td class="px-4 py-3 text-gray-300">{{ reasonsText(t, r.reasons) }}</td>
              <td class="px-4 py-3 text-gray-300">{{ freedText(t, r) ?? '—' }}</td>
              <td class="px-4 py-3 text-gray-300" data-test="rightsizing-row-savings">{{ savingsText(t, r, locale) ?? '—' }}</td>
              <td class="px-4 py-3">
                <div v-if="perms.can('projects.update', r.project_id)" class="flex items-center justify-end gap-2">
                  <button type="button" class="btn-primary text-xs" :disabled="busy === r.project_id || !!r.blocked" data-test="rightsizing-row-apply" @click="apply(r)">
                    {{ t('rightsizing.apply') }}
                  </button>
                  <button type="button" class="btn-secondary text-xs" :disabled="busy === r.project_id" data-test="rightsizing-row-snooze" @click="dismiss(r, 30)">
                    {{ t('rightsizing.snooze') }}
                  </button>
                  <button type="button" class="text-xs text-gray-400 hover:text-gray-200 px-2" :disabled="busy === r.project_id" data-test="rightsizing-row-dismiss" @click="dismiss(r)">
                    {{ t('rightsizing.dismiss') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </main>
  </div>
</template>
