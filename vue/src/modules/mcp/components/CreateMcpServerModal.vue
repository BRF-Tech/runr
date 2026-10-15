<script setup>
// One-click hosted MCP server: a project from a starter (Python with FastMCP, TypeScript with
// the official SDK) or an existing image, served behind the RunR gateway (docs/mcp.md).
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { projectsApi } from '@/api/projects'
import { runtimesApi } from '@/api/runtimes'
import { useSettingsStore } from '@/stores/settings'
import { useRuntimesStore } from '@/stores/runtimes'
import { slugError, projectSlug } from '@/composables/projectValidation'
import RegionSelector from '@/components/project/RegionSelector.vue'
import { mcpApi } from '../api/mcp'
import AccessModePicker from './AccessModePicker.vue'
import { Bot, Box, Container, Loader2 } from 'lucide-vue-next'

const props = defineProps({
  existingNames: { type: Array, default: () => [] },
})
const emit = defineEmits(['close', 'created'])

const { t } = useI18n()
const router = useRouter()
const settingsStore = useSettingsStore()

const templates = ref([])
const loadingTemplates = ref(true)
const enabling = ref(false)
const creating = ref(false)
const error = ref(null)

const name = ref('')
const regionId = ref(null)
const templateId = ref('python')
const image = ref('')
const port = ref(8080)
const accessMode = ref('token')

const regions = computed(() => settingsStore.data.regions ?? [])
const template = computed(() => templates.value.find(x => x.id === templateId.value) || null)
const nameError = computed(() => slugError(name.value))
const nameTaken = computed(() => props.existingNames.some(n => n.toLowerCase() === name.value.trim().toLowerCase()))
const runtimeMissing = computed(() => template.value && template.value.service_type !== 'caas' && !template.value.runtime_available)

const canCreate = computed(() => name.value.trim() && !nameError.value && !nameTaken.value && regionId.value
  && template.value && !runtimeMissing.value
  && (template.value.service_type !== 'caas' || (image.value.trim() && port.value)))

const icons = { python: Bot, node: Box, image: Container }

async function loadTemplates() {
  loadingTemplates.value = true
  try {
    const { data } = await mcpApi.templates()
    templates.value = data.data ?? []
    if (!templates.value.some(x => x.id === templateId.value) && templates.value.length) templateId.value = templates.value[0].id
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    loadingTemplates.value = false
  }
}

async function enableRuntime() {
  if (!template.value?.runtime_manifest) return
  enabling.value = true
  error.value = null
  try {
    await runtimesApi.create({ manifest: template.value.runtime_manifest })
    try { await useRuntimesStore().fetch() } catch { /* the catalog refreshes on its own */ }
    await loadTemplates()
  } catch (e) {
    error.value = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
  } finally {
    enabling.value = false
  }
}

/** POST /api/projects payload of the chosen template. */
function projectPayload() {
  const base = { name: name.value.trim(), service_type: template.value.service_type, knative_region_id: regionId.value }
  if (template.value.service_type === 'caas') {
    return { ...base, config: { image: image.value.trim(), ports: [Number(port.value)], trigger_type: 'http' } }
  }
  return { ...base, runtime: template.value.runtime, config: { trigger_type: 'http' } }
}

async function create() {
  if (!canCreate.value || creating.value) return
  creating.value = true
  error.value = null
  let project = null
  try {
    const { data } = await projectsApi.create(projectPayload())
    project = data.data ?? data

    const files = Object.entries(template.value.files || {}).map(([fileName, content]) => ({ name: fileName, content }))
    if (template.value.service_type !== 'caas' && files.length) {
      await projectsApi.saveFiles(project.id, files, t('mcp.create.commitMessage'))
    }

    await mcpApi.saveServer(project.id, { access_mode: accessMode.value, path: template.value.path || '/mcp' })

    emit('created', project)
    router.push({ path: `/projects/${project.id}`, hash: '#mcp' })
  } catch (e) {
    const msg = e.response?.data?.message ?? Object.values(e.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
    error.value = project ? t('mcp.create.partial', { name: project.name, error: msg }) : msg
    if (project) emit('created', project)
  } finally {
    creating.value = false
  }
}

onMounted(async () => {
  if (!settingsStore.loaded) {
    try { await settingsStore.fetch() } catch { /* regions stay empty */ }
  }
  await loadTemplates()
})
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="create-mcp-server">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-2xl">
      <div class="card space-y-5 max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between">
          <div>
            <h2 class="text-lg font-semibold text-gray-100">{{ t('mcp.create.title') }}</h2>
            <p class="text-sm text-gray-400">{{ t('mcp.create.subtitle') }}</p>
          </div>
          <button class="text-gray-400 hover:text-gray-100" :aria-label="t('common.cancel')" @click="emit('close')">{{ t('common.close') }}</button>
        </div>

        <div>
          <label class="label" for="mcp-create-name">{{ t('create.fields.name') }}</label>
          <input id="mcp-create-name" v-model="name" class="input" :placeholder="t('mcp.create.namePlaceholder')" data-testid="mcp-create-name" />
          <p v-if="nameTaken" class="text-xs text-red-400 mt-1">{{ t('create.step1.nameTaken') }}</p>
          <p v-else-if="nameError" class="text-xs text-red-400 mt-1">{{ t(nameError.key, nameError.params) }}</p>
          <p v-else class="text-xs text-gray-400 mt-1 font-mono">slug: {{ projectSlug(name) || '—' }}</p>
        </div>

        <div>
          <p class="label mb-2">{{ t('mcp.create.region') }}</p>
          <RegionSelector :regions="regions" :selected-region-id="regionId" @select="regionId = $event" />
        </div>

        <div>
          <p class="label mb-2">{{ t('mcp.create.template') }}</p>
          <div v-if="loadingTemplates" class="flex items-center gap-2 text-xs text-gray-400"><Loader2 class="w-3.5 h-3.5 animate-spin" />{{ t('common.loading') }}</div>
          <div v-else class="grid grid-cols-1 sm:grid-cols-3 gap-2">
            <button v-for="tpl in templates" :key="tpl.id" type="button"
              class="text-left rounded-lg border px-3 py-2.5 transition-colors"
              :class="templateId === tpl.id ? 'border-brand-500/60 bg-brand-500/10' : 'border-gray-700 bg-gray-900/40 hover:border-gray-600'"
              :data-testid="`mcp-template-${tpl.id}`"
              @click="templateId = tpl.id">
              <div class="flex items-center gap-2 text-sm font-medium text-gray-100">
                <component :is="icons[tpl.id] || Bot" class="w-4 h-4" />{{ tpl.name }}
              </div>
              <p class="text-[11px] text-gray-400 mt-1">{{ tpl.description }}</p>
            </button>
          </div>

          <div v-if="runtimeMissing" class="mt-3 rounded-lg border border-amber-800/50 bg-amber-900/20 px-3 py-2.5" data-testid="mcp-runtime-missing">
            <p class="text-xs text-amber-200">{{ t('mcp.create.runtimeMissing', { runtime: template.runtime }) }}</p>
            <button v-if="template.can_register_runtime" class="btn-secondary text-xs mt-2" :disabled="enabling" data-testid="mcp-enable-runtime" @click="enableRuntime">
              {{ enabling ? t('common.saving') : t('mcp.create.enableRuntime') }}
            </button>
            <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('mcp.create.askAdmin') }}</p>
          </div>
        </div>

        <div v-if="template?.service_type === 'caas'" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div class="sm:col-span-2">
            <label class="label" for="mcp-create-image">{{ t('mcp.create.image') }}</label>
            <input id="mcp-create-image" v-model="image" class="input font-mono" :placeholder="t('mcp.create.imagePlaceholder')" data-testid="mcp-create-image" />
          </div>
          <div>
            <label class="label" for="mcp-create-port">{{ t('mcp.create.port') }}</label>
            <input id="mcp-create-port" v-model.number="port" type="number" min="1" max="65535" class="input" data-testid="mcp-create-port" />
          </div>
          <p class="sm:col-span-3 text-[11px] text-gray-400">{{ t('mcp.create.imageHint') }}</p>
        </div>

        <div>
          <p class="label mb-2">{{ t('mcp.project.accessMode') }}</p>
          <AccessModePicker v-model="accessMode" />
        </div>

        <p v-if="error" class="text-xs text-red-400" data-testid="mcp-create-error">{{ error }}</p>

        <div class="flex justify-end gap-2">
          <button class="btn-secondary text-sm" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button class="btn-primary text-sm" :disabled="!canCreate || creating" data-testid="mcp-create-submit" @click="create">
            {{ creating ? t('common.saving') : t('mcp.create.submit') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
