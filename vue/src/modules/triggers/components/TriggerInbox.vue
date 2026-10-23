<script setup>
// Inbox of a webhook / platform_event trigger (replay.manage, like request replay): the stored
// events by status with paging, one event with its headers and body (text or base64), status,
// attempts and last error; replay one event or every failed one.
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowLeft, RefreshCw, RotateCcw, X } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { formatTime } from '@/modules/workflows/utils/format'
import { triggersApi } from '../api/triggers'
import { EVENT_STATUSES, errorMessage, eventBody, eventStatusClass, formatSize } from '../utils/triggers'
import CopyField from './CopyField.vue'

const props = defineProps({
  project: { type: Object, required: true },
  trigger: { type: Object, required: true },
})
const emit = defineEmits(['back'])

const PER_PAGE = 25

const { t, locale } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const canReplay = computed(() => perms.can('replay.manage', props.project.id))

const status    = ref('')
const page      = ref(1)
const events    = ref([])
const meta      = ref({ current_page: 1, last_page: 1, total: 0 })
const loading   = ref(true)
const error     = ref(null)
const selected  = ref(null)       // event detail
const bodyMode  = ref('text')     // text | base64
const replaying = ref(false)
const replayingAll = ref(false)

const triggerKey = computed(() => props.trigger.id ?? props.trigger.name)
const body = computed(() => eventBody(selected.value, bodyMode.value))
const headers = computed(() => Object.entries(selected.value?.headers ?? {}))

async function load() {
  loading.value = true
  try {
    const params = { per_page: PER_PAGE }
    if (status.value) params.status = status.value
    if (page.value > 1) params.page = page.value
    const { data } = await triggersApi.events(props.project.id, triggerKey.value, params)
    events.value = data?.data ?? []
    meta.value   = data?.meta ?? meta.value
    error.value  = null
  } catch (e) {
    error.value = errorMessage(e, t('triggers.inbox.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function open(event) {
  try {
    const { data } = await triggersApi.event(props.project.id, triggerKey.value, event.id)
    selected.value = data?.data ?? null
    bodyMode.value = 'text'
  } catch (e) {
    toast.error(errorMessage(e, t('triggers.inbox.loadFailed')))
  }
}

function patchRow(updated) {
  if (!updated) return
  events.value = events.value.map(e => (e.id === updated.id ? { ...e, ...updated } : e))
  if (selected.value?.id === updated.id) selected.value = { ...selected.value, ...updated }
}

async function replay(event) {
  replaying.value = true
  try {
    const { data } = await triggersApi.replayEvent(props.project.id, triggerKey.value, event.id)
    patchRow(data?.data)
    toast.success(t('triggers.inbox.replayed'))
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    replaying.value = false
  }
}

async function replayFailed() {
  const ok = await confirm({
    title:        t('triggers.inbox.replayAllTitle'),
    message:      t('triggers.inbox.replayAllConfirm'),
    confirmLabel: t('triggers.inbox.replayAll'),
  })
  if (!ok) return
  replayingAll.value = true
  try {
    const { data } = await triggersApi.replayEvents(props.project.id, triggerKey.value, { status: 'failed' })
    toast.success(t('triggers.inbox.replayedMany', { n: data?.replayed ?? 0 }))
    await load()
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  } finally {
    replayingAll.value = false
  }
}

function go(next) {
  if (next < 1 || next > (meta.value.last_page ?? 1)) return
  page.value = next
  load()
}

watch(status, () => { page.value = 1; selected.value = null; load() })
onMounted(() => { if (canReplay.value) load() })
</script>

<template>
  <div class="space-y-4" data-test="trigger-inbox">
    <div class="flex flex-wrap items-start gap-3">
      <button type="button" class="btn-secondary text-xs px-2.5 py-1" data-action="back" @click="emit('back')">
        <ArrowLeft class="w-3 h-3" />{{ t('triggers.inbox.back') }}
      </button>
      <div class="min-w-0 flex-1">
        <h3 class="text-sm font-semibold text-gray-100">{{ t('triggers.inbox.title', { name: trigger.name }) }}</h3>
        <p class="text-xs text-gray-400 mt-1 max-w-3xl">{{ t('triggers.inbox.intro') }}</p>
        <div v-if="trigger.url" class="mt-2 max-w-xl"><CopyField :value="trigger.url" test-id="inbox-url" /></div>
      </div>
    </div>

    <p v-if="!canReplay" class="text-xs text-gray-400" data-test="inbox-no-permission">{{ t('triggers.inbox.noPermission') }}</p>

    <template v-else>
      <div class="flex flex-wrap items-center gap-2">
        <select v-model="status" class="input w-auto! py-1.5! text-xs" data-field="status">
          <option value="">{{ t('triggers.inbox.allStatuses') }}</option>
          <option v-for="s in EVENT_STATUSES" :key="s" :value="s">{{ t(`triggers.inbox.statuses.${s}`) }}</option>
        </select>
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="loading" @click="load">
          <RefreshCw class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" />{{ t('triggers.inbox.refresh') }}
        </button>
        <button type="button" class="btn-secondary text-xs px-3 py-1.5 ml-auto" :disabled="replayingAll" data-action="replay-failed" @click="replayFailed">
          <RotateCcw class="w-3.5 h-3.5" />{{ t('triggers.inbox.replayAll') }}
        </button>
      </div>

      <p v-if="error" class="text-xs text-red-400">{{ error }}</p>

      <div class="grid gap-4" :class="selected ? 'lg:grid-cols-2' : ''">
        <div class="rounded-md border border-gray-800 overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-gray-900/60 text-left text-gray-400">
              <tr>
                <th class="font-medium px-3 py-2">{{ t('triggers.inbox.received') }}</th>
                <th class="font-medium px-3 py-2">{{ t('triggers.inbox.type') }}</th>
                <th class="font-medium px-3 py-2">{{ t('triggers.inbox.status') }}</th>
                <th class="font-medium px-3 py-2 text-right">{{ t('triggers.inbox.attempts') }}</th>
                <th class="font-medium px-3 py-2 text-right">{{ t('triggers.inbox.size') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!loading && !events.length"><td colspan="5" class="px-3 py-8 text-center text-gray-400" data-test="inbox-empty">{{ t('triggers.inbox.empty') }}</td></tr>
              <tr
                v-for="e in events" :key="e.id"
                class="border-t border-gray-800 hover:bg-gray-900/60 cursor-pointer"
                :class="selected?.id === e.id ? 'bg-gray-900/80' : ''"
                :data-event="e.id" :data-status="e.status"
                @click="open(e)"
              >
                <td class="px-3 py-2 text-gray-300 whitespace-nowrap">{{ formatTime(e.received_at, locale) }}</td>
                <td class="px-3 py-2">
                  <span class="font-mono text-gray-100">{{ e.type }}</span>
                  <span v-if="e.subject" class="block text-[11px] text-gray-400 font-mono truncate max-w-64">{{ e.subject }}</span>
                </td>
                <td class="px-3 py-2"><span class="px-1.5 py-0.5 rounded-sm border text-[11px]" :class="eventStatusClass(e.status)">{{ t(`triggers.inbox.statuses.${e.status}`) }}</span></td>
                <td class="px-3 py-2 text-right font-mono text-gray-300">{{ e.attempts }}<span v-if="e.replays" class="text-gray-500"> (+{{ e.replays }})</span></td>
                <td class="px-3 py-2 text-right font-mono text-gray-300">{{ formatSize(e.size) }}</td>
              </tr>
            </tbody>
          </table>
          <div v-if="(meta.last_page ?? 1) > 1" class="flex items-center justify-end gap-2 text-xs text-gray-400 p-2 border-t border-gray-800">
            <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page <= 1" data-action="previous" @click="go(meta.current_page - 1)">{{ t('triggers.inbox.previous') }}</button>
            <span>{{ t('triggers.inbox.page', { page: meta.current_page, last: meta.last_page }) }}</span>
            <button type="button" class="btn-secondary text-xs px-3 py-1" :disabled="meta.current_page >= meta.last_page" data-action="next" @click="go(meta.current_page + 1)">{{ t('triggers.inbox.next') }}</button>
          </div>
        </div>

        <!-- Event detail -->
        <div v-if="selected" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3 min-w-0" data-test="event-detail">
          <div class="flex items-center gap-2">
            <span class="font-mono text-sm text-gray-100 truncate">{{ selected.type }}</span>
            <span class="px-1.5 py-0.5 rounded-sm border text-[11px]" :class="eventStatusClass(selected.status)" data-test="detail-status">{{ t(`triggers.inbox.statuses.${selected.status}`) }}</span>
            <button type="button" class="ml-auto text-gray-400 hover:text-gray-100" :aria-label="t('triggers.inbox.detail.close')" @click="selected = null"><X class="w-4 h-4" /></button>
          </div>

          <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
            <dt class="text-gray-500">ID</dt><dd class="font-mono text-gray-300 truncate">{{ selected.id }}</dd>
            <template v-if="selected.delivery_id"><dt class="text-gray-500">{{ t('triggers.inbox.deliveryId') }}</dt><dd class="font-mono text-gray-300 truncate">{{ selected.delivery_id }}</dd></template>
            <template v-if="selected.subject"><dt class="text-gray-500">{{ t('triggers.inbox.subject') }}</dt><dd class="font-mono text-gray-300 truncate">{{ selected.subject }}</dd></template>
            <dt class="text-gray-500">{{ t('triggers.inbox.received') }}</dt><dd class="text-gray-300">{{ formatTime(selected.received_at, locale) }}</dd>
            <template v-if="selected.delivered_at"><dt class="text-gray-500">{{ t('triggers.inbox.detail.deliveredAt') }}</dt><dd class="text-gray-300">{{ formatTime(selected.delivered_at, locale) }}</dd></template>
            <dt class="text-gray-500">{{ t('triggers.inbox.attempts') }}</dt><dd class="font-mono text-gray-300" data-test="detail-attempts">{{ selected.attempts }}</dd>
            <dt class="text-gray-500">{{ t('triggers.inbox.replays') }}</dt><dd class="font-mono text-gray-300">{{ selected.replays }}</dd>
            <template v-if="selected.content_type"><dt class="text-gray-500">{{ t('triggers.inbox.detail.contentType') }}</dt><dd class="font-mono text-gray-300 truncate">{{ selected.content_type }}</dd></template>
            <template v-if="selected.expires_at"><dt class="text-gray-500">{{ t('triggers.inbox.detail.expiresAt') }}</dt><dd class="text-gray-300">{{ formatTime(selected.expires_at, locale) }}</dd></template>
          </dl>

          <div v-if="selected.last_error">
            <p class="text-[11px] font-medium text-gray-400 mb-1">{{ t('triggers.inbox.detail.lastError') }}</p>
            <pre class="rounded-md border border-red-900/50 bg-red-950/30 px-3 py-2 text-[11px] font-mono text-red-300 whitespace-pre-wrap break-all" data-test="detail-error">{{ selected.last_error }}</pre>
          </div>

          <div>
            <p class="text-[11px] font-medium text-gray-400 mb-1">{{ t('triggers.inbox.detail.headers') }}</p>
            <p v-if="!headers.length" class="text-[11px] text-gray-500">{{ t('triggers.inbox.detail.noHeaders') }}</p>
            <div v-else class="rounded-md border border-gray-800 bg-gray-950/60 px-3 py-2 max-h-40 overflow-auto font-mono text-[11px] text-gray-300" data-test="detail-headers">
              <div v-for="[name, value] in headers" :key="name" class="break-all"><span class="text-gray-500">{{ name }}:</span> {{ Array.isArray(value) ? value.join(', ') : value }}</div>
            </div>
          </div>

          <div>
            <div class="flex items-center gap-2 mb-1">
              <p class="text-[11px] font-medium text-gray-400">{{ t('triggers.inbox.detail.body') }}</p>
              <div v-if="typeof selected.body_text === 'string'" class="ml-auto flex gap-1">
                <button type="button" class="px-2 py-0.5 rounded-sm text-[11px]" :class="bodyMode === 'text' ? 'bg-gray-800 text-gray-100' : 'text-gray-400 hover:text-gray-200'" data-action="body-text" @click="bodyMode = 'text'">{{ t('triggers.inbox.detail.asText') }}</button>
                <button type="button" class="px-2 py-0.5 rounded-sm text-[11px]" :class="bodyMode === 'base64' ? 'bg-gray-800 text-gray-100' : 'text-gray-400 hover:text-gray-200'" data-action="body-base64" @click="bodyMode = 'base64'">{{ t('triggers.inbox.detail.asBase64') }}</button>
              </div>
            </div>
            <p v-if="body.binary && body.text" class="text-[11px] text-gray-500 mb-1">{{ t('triggers.inbox.detail.binary') }}</p>
            <pre class="rounded-md border border-gray-800 bg-gray-950/60 px-3 py-2 max-h-72 overflow-auto text-[11px] font-mono text-gray-200 whitespace-pre-wrap break-all" data-test="detail-body">{{ body.text || t('triggers.inbox.detail.noBody') }}</pre>
          </div>

          <button
            type="button" class="btn-primary text-xs px-3 py-1.5"
            :disabled="replaying || selected.status === 'pending'" data-action="replay" @click="replay(selected)"
          >
            <RotateCcw class="w-3.5 h-3.5" />{{ replaying ? t('triggers.inbox.replaying') : t('triggers.inbox.replay') }}
          </button>
        </div>
      </div>
    </template>
  </div>
</template>
