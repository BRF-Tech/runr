<script setup>
// Secret env vars of a project (docs/security.md). Values are write-only: the API never returns
// them unless the user may view values (owners) and asks for one explicitly ("Show").
//   projectId set   → changes go straight to the API (edit project)
//   projectId null  → v-model list of { name, value } the caller saves after creating the project
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectSecretsApi } from '@/api/security'
import { useConfirm } from '@/composables/useConfirm'
import { useToast } from '@/composables/useToast'
import { KeyRound, Plus, Trash2, Eye, EyeOff, Pencil } from 'lucide-vue-next'

const ENV_KEY = /^[A-Za-z_][A-Za-z0-9_]*$/
const RESERVED = ['PORT', 'K_SERVICE', 'K_CONFIGURATION', 'K_REVISION', 'RUNR_MODE', 'RUNR_HANDLER', 'RUNR_FILENAME']

const props = defineProps({
  projectId:  { type: String, default: null },
  modelValue: { type: Array, default: () => [] },
  // Names of the plain env vars of the form — a name is either plain or secret
  plainNames: { type: Array, default: () => [] },
})
const emit = defineEmits(['update:modelValue', 'changed'])

const { t, locale } = useI18n()
const { confirm } = useConfirm()
const toast = useToast()

const remote = computed(() => !!props.projectId)
const secrets = ref([])           // remote: [{ name, updated_at, updated_by }]
const canViewValues = ref(false)
const loading = ref(false)
const loadError = ref(false)
const busy = ref(null)            // name being saved / removed
const revealed = ref({})          // name → value (remote, while shown)
const replacing = ref(null)       // name whose value is being replaced
const replaceValue = ref('')
const newName = ref('')
const newValue = ref('')
const formError = ref(null)

const rows = computed(() => remote.value
  ? secrets.value
  : props.modelValue.map(s => ({ name: s.name, pending: true })))

async function load() {
  if (!remote.value) return
  loading.value = true
  loadError.value = false
  try {
    const { data } = await projectSecretsApi.list(props.projectId)
    secrets.value = data?.data ?? []
    canViewValues.value = !!data?.can_view_values
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

function nameError(name) {
  if (!ENV_KEY.test(name)) return t('secretEnv.errors.nameInvalid')
  if (RESERVED.includes(name.toUpperCase())) return t('secretEnv.errors.nameReserved', { name })
  if (rows.value.some(r => r.name === name)) return t('secretEnv.errors.nameTaken', { name })
  if (props.plainNames.includes(name)) return t('secretEnv.errors.nameIsPlain', { name })
  return null
}

function apiError(e) {
  return e?.response?.data?.message
    ?? Object.values(e?.response?.data?.errors ?? {}).flat()[0]
    ?? t('common.error')
}

async function add() {
  const name = newName.value.trim()
  formError.value = nameError(name) ?? (newValue.value === '' ? t('secretEnv.errors.valueRequired') : null)
  if (formError.value) return

  if (!remote.value) {
    emit('update:modelValue', [...props.modelValue, { name, value: newValue.value }])
    newName.value = ''
    newValue.value = ''
    return
  }

  busy.value = name
  try {
    await projectSecretsApi.set(props.projectId, name, newValue.value)
    newName.value = ''
    newValue.value = ''
    await load()
    emit('changed')
  } catch (e) {
    formError.value = apiError(e)
  } finally {
    busy.value = null
  }
}

function startReplace(name) {
  replacing.value = name
  replaceValue.value = ''
}

async function saveReplace(name) {
  if (replaceValue.value === '') return
  busy.value = name
  try {
    await projectSecretsApi.set(props.projectId, name, replaceValue.value)
    replacing.value = null
    replaceValue.value = ''
    delete revealed.value[name]
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function remove(name) {
  if (!remote.value) {
    emit('update:modelValue', props.modelValue.filter(s => s.name !== name))
    return
  }
  const ok = await confirm({
    title: t('secretEnv.removeTitle'),
    message: t('secretEnv.removeConfirm', { name }),
    destructive: true,
    confirmLabel: t('secretEnv.remove'),
  })
  if (!ok) return

  busy.value = name
  try {
    await projectSecretsApi.remove(props.projectId, name)
    delete revealed.value[name]
    await load()
    emit('changed')
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function toggleReveal(name) {
  if (revealed.value[name] !== undefined) {
    delete revealed.value[name]
    return
  }
  busy.value = name
  try {
    const { data } = await projectSecretsApi.reveal(props.projectId, name)
    revealed.value[name] = data?.value ?? ''
    // Shown briefly only
    setTimeout(() => { delete revealed.value[name] }, 30000)
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

function updatedLine(row) {
  if (!row.updated_at) return ''
  const date = new Date(row.updated_at).toLocaleString(locale.value)
  return row.updated_by?.name
    ? `${t('secretEnv.updatedAt', { date })} ${t('secretEnv.updatedBy', { name: row.updated_by.name })}`
    : t('secretEnv.updatedAt', { date })
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <section data-testid="secret-env-editor">
    <p class="section-title flex items-center gap-1.5">
      <KeyRound class="w-3.5 h-3.5" />{{ t('secretEnv.title') }}
      <span class="normal-case text-gray-400 font-normal">{{ t('common.optional') }}</span>
    </p>
    <p class="text-xs text-gray-400 mb-3">{{ t('secretEnv.hint') }}<template v-if="remote"> {{ t('secretEnv.deployHint') }}</template></p>

    <p v-if="loading" class="text-xs text-gray-400">{{ t('common.loading') }}</p>
    <p v-else-if="loadError" class="text-xs text-red-400">{{ t('secretEnv.loadError') }}</p>

    <ul v-else-if="rows.length" class="space-y-1.5 mb-3">
      <li
        v-for="row in rows" :key="row.name"
        class="rounded-lg border border-gray-800 bg-gray-900/60 px-3 py-2"
        :data-testid="`secret-row-${row.name}`"
      >
        <div class="flex items-center gap-2">
          <span class="font-mono text-xs text-gray-100 truncate">{{ row.name }}</span>
          <span class="font-mono text-xs text-gray-400 truncate flex-1" :data-testid="`secret-value-${row.name}`">
            {{ revealed[row.name] !== undefined ? revealed[row.name] : '••••••••' }}
          </span>
          <span v-if="row.pending" class="text-[11px] text-gray-400">{{ t('secretEnv.pending') }}</span>
          <template v-else>
            <button
              v-if="canViewValues" type="button" class="icon-btn" :disabled="busy === row.name"
              :title="revealed[row.name] !== undefined ? t('secretEnv.hide') : t('secretEnv.reveal')"
              :data-testid="`secret-reveal-${row.name}`" @click="toggleReveal(row.name)"
            >
              <EyeOff v-if="revealed[row.name] !== undefined" class="w-3.5 h-3.5" />
              <Eye v-else class="w-3.5 h-3.5" />
            </button>
            <button
              type="button" class="icon-btn" :disabled="busy === row.name" :title="t('secretEnv.replace')"
              :data-testid="`secret-replace-${row.name}`" @click="startReplace(row.name)"
            >
              <Pencil class="w-3.5 h-3.5" />
            </button>
          </template>
          <button
            type="button" class="icon-btn hover:text-red-400" :disabled="busy === row.name" :title="t('secretEnv.remove')"
            :data-testid="`secret-remove-${row.name}`" @click="remove(row.name)"
          >
            <Trash2 class="w-3.5 h-3.5" />
          </button>
        </div>
        <p v-if="!row.pending && updatedLine(row)" class="text-[11px] text-gray-500 mt-0.5">{{ updatedLine(row) }}</p>

        <div v-if="replacing === row.name" class="flex gap-2 mt-2">
          <input
            v-model="replaceValue" type="password" autocomplete="new-password" class="input font-mono text-xs flex-1"
            :placeholder="t('secretEnv.replacePlaceholder')" :data-testid="`secret-replace-input-${row.name}`"
            @keydown.enter.prevent="saveReplace(row.name)"
          />
          <button type="button" class="btn-primary text-xs" :disabled="!replaceValue || busy === row.name" @click="saveReplace(row.name)">
            {{ t('secretEnv.save') }}
          </button>
          <button type="button" class="btn-secondary text-xs" @click="replacing = null">{{ t('secretEnv.cancel') }}</button>
        </div>
      </li>
    </ul>
    <p v-else class="text-xs text-gray-500 mb-3">{{ t('secretEnv.empty') }}</p>

    <div v-if="!loadError" class="flex gap-2">
      <input
        v-model="newName" class="input font-mono text-xs w-1/3" :placeholder="t('secretEnv.namePlaceholder')"
        autocomplete="off" spellcheck="false" data-testid="secret-new-name"
      />
      <input
        v-model="newValue" type="password" autocomplete="new-password" class="input font-mono text-xs flex-1"
        :placeholder="t('secretEnv.valuePlaceholder')" data-testid="secret-new-value" @keydown.enter.prevent="add"
      />
      <button type="button" class="btn-secondary text-xs flex items-center gap-1" :disabled="!!busy" data-testid="secret-add" @click="add">
        <Plus class="w-3.5 h-3.5" />{{ t('secretEnv.add') }}
      </button>
    </div>
    <p v-if="formError" class="text-xs text-red-400 mt-1" data-testid="secret-error">{{ formError }}</p>
  </section>
</template>

<style scoped>
@reference "../../assets/main.css";
.section-title {
  @apply text-xs text-gray-400 uppercase tracking-wider font-medium mb-3 border-b border-gray-800/60 pb-2;
}
.icon-btn {
  @apply p-1 rounded-sm text-gray-400 hover:text-gray-100 transition-colors disabled:opacity-50;
}
</style>
