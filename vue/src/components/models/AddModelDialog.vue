<script setup>
// Add a model to the library: from the Hub (pinned revision), as files uploaded through RunR
// (air-gapped regions), from a path in the region's model volume or from an OCI artifact.
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { X } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useSettingsStore } from '@/stores/settings'
import { useToast } from '@/composables/useToast'
import { isRegionReady } from '@/composables/useProjectForm'

const { t } = useI18n()
const ai = useAiStore()
const settingsStore = useSettingsStore()
const toast = useToast()

const emit = defineEmits(['close', 'created'])

const form = reactive({
  source:   'hub',
  hub_id:   '',
  revision: 'main',
  name:     '',
  task:     '',
  library:  'transformers',
  path:     '',
  reference: '',
  knative_region_id: null,
})
const saving = ref(false)
const error  = ref(null)

const regions = computed(() => (settingsStore.data.regions ?? []).filter(isRegionReady))
const sources = computed(() => {
  const list = ai.canManage ? ['hub', 'upload', 'pvc', 'oci'] : ['hub', 'upload']
  return ai.hubAvailable ? list : list.filter(s => s !== 'hub')
})
if (!ai.hubAvailable) form.source = 'upload'
const taskOptions = computed(() => ai.tasks.map(tk => tk.task))

const canSubmit = computed(() => {
  if (saving.value) return false
  if (form.source === 'hub') return /^[\w.-]+\/[\w.-]+$/.test(form.hub_id.trim()) || /^[\w.-]+$/.test(form.hub_id.trim())
  if (!form.name.trim() || !form.task) return false
  if (form.source === 'pvc') return !!form.path.trim() && !!form.knative_region_id
  if (form.source === 'oci') return !!form.reference.trim() && !!form.knative_region_id
  return true
})

onMounted(() => { ai.fetchEngines(); if (!settingsStore.loaded) settingsStore.fetch() })

async function submit() {
  saving.value = true
  error.value  = null
  try {
    let payload
    if (form.source === 'hub') payload = { source: 'hub', hub_id: form.hub_id.trim(), revision: form.revision.trim() || undefined, name: form.name.trim() || undefined }
    else {
      payload = { source: form.source, name: form.name.trim(), task: form.task, library: form.library || undefined }
      if (form.source === 'pvc') payload.path = form.path.trim()
      if (form.source === 'oci') payload.reference = form.reference.trim()
      // Imports run in the region the path / artifact is in: the API starts the import job
      if (['pvc', 'oci'].includes(form.source)) payload.knative_region_id = form.knative_region_id
    }
    const { data } = await aiApi.createModel(payload)
    const model = data?.data ?? data
    ai.upsertModel(model)
    toast.success(t('ai.library.added', { name: model.name }))
    emit('created', model)
  } catch (e) {
    error.value = e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="add-model-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')" />
    <div class="relative w-full max-w-lg card space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold text-gray-100">{{ t('ai.library.addTitle') }}</h2>
        <button class="h-7 w-7 flex items-center justify-center rounded-md text-gray-400 hover:text-gray-100 hover:bg-gray-800" @click="emit('close')"><X class="w-4 h-4" /></button>
      </div>

      <div class="flex flex-wrap gap-1.5">
        <button v-for="s in sources" :key="s" type="button"
          class="rounded-md border px-3 py-1.5 text-xs font-medium transition-colors"
          :class="form.source === s ? 'border-brand-500/60 bg-brand-900/20 text-brand-400' : 'border-gray-800 bg-gray-900/40 text-gray-400 hover:text-gray-100'"
          :data-testid="`add-source-${s}`" @click="form.source = s">{{ t(`ai.sources.${s}`) }}</button>
      </div>
      <p class="text-[11px] text-gray-400">{{ t(`ai.library.sourceHints.${form.source}`) }}</p>

      <template v-if="form.source === 'hub'">
        <div>
          <label class="label" for="add-hub-id">{{ t('ai.library.hubId') }}</label>
          <input id="add-hub-id" v-model="form.hub_id" class="input font-mono" placeholder="sentence-transformers/all-MiniLM-L6-v2" data-testid="add-hub-id" />
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="add-revision">{{ t('ai.library.revision') }}</label>
            <input id="add-revision" v-model="form.revision" class="input font-mono" placeholder="main" />
          </div>
          <div>
            <label class="label" for="add-name">{{ t('ai.library.name') }} <span class="normal-case text-gray-400">{{ t('common.optional') }}</span></label>
            <input id="add-name" v-model="form.name" class="input font-mono" />
          </div>
        </div>
        <p class="text-[11px] text-gray-400">{{ t('ai.library.revisionHint') }}</p>
      </template>

      <template v-else>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="add-name2">{{ t('ai.library.name') }}</label>
            <input id="add-name2" v-model="form.name" class="input font-mono" data-testid="add-name" />
          </div>
          <div>
            <label class="label" for="add-task">{{ t('ai.hub.task') }}</label>
            <select id="add-task" v-model="form.task" class="input" data-testid="add-task">
              <option value="" disabled>{{ t('ai.library.selectTask') }}</option>
              <option v-for="task in taskOptions" :key="task" :value="task">{{ t(`ai.tasks.${task}`, task) }}</option>
            </select>
          </div>
        </div>
        <div>
          <label class="label" for="add-library">{{ t('ai.hub.library') }}</label>
          <input id="add-library" v-model="form.library" class="input font-mono" placeholder="transformers" />
        </div>
        <div v-if="form.source === 'pvc'">
          <label class="label" for="add-path">{{ t('ai.library.pvcPath') }}</label>
          <input id="add-path" v-model="form.path" class="input font-mono" placeholder="imports/my-model" data-testid="add-path" />
          <p class="text-[11px] text-gray-400 mt-1">{{ t('ai.library.pvcPathHint') }}</p>
        </div>
        <div v-if="form.source === 'oci'">
          <label class="label" for="add-reference">{{ t('ai.library.ociReference') }}</label>
          <input id="add-reference" v-model="form.reference" class="input font-mono" placeholder="registry.example.com/models/minilm:2024-10" data-testid="add-reference" />
          <p class="text-[11px] text-gray-400 mt-1">{{ t('ai.library.ociHint') }}</p>
        </div>
        <div v-if="['pvc', 'oci'].includes(form.source)">
          <label class="label" for="add-region">{{ t('ai.deploy.region') }}</label>
          <select id="add-region" v-model="form.knative_region_id" class="input" data-testid="add-region">
            <option :value="null" disabled>{{ t('ai.deploy.selectRegion') }}</option>
            <option v-for="r in regions" :key="r.id" :value="r.id">{{ r.name }}</option>
          </select>
        </div>
      </template>

      <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
      <div class="flex justify-end gap-2">
        <button class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="!canSubmit" data-testid="add-submit" @click="submit">{{ saving ? t('common.saving') : t('ai.library.add') }}</button>
      </div>
    </div>
  </div>
</template>
