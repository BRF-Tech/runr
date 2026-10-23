<script setup>
// A project's trigger list (GET …/triggers): one row per trigger with its type, primary badge,
// where it runs and its settings; enable / disable, edit, make primary, remove, add (type picker
// from GET /trigger-types) and, for webhook / platform_event triggers, the inbox. Every change is
// saved at once and reaches the cluster with the project's next deploy (the hint stays shown).
// Used by the project "Triggers" tab and the Edit Project modal (TriggerConfig, compact).
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Inbox, Info, Pencil, Plus, Star, Trash2, X } from 'lucide-vue-next'
import { useSettingsStore } from '@/stores/settings'
import { usePermissionsStore } from '@/stores/permissions'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { triggersApi } from '../api/triggers'
import { useTriggerTypesStore } from '../stores/triggerTypes'
import { useTriggerLabels } from '../composables/useTriggerLabels'
import { MAX_TRIGGERS, addableTypes, errorMessage, storesEvents, triggerSummary } from '../utils/triggers'
import TriggerEditorModal from './TriggerEditorModal.vue'
import CopyField from './CopyField.vue'

const props = defineProps({
  project:   { type: Object, required: true },
  compact:   { type: Boolean, default: false },   // Edit Project modal: no inbox, smaller rows
  showInbox: { type: Boolean, default: true },
})
const emit = defineEmits(['state', 'changed', 'open-inbox'])

const { t } = useI18n()
const toast = useToast()
const { confirm } = useConfirm()
const perms = usePermissionsStore()
const settingsStore = useSettingsStore()
const typesStore = useTriggerTypesStore()
const { typeLabel, typeHint } = useTriggerLabels(() => typesStore.types)

const triggers      = ref([])
const plan          = ref(null)
const loading       = ref(true)
const loadError     = ref(null)
const pendingDeploy = ref(false)
const busy          = ref({})          // trigger id → true while a row action runs
const picking       = ref(false)       // type picker open
const editor        = ref(null)        // { type, trigger } of the open editor

const canEdit  = computed(() => perms.can('projects.update', props.project.id))
const canInbox = computed(() => props.showInbox && !props.compact && perms.can('replay.manage', props.project.id))
const region   = computed(() => (settingsStore.data.regions ?? []).find(r => r.id === props.project.knative_region_id) ?? null)
const choices  = computed(() => addableTypes(typesStore.types, triggers.value, region.value))
const canAdd   = computed(() => choices.value.some(c => !c.disabled))
const keyOf    = (tr) => tr.id ?? tr.name

const planParts = computed(() => {
  if (!plan.value) return []
  const parts = [t(`triggers.plan.${plan.value.workload === 'cronjob' ? 'cronjob' : 'service'}`)]
  if (plan.value.workload !== 'cronjob') parts.push(t(`triggers.plan.${plan.value.public ? 'public' : 'private'}`))
  if (plan.value.gateway) parts.push(t('triggers.plan.gateway'))
  if (plan.value.requires_redis) parts.push(t('triggers.plan.redis'))
  return parts
})

async function load() {
  try {
    const { data } = await triggersApi.list(props.project.id)
    triggers.value = data?.data ?? []
    plan.value     = data?.plan ?? null
    loadError.value = null
    emit('state', { triggers: triggers.value, plan: plan.value })
  } catch (e) {
    loadError.value = errorMessage(e, t('triggers.loadFailed'))
  } finally {
    loading.value = false
  }
}

async function changed(message) {
  pendingDeploy.value = true
  toast.success(message)
  await load()
  emit('changed', { triggers: triggers.value, plan: plan.value })
}

async function run(tr, fn) {
  const key = keyOf(tr)
  busy.value = { ...busy.value, [key]: true }
  try {
    await fn()
  } catch (e) {
    toast.error(errorMessage(e, t('triggers.saveFailed')))
  } finally {
    busy.value = { ...busy.value, [key]: false }
  }
}

function toggle(tr) {
  return run(tr, async () => {
    await triggersApi.update(props.project.id, keyOf(tr), { enabled: !tr.enabled })
    await changed(t(tr.enabled ? 'triggers.enabledOff' : 'triggers.enabledOn', { name: tr.name }))
  })
}

function makePrimary(tr) {
  return run(tr, async () => {
    await triggersApi.update(props.project.id, keyOf(tr), { position: 0 })
    await changed(t('triggers.madePrimary', { name: tr.name }))
  })
}

async function remove(tr) {
  const ok = await confirm({
    title:        t('triggers.removeTitle'),
    message:      t('triggers.removeConfirm', { name: tr.name }),
    destructive:  true,
    confirmLabel: t('triggers.remove'),
  })
  if (!ok) return
  await run(tr, async () => {
    const { data } = await triggersApi.remove(props.project.id, keyOf(tr))
    noteCleanup(data?.cleanup)
    await changed(t('triggers.removed', { name: tr.name }))
  })
}

// What a change left outside RunR that RunR removes later (a cdc trigger's replication slots):
// shown until the page is left; RunR notifies the outcome.
const cleanupNotes = ref([])
function noteCleanup(entries) {
  for (const c of entries ?? []) {
    if (c?.message) cleanupNotes.value = [...cleanupNotes.value, `${c.trigger}: ${c.message}`]
  }
}

function openAdd(choice) {
  if (choice.disabled) return
  picking.value = false
  editor.value = { type: choice.type, trigger: null }
}

function openEdit(tr) {
  editor.value = { type: tr.type, trigger: tr }
}

async function onSaved({ trigger, created, cleanup }) {
  const name = trigger?.name ?? editor.value?.trigger?.name ?? ''
  editor.value = null
  noteCleanup(cleanup)
  await changed(t(created ? 'triggers.added' : 'triggers.saved', { name }))
}

function reasonText(reason) {
  return reason ? t(`triggers.unavailable.${reason}`, { max: MAX_TRIGGERS }) : ''
}

onMounted(() => {
  if (!settingsStore.loaded) settingsStore.fetch().catch(() => {})
  typesStore.load().catch(() => {})
  load()
})
watch(() => props.project.id, () => { loading.value = true; load() })

defineExpose({ load })
</script>

<template>
  <div class="space-y-3" data-test="trigger-list">
    <div v-if="loading" class="flex justify-center py-6">
      <div class="animate-spin h-4 w-4 border-2 border-sky-500 border-t-transparent rounded-full" />
    </div>
    <p v-else-if="loadError" class="bg-red-900/20 border border-red-800/40 rounded-md px-4 py-3 text-xs text-red-400" data-test="trigger-load-error">{{ loadError }}</p>

    <template v-else>
      <p v-if="planParts.length && !compact" class="text-[11px] text-gray-400" data-test="trigger-plan">
        {{ t('triggers.plan.title') }}: <span class="text-gray-300">{{ planParts.join(' · ') }}</span>
      </p>

      <p v-if="!triggers.length" class="text-sm text-gray-400 py-4 text-center">{{ t('triggers.empty') }}</p>

      <!-- Rows -->
      <div v-else class="space-y-2">
        <div
          v-for="tr in triggers" :key="keyOf(tr)"
          class="rounded-xl border bg-gray-900/60" :class="[compact ? 'px-3 py-2' : 'px-4 py-3', tr.enabled ? 'border-gray-800' : 'border-gray-800/60 opacity-70']"
          :data-trigger="tr.name" :data-trigger-type="tr.type"
        >
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2 min-w-0">
              <!-- enabled switch -->
              <button
                type="button" role="switch" :aria-checked="tr.enabled"
                :aria-label="tr.enabled ? t('triggers.disable', { name: tr.name }) : t('triggers.enable', { name: tr.name })"
                :title="tr.enabled ? t('triggers.disable', { name: tr.name }) : t('triggers.enable', { name: tr.name })"
                :disabled="!canEdit || busy[keyOf(tr)]"
                class="relative inline-flex h-4 w-7 shrink-0 rounded-full border-2 border-transparent transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                :class="tr.enabled ? 'bg-brand-500' : 'bg-gray-700'"
                data-action="toggle" @click="toggle(tr)"
              >
                <span class="pointer-events-none inline-block h-3 w-3 rounded-full bg-white shadow-sm transition" :class="tr.enabled ? 'translate-x-3' : 'translate-x-0'" />
              </button>
              <span class="font-mono text-sm text-gray-100">{{ tr.name }}</span>
              <span class="text-[11px] px-1.5 py-0.5 rounded-sm border border-gray-700 bg-gray-800 text-gray-300">{{ typeLabel(tr.type, tr.label) }}</span>
              <span v-if="tr.primary" class="text-[10px] px-1.5 py-0.5 rounded-sm border border-amber-800/50 bg-amber-900/30 text-amber-300" :title="t('triggers.primaryHint')" data-test="primary-badge">{{ t('triggers.primary') }}</span>
              <span class="text-[10px] px-1.5 py-0.5 rounded-sm border border-sky-900/50 bg-sky-950/40 text-sky-300" :title="t(`triggers.runsOnHint.${tr.runs_on ?? 'unknown'}`)" data-test="runs-on">{{ t(`triggers.runsOn.${tr.runs_on ?? 'unknown'}`) }}</span>
            </div>

            <div class="flex items-center gap-0.5">
              <button v-if="canInbox && storesEvents(typesStore.types, tr.type) && tr.id" type="button" class="flex items-center gap-1 px-2 py-1 rounded-md text-[11px] text-gray-300 hover:text-gray-100 hover:bg-gray-800" data-action="inbox" @click="emit('open-inbox', tr)">
                <Inbox class="w-3.5 h-3.5" />{{ t('triggers.openInbox') }}
              </button>
              <template v-if="canEdit">
                <button v-if="tr.enabled && !tr.primary" type="button" class="p-1.5 text-gray-400 hover:text-amber-300" :title="t('triggers.makePrimary')" :aria-label="t('triggers.makePrimary')" :disabled="busy[keyOf(tr)]" data-action="primary" @click="makePrimary(tr)">
                  <Star class="w-3.5 h-3.5" />
                </button>
                <button type="button" class="p-1.5 text-gray-400 hover:text-gray-100" :title="t('triggers.edit')" :aria-label="t('triggers.edit')" :disabled="busy[keyOf(tr)]" data-action="edit" @click="openEdit(tr)">
                  <Pencil class="w-3.5 h-3.5" />
                </button>
                <button type="button" class="p-1.5 text-gray-400 hover:text-red-400" :title="t('triggers.remove')" :aria-label="t('triggers.remove')" :disabled="busy[keyOf(tr)]" data-action="remove" @click="remove(tr)">
                  <Trash2 class="w-3.5 h-3.5" />
                </button>
              </template>
            </div>
          </div>

          <!-- settings -->
          <div v-if="triggerSummary(tr, t).length" class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-gray-400" data-test="trigger-summary">
            <span v-for="(part, i) in triggerSummary(tr, t)" :key="i" :class="part.mono ? 'font-mono text-gray-300' : ''">{{ part.text }}</span>
          </div>
          <div v-if="tr.type === 'webhook' && tr.url" class="mt-1.5 max-w-xl">
            <CopyField :value="tr.url" test-id="trigger-url" />
          </div>
        </div>
      </div>

      <!-- Add -->
      <template v-if="canEdit">
        <div v-if="picking" class="rounded-xl border border-gray-800 bg-gray-900/60 p-3 space-y-2" data-test="type-picker">
          <div class="flex items-center justify-between">
            <p class="text-xs font-medium text-gray-300">{{ t('triggers.chooseType') }}</p>
            <button type="button" class="text-gray-400 hover:text-gray-100" :aria-label="t('triggers.close')" @click="picking = false"><X class="w-4 h-4" /></button>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
            <button
              v-for="c in choices" :key="c.type" type="button"
              class="text-left rounded-lg border px-3 py-2 transition-colors"
              :class="c.disabled ? 'border-gray-800 bg-gray-900 opacity-50 cursor-not-allowed' : 'border-gray-700 bg-gray-800/60 hover:border-gray-500'"
              :disabled="c.disabled" :title="reasonText(c.reason)" :data-type="c.type" @click="openAdd(c)"
            >
              <span class="block text-sm text-gray-100">{{ typeLabel(c.type) }}</span>
              <span class="block text-[11px] text-gray-400 mt-0.5">{{ c.disabled ? reasonText(c.reason) : typeHint(c.type) }}</span>
            </button>
          </div>
        </div>
        <button v-else type="button" class="btn-secondary text-xs px-3 py-1.5" :disabled="!canAdd" data-action="add" @click="picking = true">
          <Plus class="w-3.5 h-3.5" />{{ t('triggers.add') }}
        </button>
      </template>
      <p v-else class="text-[11px] text-gray-400">{{ t('triggers.readOnly') }}</p>

      <p v-if="pendingDeploy" class="flex items-start gap-1.5 rounded-lg border border-amber-800/50 bg-amber-900/20 px-3 py-2 text-xs text-amber-200" data-test="requires-deploy">
        <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ t('triggers.requiresDeploy') }}
      </p>
      <p v-for="(note, i) in cleanupNotes" :key="i" class="flex items-start gap-1.5 rounded-lg border border-sky-800/50 bg-sky-900/20 px-3 py-2 text-xs text-sky-200" data-test="trigger-cleanup">
        <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ note }}
      </p>
    </template>

    <TriggerEditorModal
      v-if="editor"
      :project-id="project.id"
      :region-id="project.knative_region_id ?? null"
      :type="editor.type"
      :trigger="editor.trigger"
      :types="typesStore.types"
      :platform-events="typesStore.platformEvents"
      :webhook-presets="typesStore.webhookPresets"
      :siblings="triggers.filter(x => x !== editor.trigger)"
      @saved="onSaved"
      @close="editor = null"
    />
  </div>
</template>
