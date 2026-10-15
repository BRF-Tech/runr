<script setup>
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RefreshCw, Square } from 'lucide-vue-next'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { sandboxesApi } from '../api/sandboxes'
import { apiError, formatDateTime } from '../utils'

const props = defineProps({
  sandboxId: { type: String, required: true },
  disabled:  { type: Boolean, default: false },
})

const { t } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const processes = ref([])
const loading   = ref(false)
const error     = ref(null)

async function load() {
  if (props.disabled) return
  loading.value = true
  try {
    const { data } = await sandboxesApi.processes.list(props.sandboxId)
    processes.value = data?.data ?? []
    error.value = null
  } catch (e) {
    error.value = apiError(e, t('sandboxes.processes.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function kill(p) {
  const ok = await confirm({
    title:        t('sandboxes.processes.killTitle'),
    message:      t('sandboxes.processes.killConfirm', { pid: p.pid, command: p.command }),
    destructive:  true,
    confirmLabel: t('sandboxes.actions.kill'),
  })
  if (!ok) return
  try {
    await sandboxesApi.processes.kill(props.sandboxId, p.id)
    toast.success(t('sandboxes.processes.killed', { pid: p.pid }))
    await load()
  } catch (e) {
    toast.error(apiError(e, t('sandboxes.list.actionFailed')))
  }
}

const statusStyle = {
  running: 'text-emerald-400',
  exited:  'text-gray-400',
  killed:  'text-orange-400',
}

watch(() => props.disabled, (d) => { if (!d) load() })
onMounted(load)
</script>

<template>
  <div data-processes>
    <p v-if="disabled" class="text-sm text-gray-400 py-6 text-center">{{ t('sandboxes.detail.notRunning') }}</p>
    <template v-else>
      <div class="flex justify-end mb-2">
        <button type="button" class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" :title="t('sandboxes.actions.refresh')" @click="load">
          <RefreshCw class="w-3.5 h-3.5" :class="loading && 'animate-spin'" />
        </button>
      </div>
      <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>
      <p v-if="!processes.length && !loading" class="text-sm text-gray-400 py-6 text-center">{{ t('sandboxes.processes.empty') }}</p>
      <div v-else class="rounded-lg border border-gray-800 overflow-hidden">
        <table class="w-full text-xs">
          <thead>
            <tr class="text-left text-[10px] uppercase tracking-wider text-gray-500 border-b border-gray-800 bg-gray-900/60">
              <th class="px-3 py-2 font-medium w-16">{{ t('sandboxes.processes.pid') }}</th>
              <th class="px-3 py-2 font-medium">{{ t('sandboxes.processes.command') }}</th>
              <th class="px-3 py-2 font-medium w-24">{{ t('sandboxes.processes.status') }}</th>
              <th class="px-3 py-2 font-medium w-20">{{ t('sandboxes.processes.exitCode') }}</th>
              <th class="px-3 py-2 font-medium w-40">{{ t('sandboxes.processes.started') }}</th>
              <th class="px-3 py-2 w-10" />
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in processes" :key="p.id" :data-process-row="p.id" class="border-b border-gray-800/60 last:border-0">
              <td class="px-3 py-1.5 font-mono text-gray-300">{{ p.pid }}</td>
              <td class="px-3 py-1.5 font-mono text-gray-200 truncate max-w-[320px]" :title="p.command">{{ p.command }}</td>
              <td class="px-3 py-1.5 font-mono" :class="statusStyle[p.status] ?? 'text-gray-400'">{{ t(`sandboxes.processes.statuses.${p.status}`, p.status) }}</td>
              <td class="px-3 py-1.5 font-mono text-gray-400">{{ p.exit_code ?? '—' }}</td>
              <td class="px-3 py-1.5 font-mono text-gray-500">{{ formatDateTime(p.started_at) }}</td>
              <td class="px-3 py-1.5 text-right">
                <button v-if="p.status === 'running'" type="button" class="text-gray-500 hover:text-red-400" :title="t('sandboxes.actions.kill')" @click="kill(p)">
                  <Square class="w-3.5 h-3.5" />
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>
