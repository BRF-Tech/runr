<script setup>
// Build & Run → Topics: the organization's fan-out topics (docs/triggers.md#topics). List, create
// and delete (topics.manage; a topic with subscriptions is refused and the subscriptions are
// shown), the detail with its subscriptions (topic triggers of projects) and a test publish
// (events.send).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { Pencil, Plus, Radio, RefreshCw, Send, Trash2, X } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { formatTime } from '@/modules/workflows/utils/format'
import { useTopicsStore } from '../stores/topics'
import { errorMessage } from '../utils/triggers'
import CreateTopicModal from './CreateTopicModal.vue'
import PublishTopicModal from './PublishTopicModal.vue'

const { t, locale } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const store = useTopicsStore()

const canManage  = computed(() => perms.can('topics.manage'))
const canPublish = computed(() => perms.can('events.send'))

const loadError    = ref(null)
const selected     = ref(null)     // topic detail with subscriptions
const blocked      = ref(null)     // { message, subscriptions } of a refused delete
const creating     = ref(false)
const publishing   = ref(false)
const editingDesc  = ref(null)     // draft description while editing

async function load(force = false) {
  try {
    await store.load(force)
    loadError.value = null
  } catch (e) {
    loadError.value = errorMessage(e, t('triggers.topics.loadFailed'))
  }
}

async function open(topic) {
  blocked.value = null
  editingDesc.value = null
  try {
    selected.value = await store.show(topic.name)
  } catch (e) {
    toast.error(errorMessage(e, t('triggers.topics.loadFailed')))
  }
}

async function onCreated(topic) {
  creating.value = false
  toast.success(t('triggers.topics.created', { name: topic.name }))
  await open(topic)
}

function onPublished(result) {
  publishing.value = false
  toast.success(t('triggers.topics.published', { id: result?.id ?? '' }))
}

async function remove(topic) {
  const ok = await confirm({
    title:        t('triggers.topics.deleteTitle'),
    message:      t('triggers.topics.deleteConfirm', { name: topic.name }),
    destructive:  true,
    confirmLabel: t('triggers.topics.delete'),
  })
  if (!ok) return
  try {
    const res = await store.remove(topic)
    if (!res.ok) {
      blocked.value = { message: res.message, subscriptions: res.subscriptions }
      return
    }
    toast.success(t('triggers.topics.deleted', { name: topic.name }))
    selected.value = null
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  }
}

async function saveDescription() {
  try {
    const topic = await store.updateDescription(selected.value, editingDesc.value ?? '')
    selected.value = { ...selected.value, description: topic?.description ?? null }
    editingDesc.value = null
    toast.success(t('triggers.topics.descriptionSaved'))
  } catch (e) {
    toast.error(errorMessage(e, t('common.error')))
  }
}

const projectOf = (projectId) => `/projects/${projectId}#triggers`

// Fresh on every visit: subscription counts change from the projects' trigger lists
onMounted(() => load(true))
</script>

<template>
  <div class="space-y-4" data-test="topics-view">
    <div class="flex items-start justify-between gap-4">
      <div>
        <div class="flex items-center gap-2">
          <Radio class="w-4 h-4 text-sky-300" />
          <h2 class="text-[15px] font-semibold text-gray-100">{{ t('triggers.topics.title') }}</h2>
        </div>
        <p class="text-xs text-gray-400 mt-1 max-w-3xl">{{ t('triggers.topics.intro') }}</p>
      </div>
      <div class="flex items-center gap-2 shrink-0">
        <button type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="store.loading" :aria-label="t('triggers.inbox.refresh')" @click="load(true)">
          <RefreshCw class="w-3.5 h-3.5" :class="store.loading ? 'animate-spin' : ''" />
        </button>
        <button v-if="canManage" type="button" class="btn-primary text-xs px-3 py-1.5" data-action="create-topic" @click="creating = true">
          <Plus class="w-3.5 h-3.5" />{{ t('triggers.topics.create') }}
        </button>
      </div>
    </div>

    <p v-if="loadError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400">{{ loadError }}</p>

    <div class="grid gap-4" :class="selected ? 'lg:grid-cols-2' : ''">
      <div class="rounded-md border border-gray-800 overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-gray-900/60 text-left text-gray-400">
            <tr>
              <th class="font-medium px-3 py-2">{{ t('triggers.topics.name') }}</th>
              <th class="font-medium px-3 py-2">{{ t('triggers.topics.region') }}</th>
              <th class="font-medium px-3 py-2 text-right">{{ t('triggers.topics.subscriptions') }}</th>
              <th class="font-medium px-3 py-2">{{ t('triggers.topics.description') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="store.loaded && !store.topics.length"><td colspan="4" class="px-3 py-8 text-center text-gray-400" data-test="topics-empty">{{ t('triggers.topics.empty') }}</td></tr>
            <tr
              v-for="tp in store.topics" :key="tp.id"
              class="border-t border-gray-800 hover:bg-gray-900/60 cursor-pointer"
              :class="selected?.id === tp.id ? 'bg-gray-900/80' : ''"
              :data-topic="tp.name"
              @click="open(tp)"
            >
              <td class="px-3 py-2 font-mono text-gray-100">{{ tp.name }}</td>
              <td class="px-3 py-2 text-gray-300">{{ tp.region_name ?? '—' }}</td>
              <td class="px-3 py-2 text-right font-mono text-gray-300">{{ tp.subscriptions ?? 0 }}</td>
              <td class="px-3 py-2 text-gray-400 truncate max-w-64">{{ tp.description || '' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Detail -->
      <div v-if="selected" class="bg-gray-900 border border-gray-800 rounded-md p-4 space-y-3 min-w-0" data-test="topic-detail">
        <div class="flex items-center gap-2">
          <span class="font-mono text-sm text-gray-100 truncate">{{ selected.name }}</span>
          <span class="text-[11px] text-gray-400">{{ selected.region_name }}</span>
          <button type="button" class="ml-auto text-gray-400 hover:text-gray-100" :aria-label="t('triggers.topics.close')" @click="selected = null"><X class="w-4 h-4" /></button>
        </div>

        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-[11px]">
          <dt class="text-gray-500">{{ t('triggers.topics.stream') }}</dt><dd class="font-mono text-gray-300 truncate">{{ selected.stream }}</dd>
          <dt class="text-gray-500">{{ t('triggers.topics.createdAt') }}</dt><dd class="text-gray-300">{{ formatTime(selected.created_at, locale) }}</dd>
        </dl>

        <!-- Description -->
        <div>
          <div v-if="editingDesc !== null" class="flex items-center gap-2">
            <input v-model="editingDesc" class="input text-xs" maxlength="500" data-field="description" @keyup.enter="saveDescription" />
            <button type="button" class="btn-primary text-xs px-3 py-1.5" data-action="save-description" @click="saveDescription">{{ t('triggers.topics.save') }}</button>
            <button type="button" class="btn-secondary text-xs px-3 py-1.5" @click="editingDesc = null">{{ t('triggers.topics.cancel') }}</button>
          </div>
          <div v-else class="flex items-start gap-2">
            <p class="text-xs" :class="selected.description ? 'text-gray-300' : 'text-gray-500'">{{ selected.description || t('triggers.topics.noDescription') }}</p>
            <button v-if="canManage" type="button" class="text-gray-400 hover:text-gray-100" :title="t('triggers.topics.editDescription')" :aria-label="t('triggers.topics.editDescription')" data-action="edit-description" @click="editingDesc = selected.description ?? ''">
              <Pencil class="w-3 h-3" />
            </button>
          </div>
        </div>

        <!-- Subscriptions -->
        <div>
          <p class="text-[11px] font-medium text-gray-400 mb-1">{{ t('triggers.topics.subscriptions') }}</p>
          <p v-if="!selected.subscriptions?.length" class="text-xs text-gray-500" data-test="no-subscriptions">{{ t('triggers.topics.noSubscribers') }}</p>
          <table v-else class="w-full text-xs" data-test="subscriptions">
            <thead class="text-left text-gray-500">
              <tr>
                <th class="font-medium py-1 pr-3">{{ t('triggers.topics.project') }}</th>
                <th class="font-medium py-1 pr-3">{{ t('triggers.topics.trigger') }}</th>
                <th class="font-medium py-1 pr-3">{{ t('triggers.topics.startFrom') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in selected.subscriptions" :key="s.trigger_id" class="border-t border-gray-800/60" :data-subscription="s.project_name">
                <td class="py-1 pr-3"><RouterLink :to="projectOf(s.project_id)" class="text-sky-400 hover:text-sky-300">{{ s.project_name ?? s.project_id }}</RouterLink></td>
                <td class="py-1 pr-3 font-mono text-gray-300">{{ s.trigger }}<span v-if="!s.enabled" class="ml-1 text-gray-500">({{ t('triggers.topics.subscriptionOff') }})</span></td>
                <td class="py-1 pr-3 text-gray-400">{{ t(`triggers.fields.start_${s.start_from === 'earliest' ? 'earliest' : 'latest'}`) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Refused delete -->
        <div v-if="blocked" class="rounded-md border border-red-800/50 bg-red-900/20 px-3 py-2 text-xs text-red-300 space-y-1" data-test="topic-in-use">
          <p>{{ blocked.message }}</p>
          <p v-if="blocked.subscriptions.length">{{ t('triggers.topics.inUse') }}</p>
          <ul class="list-disc pl-5">
            <li v-for="s in blocked.subscriptions" :key="`${s.project_id}:${s.trigger}`">
              <RouterLink :to="projectOf(s.project_id)" class="underline">{{ selected.subscriptions?.find(x => x.project_id === s.project_id)?.project_name ?? s.project_id }}</RouterLink>
              <span class="font-mono"> · {{ s.trigger }}</span>
            </li>
          </ul>
        </div>

        <div class="flex flex-wrap gap-2 pt-1">
          <button v-if="canPublish" type="button" class="btn-primary text-xs px-3 py-1.5" data-action="publish" @click="publishing = true">
            <Send class="w-3.5 h-3.5" />{{ t('triggers.topics.publish') }}
          </button>
          <button v-if="canManage" type="button" class="btn-danger text-xs px-3 py-1.5" data-action="delete-topic" @click="remove(selected)">
            <Trash2 class="w-3.5 h-3.5" />{{ t('triggers.topics.delete') }}
          </button>
        </div>
      </div>
    </div>

    <CreateTopicModal v-if="creating" @created="onCreated" @close="creating = false" />
    <PublishTopicModal v-if="publishing && selected" :topic="selected" @published="onPublished" @close="publishing = false" />
  </div>
</template>
