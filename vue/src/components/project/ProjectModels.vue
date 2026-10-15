<script setup>
// Project → Models: model services of the project's region bound to the project. Each binding
// injects RUNR_MODEL_<ALIAS>_* env vars at the next deploy (internal URL, model name, API) and,
// with "mount weights", the read-only model files at RUNR_MODEL_<ALIAS>_PATH.
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Sparkles, Plus, Trash2, Copy, Info } from 'lucide-vue-next'
import { aiApi } from '@/api/ai'
import { useAiStore } from '@/stores/ai'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { aliasFor, isValidAlias, bindingEnv, usageSnippets, AI_STATUS_CLASSES } from '@/utils/ai'

const { t } = useI18n()
const ai = useAiStore()
const { confirm } = useConfirm()
const toast = useToast()

const props = defineProps({ project: { type: Object, required: true } })

const bindings = ref([])
const loading  = ref(false)
const showForm = ref(false)
const saving   = ref(false)
const form = reactive({ ai_model_service_id: null, alias: '', mount_weights: false })
const snippetLang = reactive({})   // binding id → python | node | go | curl

const regionServices = computed(() => ai.services.filter(s => s.knative_region_id === props.project.knative_region_id))
const boundIds = computed(() => new Set(bindings.value.map(b => b.ai_model_service_id)))
const available = computed(() => regionServices.value.filter(s => !boundIds.value.has(s.id)))
const selected = computed(() => ai.services.find(s => s.id === form.ai_model_service_id) ?? null)
const aliasTaken = computed(() => bindings.value.some(b => b.alias === form.alias))
const previewEnv = computed(() => bindingEnv(form.alias, selected.value, { mountWeights: form.mount_weights }))
const canBind = computed(() => !!selected.value && isValidAlias(form.alias) && !aliasTaken.value && !saving.value)

watch(selected, (s) => { if (s) form.alias = aliasFor(s.name) })

function errMessage(e) {
  return e?.response?.data?.message ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0] ?? t('common.error')
}

async function load() {
  loading.value = true
  try {
    const { data } = await aiApi.projectModels(props.project.id)
    bindings.value = data?.data ?? []
  } catch { bindings.value = [] } finally { loading.value = false }
}

async function bind() {
  saving.value = true
  try {
    const { data } = await aiApi.bindModel(props.project.id, { ...form })
    bindings.value = [...bindings.value, data?.data ?? data]
    toast.success(t('ai.project.bound', { alias: form.alias }))
    showForm.value = false
    Object.assign(form, { ai_model_service_id: null, alias: '', mount_weights: false })
  } catch (e) { toast.error(errMessage(e)) } finally { saving.value = false }
}

async function toggleMount(b) {
  try {
    const { data } = await aiApi.updateBinding(props.project.id, b.id, { mount_weights: !b.mount_weights })
    const updated = data?.data ?? data
    bindings.value = bindings.value.map(x => x.id === b.id ? { ...x, ...updated } : x)
  } catch (e) { toast.error(errMessage(e)) }
}

async function unbind(b) {
  const ok = await confirm({ title: t('ai.project.unbind'), message: t('ai.project.unbindConfirm', { alias: b.alias }), destructive: true, confirmLabel: t('ai.project.unbind') })
  if (!ok) return
  try {
    await aiApi.unbindModel(props.project.id, b.id)
    bindings.value = bindings.value.filter(x => x.id !== b.id)
    toast.success(t('ai.project.unbound'))
  } catch (e) { toast.error(errMessage(e)) }
}

function serviceOf(b) {
  return ai.services.find(s => s.id === b.ai_model_service_id) ?? b.service ?? null
}

function envOf(b) {
  return b.env && Object.keys(b.env).length ? b.env : bindingEnv(b.alias, serviceOf(b), { mountWeights: b.mount_weights })
}

async function copy(text) {
  try { await navigator.clipboard.writeText(text); toast.success(t('ai.project.copied')) } catch { /* clipboard unavailable */ }
}

const statusClass = (s) => AI_STATUS_CLASSES[s] ?? 'bg-gray-800 text-gray-400 border-gray-700'

onMounted(() => {
  load()
  ai.fetchServices().catch(() => {})
})
</script>

<template>
  <div class="max-w-4xl space-y-4" data-testid="project-models">
    <div class="flex items-start justify-between gap-3">
      <div>
        <h3 class="text-sm font-semibold text-gray-100 flex items-center gap-1.5"><Sparkles class="w-4 h-4 text-gray-400" />{{ t('ai.project.title') }}</h3>
        <p class="text-xs text-gray-400 mt-1">{{ t('ai.project.subtitle') }}</p>
      </div>
      <button v-if="ai.canDeploy && !showForm" class="btn-primary text-xs" data-testid="bind-open" @click="showForm = true"><Plus class="w-3.5 h-3.5" />{{ t('ai.project.bind') }}</button>
    </div>

    <p class="flex items-start gap-1.5 text-[11px] text-gray-400 bg-gray-800/40 border border-gray-700 rounded px-3 py-2" data-testid="bind-next-deploy">
      <Info class="w-3.5 h-3.5 shrink-0 mt-0.5" />{{ t('ai.project.nextDeploy') }}
    </p>

    <!-- Bind form -->
    <div v-if="showForm" class="rounded-xl border border-gray-800 bg-gray-900/60 p-4 space-y-3" data-testid="bind-form">
      <p v-if="!available.length" class="text-xs text-gray-400">
        {{ t('ai.project.noServices') }}
        <RouterLink to="/models" class="text-brand-500 hover:underline">{{ t('ai.project.openModels') }}</RouterLink>
      </p>
      <template v-else>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="label" for="bind-service">{{ t('ai.project.service') }}</label>
            <select id="bind-service" v-model="form.ai_model_service_id" class="input" data-testid="bind-service">
              <option :value="null" disabled>{{ t('ai.project.selectService') }}</option>
              <option v-for="s in available" :key="s.id" :value="s.id">{{ s.name }} — {{ s.model?.hub_id ?? s.model?.name }}</option>
            </select>
          </div>
          <div>
            <label class="label" for="bind-alias">{{ t('ai.project.alias') }}</label>
            <input id="bind-alias" v-model="form.alias" class="input font-mono uppercase" data-testid="bind-alias" @input="form.alias = form.alias.toUpperCase()" />
            <p v-if="form.alias && !isValidAlias(form.alias)" class="text-xs text-red-400 mt-1">{{ t('ai.project.aliasInvalid') }}</p>
            <p v-else-if="aliasTaken" class="text-xs text-red-400 mt-1">{{ t('ai.project.aliasTaken') }}</p>
          </div>
        </div>
        <label class="flex items-start gap-2 text-xs text-gray-300">
          <input v-model="form.mount_weights" type="checkbox" class="mt-0.5 rounded border-gray-700 bg-gray-800" data-testid="bind-mount" />
          <span>{{ t('ai.project.mountWeights') }}<span class="block text-[11px] text-gray-400">{{ t('ai.project.mountWeightsHint') }}</span></span>
        </label>
        <div v-if="selected && isValidAlias(form.alias)" class="rounded-lg border border-gray-800 bg-gray-950/60 p-3" data-testid="bind-env-preview">
          <p class="text-[10px] text-gray-500 uppercase tracking-wider mb-1.5">{{ t('ai.project.envPreview') }}</p>
          <div v-for="(v, k) in previewEnv" :key="k" class="text-[11px] font-mono"><span class="text-gray-300">{{ k }}</span>=<span class="text-gray-500">{{ v }}</span></div>
        </div>
        <div class="flex gap-2">
          <button class="btn-primary text-xs" :disabled="!canBind" data-testid="bind-submit" @click="bind">{{ saving ? t('common.saving') : t('ai.project.bind') }}</button>
          <button class="btn-secondary text-xs" @click="showForm = false">{{ t('common.cancel') }}</button>
        </div>
      </template>
    </div>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="!bindings.length && !showForm" class="py-8 text-center text-xs text-gray-400">{{ t('ai.project.empty') }}</p>

    <!-- Bindings -->
    <div v-for="b in bindings" :key="b.id" class="rounded-xl border border-gray-800 bg-gray-900/50 p-4 space-y-3" :data-binding="b.alias">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2 min-w-0">
          <code class="text-sm text-gray-100">{{ b.alias }}</code>
          <span class="text-xs text-gray-400 truncate">{{ serviceOf(b)?.name }} · {{ serviceOf(b)?.model?.hub_id ?? serviceOf(b)?.model?.name }}</span>
          <span v-if="serviceOf(b)?.status" class="inline-block px-1.5 py-0.5 rounded border text-[10px] font-mono" :class="statusClass(serviceOf(b).status)">{{ t(`ai.services.statuses.${serviceOf(b).status}`, serviceOf(b).status) }}</span>
        </div>
        <div v-if="ai.canDeploy" class="flex items-center gap-2 shrink-0">
          <label class="flex items-center gap-1.5 text-[11px] text-gray-400">
            <input type="checkbox" :checked="b.mount_weights" class="rounded border-gray-700 bg-gray-800" @change="toggleMount(b)" />{{ t('ai.project.mountWeightsShort') }}
          </label>
          <button class="p-1.5 rounded-md text-gray-400 hover:text-red-400 hover:bg-red-900/20" :title="t('ai.project.unbind')" @click="unbind(b)"><Trash2 class="w-4 h-4" /></button>
        </div>
      </div>

      <div class="rounded-lg border border-gray-800 bg-gray-950/60 p-3" data-testid="binding-env">
        <div v-for="(v, k) in envOf(b)" :key="k" class="text-[11px] font-mono"><span class="text-gray-300">{{ k }}</span>=<span class="text-gray-500">{{ v }}</span></div>
      </div>

      <div>
        <div class="flex items-center gap-1 mb-1.5">
          <button v-for="lang in ['python', 'node', 'go', 'curl']" :key="lang" type="button"
            class="px-2.5 py-1 rounded text-[11px] font-mono transition-colors"
            :class="(snippetLang[b.id] ?? 'python') === lang ? 'bg-gray-800 text-gray-100' : 'text-gray-400 hover:text-gray-100'"
            :data-testid="`snippet-${lang}`" @click="snippetLang[b.id] = lang">{{ t(`ai.project.langs.${lang}`) }}</button>
          <button class="ml-auto p-1 rounded text-gray-400 hover:text-gray-100" :title="t('common.copy')"
            @click="copy(usageSnippets(b.alias, serviceOf(b))[snippetLang[b.id] ?? 'python'])"><Copy class="w-3.5 h-3.5" /></button>
        </div>
        <pre class="rounded-lg border border-gray-800 bg-gray-950 p-3 text-[11px] font-mono text-gray-300 overflow-x-auto" data-testid="binding-snippet">{{ usageSnippets(b.alias, serviceOf(b))[snippetLang[b.id] ?? 'python'] }}</pre>
      </div>
    </div>
  </div>
</template>
