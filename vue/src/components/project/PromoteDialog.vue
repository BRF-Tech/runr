<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, ShieldCheck, ChevronDown, ChevronRight, AlertTriangle } from 'lucide-vue-next'
import { environmentsApi } from '@/api/environments'

/**
 * Promote what an environment runs to the next environment (or a chosen one) —
 * docs/environments.md#promotion. First what will change in the target: its code version and
 * image, its configuration (the target keeps its own: only what changes besides the code), env
 * vars, secrets and bindings by name only — values are never shown. Into an environment that
 * requires approval the request waits for an approver.
 */
const props = defineProps({
  projectId:    { type: String, required: true },
  // The environment promoted from (its entry of GET …/environments)
  source:       { type: Object, required: true },
  environments: { type: Array, default: () => [] },
})

const emit = defineEmits(['promoted', 'close'])

const { t } = useI18n()

const targets = computed(() => props.environments.filter(e => e.id !== props.source.id))
const nextTarget = computed(() => {
  const i = props.environments.findIndex(e => e.id === props.source.id)
  return i >= 0 ? props.environments[i + 1] ?? null : null
})
const target = ref(nextTarget.value?.name ?? targets.value[0]?.name ?? null)

const preview     = ref(null)
const loading     = ref(false)
const error       = ref(null)
const message     = ref('')
const submitting  = ref(false)
const showSourceDiff = ref(false)

const fromName = computed(() => (props.source.is_primary ? 'production' : props.source.name))

async function load() {
  if (!target.value) return
  loading.value = true
  error.value   = null
  try {
    const { data } = await environmentsApi.promotePreview(props.projectId, fromName.value, target.value)
    preview.value = data.data ?? data
  } catch (e) {
    preview.value = null
    error.value   = e.response?.data?.message ?? t('common.error')
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(target, load)

/** A field's label: the known ones translated, others as they are. */
function fieldLabel(field) {
  const key = `environments.promoteDialog.fields.${field}`
  const label = t(key)
  return label === key ? field : label
}

/** A scalar value for the diff (never an env var: those come as names). */
function show(value) {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? t('environments.form.on') : t('environments.form.off')
  if (Array.isArray(value)) return value.join(' ')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

const isNames = change => Array.isArray(change.added) || Array.isArray(change.removed) || Array.isArray(change.changed)

async function submit() {
  if (submitting.value || !preview.value?.can_promote) return
  submitting.value = true
  error.value = null
  try {
    const { data } = await environmentsApi.promote(props.projectId, fromName.value, {
      to: target.value,
      ...(message.value.trim() ? { message: message.value.trim() } : {}),
    })
    emit('promoted', data.data ?? data)
  } catch (e) {
    error.value = e.response?.data?.message ?? t('common.error')
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4" data-testid="promote-dialog">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="$emit('close')" />
    <div class="relative card w-full max-w-2xl max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold">{{ t('environments.promoteDialog.title', { from: fromName }) }}</h2>
        <button class="text-gray-400 hover:text-gray-100" @click="$emit('close')">✕</button>
      </div>

      <!-- Target -->
      <div class="flex items-center gap-3 mb-4">
        <span class="font-medium text-sm text-gray-200">{{ fromName }}</span>
        <ArrowRight class="w-4 h-4 text-gray-400" />
        <select v-model="target" class="input w-auto" data-testid="promote-target" :aria-label="t('environments.promoteDialog.to')">
          <option v-for="env in targets" :key="env.id" :value="env.is_primary ? 'production' : env.name">{{ env.name }}</option>
        </select>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-10">
        <div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" />
      </div>

      <template v-else-if="preview">
        <!-- Nothing to promote -->
        <div v-if="preview.problem" class="flex items-start gap-2.5 bg-yellow-900/20 border border-yellow-800/40 rounded-md px-4 py-3 mb-4">
          <AlertTriangle class="w-4 h-4 text-yellow-400 mt-0.5 shrink-0" />
          <p class="text-xs text-yellow-300">{{ preview.problem }}</p>
        </div>

        <!-- Code -->
        <section class="mb-4" data-testid="promote-code">
          <h3 class="label mb-1.5">{{ t('environments.promoteDialog.code') }}</h3>
          <div class="flex items-center gap-2 text-sm">
            <span class="font-mono text-gray-400">{{ preview.code?.from?.label ?? '—' }}</span>
            <ArrowRight class="w-3.5 h-3.5 text-gray-400" />
            <span class="font-mono" style="color:rgb(var(--brand-500))">{{ preview.code?.to?.label ?? '—' }}</span>
            <span v-if="preview.code && !preview.code.changes" class="text-xs text-gray-400">{{ t('environments.promoteDialog.noCodeChange') }}</span>
          </div>
          <p v-if="preview.code?.to?.image" class="text-[11px] font-mono text-gray-400 mt-1 truncate" :title="preview.code.to.image">
            {{ t('environments.promoteDialog.image') }}: {{ preview.code.to.image }}
          </p>
        </section>

        <!-- Configuration changes in the target -->
        <section class="mb-4" data-testid="promote-config">
          <h3 class="label mb-1.5">{{ t('environments.promoteDialog.config', { environment: target }) }}</h3>
          <ul v-if="preview.config?.length" class="space-y-1 text-xs">
            <li v-for="change in preview.config" :key="change.field" class="flex items-start gap-2" :data-testid="`promote-change-${change.field}`">
              <span class="text-gray-300 min-w-32">{{ fieldLabel(change.field) }}</span>
              <span v-if="isNames(change)" class="text-gray-400">
                <span v-if="change.added?.length" class="text-emerald-400">+ {{ change.added.join(', ') }}</span>
                <span v-if="change.removed?.length" class="text-red-400 ml-2">− {{ change.removed.join(', ') }}</span>
                <span v-if="change.changed?.length" class="text-amber-300 ml-2">~ {{ change.changed.join(', ') }}</span>
              </span>
              <span v-else class="font-mono text-gray-400">{{ show(change.from) }} → <span class="text-gray-200">{{ show(change.to) }}</span></span>
            </li>
          </ul>
          <p v-else class="text-xs text-gray-400">{{ t('environments.promoteDialog.noConfigChange') }}</p>
          <p class="text-[11px] text-gray-500 mt-1.5">{{ t('environments.promoteDialog.valuesHidden') }}</p>
        </section>

        <!-- How the source's config differs from the target's (information) -->
        <section v-if="preview.environment_differences?.length" class="mb-4">
          <button class="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-200" @click="showSourceDiff = !showSourceDiff">
            <component :is="showSourceDiff ? ChevronDown : ChevronRight" class="w-3.5 h-3.5" />
            {{ t('environments.promoteDialog.differences', { from: fromName, to: target, n: preview.environment_differences.length }) }}
          </button>
          <ul v-if="showSourceDiff" class="space-y-1 text-xs mt-2 ml-4">
            <li v-for="change in preview.environment_differences" :key="change.field" class="flex items-start gap-2">
              <span class="text-gray-300 min-w-32">{{ fieldLabel(change.field) }}</span>
              <span v-if="isNames(change)" class="text-gray-400">
                <span v-if="change.added?.length">+ {{ change.added.join(', ') }}</span>
                <span v-if="change.removed?.length" class="ml-2">− {{ change.removed.join(', ') }}</span>
                <span v-if="change.changed?.length" class="ml-2">~ {{ change.changed.join(', ') }}</span>
              </span>
              <span v-else class="font-mono text-gray-400">{{ show(change.from) }} → {{ show(change.to) }}</span>
            </li>
          </ul>
        </section>

        <!-- Approval -->
        <div v-if="preview.requires_approval" class="flex items-start gap-2.5 bg-amber-900/20 border border-amber-800/40 rounded-md px-4 py-3 mb-4" data-testid="promote-approval">
          <ShieldCheck class="w-4 h-4 text-amber-400 mt-0.5 shrink-0" />
          <p class="text-xs text-amber-200">{{ t('environments.promoteDialog.approvalNotice', { environment: target }) }}</p>
        </div>

        <label class="label mb-1.5" for="promote-message">{{ t('environments.promoteDialog.message') }}</label>
        <textarea
          id="promote-message"
          v-model="message"
          rows="2"
          maxlength="2000"
          class="input w-full"
          :placeholder="t('environments.promoteDialog.messagePlaceholder')"
        />
      </template>

      <p v-if="error" class="text-xs text-red-400 mt-3">{{ error }}</p>

      <div class="flex gap-3 mt-5 pt-4 border-t border-gray-800/60">
        <button class="btn-secondary flex-1" @click="$emit('close')">{{ t('common.cancel') }}</button>
        <button
          class="btn-primary flex-1"
          :disabled="submitting || loading || !preview?.can_promote"
          data-testid="promote-submit"
          @click="submit"
        >{{ preview?.requires_approval ? t('environments.promoteDialog.submitApproval') : t('environments.promoteDialog.submit') }}</button>
      </div>
    </div>
  </div>
</template>
