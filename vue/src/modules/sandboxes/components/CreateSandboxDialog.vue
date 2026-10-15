<script setup>
import { ref, reactive, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { Box, Plus, X, ChevronDown, ChevronRight } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { useRuntimesStore } from '@/stores/runtimes'
import { sandboxesApi, sandboxAdminApi } from '../api/sandboxes'
import { apiError, apiErrorList, buildCreatePayload, shortId } from '../utils'
import TermText from '@/components/TermText.vue'

const emit  = defineEmits(['close', 'created'])
const { t } = useI18n()
const toast = useToast()
const runtimesStore = useRuntimesStore()

const regions    = ref([])
const loading    = ref(true)
const submitting = ref(false)
const error      = ref(null)
const problems   = ref([])
const advanced   = ref(false)

const form = reactive({
  region:       '',
  source:       'runtime',
  runtime:      'python',
  version:      '',
  image:        '',
  cpu:          '500m',
  memory:       512,
  disk:         1024,
  ttlMinutes:   15,
  idleMinutes:  10,
  sandboxClass: 'auto',
  networkMode:  'none',
  allowlist:    '',
  env:          [],
  metadata:     [],
  readOnlyRoot: true,
})

onMounted(async () => {
  try {
    const [{ data }] = await Promise.all([sandboxAdminApi.regions(), runtimesStore.fetch()])
    regions.value = (data?.data ?? []).filter(r => r.enabled)
    if (!form.region && regions.value.length) form.region = regions.value[0].region.id
  } catch (e) {
    error.value = apiError(e, t('sandboxes.create.failed'))
  } finally {
    loading.value = false
  }
})

const region = computed(() => regions.value.find(r => r.region.id === form.region) ?? null)
const classes = computed(() => region.value?.effective_classes ?? [])

const runtimes = computed(() => runtimesStore.runtimes
  .filter(r => !Array.isArray(r.regions) || !form.region || r.regions.includes(form.region)))

const runtime = computed(() => runtimes.value.find(r => r.id === form.runtime) ?? null)

const versions = computed(() => (runtime.value?.versions ?? [])
  .filter(v => !(v.eol_at && new Date(v.eol_at) <= new Date())))

// A runtime the catalog (or the region) does not offer falls back to the first one; the version
// to the runtime's default (the API picks it too when version is left out)
watch([runtimes, runtime], () => {
  if (!runtime.value && runtimes.value.length) {
    form.runtime = runtimes.value[0].id
    return
  }
  const list = versions.value
  if (!list.find(v => v.version === form.version)) {
    form.version = list.find(v => v.is_default)?.version ?? list[0]?.version ?? ''
  }
}, { immediate: true })

// A class the new region does not offer falls back to "strongest"
watch(classes, (list) => {
  if (form.sandboxClass !== 'auto' && !list.includes(form.sandboxClass)) form.sandboxClass = 'auto'
})

function addRow(list) { list.push({ key: '', value: '' }) }
function removeRow(list, i) { list.splice(i, 1) }

const canSubmit = computed(() => !!form.region && !submitting.value
  && (form.source === 'image' ? !!form.image.trim() : !!form.runtime))

async function submit() {
  if (!canSubmit.value) return
  error.value    = null
  problems.value = []
  submitting.value = true
  try {
    const { data, status } = await sandboxesApi.create(buildCreatePayload(form))
    const sandbox = data?.data ?? data
    toast.success(status === 202 || sandbox?.status === 'pending'
      ? t('sandboxes.create.createdPending', { id: shortId(sandbox?.id) })
      : t('sandboxes.create.created', { id: shortId(sandbox?.id) }))
    emit('created', sandbox)
  } catch (e) {
    error.value    = apiError(e, t('sandboxes.create.failed'))
    problems.value = apiErrorList(e)
  } finally {
    submitting.value = false
  }
}

const pill = (active) => active
  ? 'text-white border-transparent'
  : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-2xl max-h-[92vh] overflow-y-auto">
      <form class="card" data-dialog="create-sandbox" @submit.prevent="submit">
        <div class="flex items-start justify-between gap-3 mb-1">
          <div class="flex items-center gap-2.5">
            <Box class="w-5 h-5 text-brand-500" />
            <h2 class="text-base font-semibold text-gray-100">{{ t('sandboxes.create.title') }}</h2>
          </div>
          <button type="button" class="text-gray-400 hover:text-gray-100" :title="t('sandboxes.actions.close')" @click="emit('close')">
            <X class="w-4 h-4" />
          </button>
        </div>
        <p class="text-xs text-gray-400 mb-5">{{ t('sandboxes.create.subtitle') }}</p>

        <div v-if="loading" class="py-10 text-center text-sm text-gray-400">{{ t('common.loading') }}</div>

        <template v-else>
          <!-- Region -->
          <label class="label">{{ t('sandboxes.create.region') }}</label>
          <div v-if="regions.length" class="flex flex-wrap gap-2 mb-4">
            <button
              v-for="r in regions" :key="r.region.id" type="button"
              class="px-3 py-1.5 rounded-md border text-xs font-mono transition-all"
              :class="pill(form.region === r.region.id)"
              :style="form.region === r.region.id ? 'background-color:rgb(var(--brand-600))' : ''"
              :data-region="r.region.id"
              @click="form.region = r.region.id"
            >{{ r.region.name }}</button>
          </div>
          <p v-else class="text-xs text-amber-400 mb-4" data-no-regions>{{ t('sandboxes.create.noRegions') }}</p>

          <!-- Source -->
          <label class="label">{{ t('sandboxes.create.source') }}</label>
          <div class="flex gap-2 mb-3">
            <button
              v-for="src in ['runtime', 'image']" :key="src" type="button"
              class="px-3 py-1.5 rounded-md border text-xs transition-all"
              :class="pill(form.source === src)"
              :style="form.source === src ? 'background-color:rgb(var(--brand-600))' : ''"
              :data-source="src"
              @click="form.source = src"
            >{{ src === 'runtime' ? t('sandboxes.create.sourceRuntime') : t('sandboxes.create.sourceImage') }}</button>
          </div>

          <div v-if="form.source === 'runtime'" class="grid grid-cols-2 gap-3 mb-4">
            <div>
              <label class="label">{{ t('sandboxes.create.runtime') }}</label>
              <select v-model="form.runtime" class="input" name="runtime">
                <option v-for="r in runtimes" :key="r.id" :value="r.id">{{ r.name }}</option>
              </select>
            </div>
            <div>
              <label class="label">{{ t('sandboxes.create.version') }}</label>
              <select v-model="form.version" class="input" name="version">
                <option v-for="v in versions" :key="v.version" :value="v.version">
                  {{ v.version }}{{ v.is_default ? ` (${t('sandboxes.create.defaultVersion')})` : '' }}
                </option>
              </select>
            </div>
          </div>
          <div v-else class="mb-4">
            <label class="label">{{ t('sandboxes.create.image') }}</label>
            <input v-model="form.image" class="input font-mono" name="image" type="text" placeholder="docker.io/library/python:3.13-slim" />
            <p class="text-[11px] text-gray-500 mt-1">{{ t('sandboxes.create.imageHint') }}</p>
          </div>

          <!-- Resources -->
          <div class="grid grid-cols-3 gap-3 mb-4">
            <div>
              <label class="label">{{ t('sandboxes.create.cpu') }}</label>
              <input v-model="form.cpu" class="input font-mono" name="cpu" type="text" :placeholder="t('sandboxes.create.cpuHint')" />
            </div>
            <div>
              <label class="label"><TermText path="sandboxes.create.memory" /></label>
              <input v-model.number="form.memory" class="input font-mono" name="memory" type="number" min="64" step="64" />
            </div>
            <div>
              <label class="label"><TermText path="sandboxes.create.disk" /></label>
              <input v-model.number="form.disk" class="input font-mono" name="disk" type="number" min="64" step="64" />
            </div>
          </div>

          <!-- Lifetime -->
          <div class="grid grid-cols-2 gap-3 mb-4">
            <div>
              <label class="label">{{ t('sandboxes.create.ttl') }}</label>
              <input v-model.number="form.ttlMinutes" class="input font-mono" name="ttl" type="number" min="1" />
            </div>
            <div>
              <label class="label">{{ t('sandboxes.create.idle') }}</label>
              <input v-model.number="form.idleMinutes" class="input font-mono" name="idle" type="number" min="0" />
              <p class="text-[11px] text-gray-500 mt-1">{{ t('sandboxes.create.idleHint') }}</p>
            </div>
          </div>

          <!-- Network -->
          <label class="label">{{ t('sandboxes.create.network') }}</label>
          <div class="flex gap-2 mb-2">
            <button
              v-for="mode in ['none', 'allowlist']" :key="mode" type="button"
              class="px-3 py-1.5 rounded-md border text-xs transition-all"
              :class="pill(form.networkMode === mode)"
              :style="form.networkMode === mode ? 'background-color:rgb(var(--brand-600))' : ''"
              :data-network="mode"
              @click="form.networkMode = mode"
            >{{ mode === 'none' ? t('sandboxes.create.networkNone') : t('sandboxes.create.networkAllowlist') }}</button>
          </div>
          <div v-if="form.networkMode === 'allowlist'" class="mb-4">
            <textarea v-model="form.allowlist" name="allowlist" rows="3" class="input font-mono text-xs" placeholder="pypi.org:443&#10;files.pythonhosted.org:443&#10;10.0.0.0/8"></textarea>
            <p class="text-[11px] text-gray-500 mt-1">{{ t('sandboxes.create.allowlistHint') }}</p>
          </div>
          <div v-else class="mb-4" />

          <!-- Environment variables -->
          <div class="mb-4">
            <div class="flex items-center justify-between mb-1.5">
              <span class="label mb-0!">{{ t('sandboxes.create.env') }}</span>
              <button type="button" class="text-xs text-brand-500 hover:underline flex items-center gap-1" data-add-row="env" @click="addRow(form.env)">
                <Plus class="w-3 h-3" />{{ t('sandboxes.create.addRow') }}
              </button>
            </div>
            <div v-for="(row, i) in form.env" :key="'env-' + i" class="flex gap-2 mb-1.5" data-row="env">
              <input v-model="row.key" class="input font-mono text-xs" :placeholder="t('sandboxes.create.key')" />
              <input v-model="row.value" class="input font-mono text-xs" :placeholder="t('sandboxes.create.value')" />
              <button type="button" class="text-gray-500 hover:text-red-400 px-1" :title="t('sandboxes.create.removeRow')" @click="removeRow(form.env, i)"><X class="w-3.5 h-3.5" /></button>
            </div>
          </div>

          <!-- Advanced -->
          <button type="button" class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-100 mb-3" data-toggle="advanced" @click="advanced = !advanced">
            <component :is="advanced ? ChevronDown : ChevronRight" class="w-3.5 h-3.5" />{{ t('sandboxes.create.advanced') }}
          </button>
          <div v-if="advanced" class="space-y-4 mb-4 pl-4 border-l border-gray-800">
            <div>
              <label class="label">{{ t('sandboxes.create.sandboxClass') }}</label>
              <select v-model="form.sandboxClass" class="input" name="sandbox_class">
                <option value="auto">{{ t('sandboxes.create.classAuto') }}{{ region?.effective_default_class ? ` (${region.effective_default_class})` : '' }}</option>
                <option v-for="c in classes" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>

            <label class="flex items-start gap-2 text-sm text-gray-300">
              <input v-model="form.readOnlyRoot" type="checkbox" name="read_only_root" class="mt-0.5" />
              <span>
                {{ t('sandboxes.create.readOnlyRoot') }}
                <span class="block text-[11px] text-gray-500">{{ t('sandboxes.create.readOnlyRootHint') }}</span>
              </span>
            </label>

            <div>
              <div class="flex items-center justify-between mb-1.5">
                <span class="label mb-0!">{{ t('sandboxes.create.metadata') }}</span>
                <button type="button" class="text-xs text-brand-500 hover:underline flex items-center gap-1" data-add-row="metadata" @click="addRow(form.metadata)">
                  <Plus class="w-3 h-3" />{{ t('sandboxes.create.addRow') }}
                </button>
              </div>
              <div v-for="(row, i) in form.metadata" :key="'meta-' + i" class="flex gap-2 mb-1.5" data-row="metadata">
                <input v-model="row.key" class="input font-mono text-xs" :placeholder="t('sandboxes.create.key')" />
                <input v-model="row.value" class="input font-mono text-xs" :placeholder="t('sandboxes.create.value')" />
                <button type="button" class="text-gray-500 hover:text-red-400 px-1" :title="t('sandboxes.create.removeRow')" @click="removeRow(form.metadata, i)"><X class="w-3.5 h-3.5" /></button>
              </div>
            </div>
          </div>

          <!-- Errors -->
          <div v-if="error" class="rounded-lg border border-red-900/60 bg-red-950/30 px-3 py-2 mb-3" data-create-error>
            <p class="text-sm text-red-400">{{ error }}</p>
            <ul v-if="problems.length" class="mt-1 text-xs text-red-300 font-mono list-disc pl-4">
              <li v-for="p in problems" :key="p">{{ p }}</li>
            </ul>
          </div>
        </template>

        <div class="flex justify-end gap-2 pt-4 border-t border-gray-800/60">
          <button type="button" class="btn-secondary" @click="emit('close')">{{ t('sandboxes.actions.cancel') }}</button>
          <button type="submit" class="btn-primary flex items-center gap-1.5" :disabled="!canSubmit" data-action="submit-sandbox">
            <Box class="w-3.5 h-3.5" />{{ submitting ? t('sandboxes.create.submitting') : t('sandboxes.create.submit') }}
          </button>
        </div>
      </form>
    </div>
  </div>
</template>
