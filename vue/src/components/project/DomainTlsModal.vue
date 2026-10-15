<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { projectsApi } from '@/api/projects'
import { Lock } from 'lucide-vue-next'

const { t } = useI18n()

const props = defineProps({
  projectId:         { type: [String, Number], required: true },
  domain:            { type: Object, required: true },
  certManagerIssuer: { type: String, default: null },
})

const emit = defineEmits(['saved', 'updated', 'close'])

const mode        = ref(props.domain.tls_mode ?? 'none')
const certificate = ref('')
const privateKey  = ref('')
const saving      = ref(false)
const errors      = ref({})
const message     = ref(null)

const options = computed(() => [
  { value: 'none',         label: t('project.domains.tls.none'),        hint: t('project.domains.tls.noneHint'),   disabled: false },
  { value: 'custom',       label: t('project.domains.tls.custom'),      hint: t('project.domains.tls.customHint'), disabled: false },
  {
    value:    'cert-manager',
    label:    t('project.domains.tls.certManager'),
    hint:     props.certManagerIssuer
      ? t('project.domains.tls.certManagerHint', { issuer: props.certManagerIssuer })
      : t('project.domains.tls.certManagerUnavailable'),
    disabled: !props.certManagerIssuer,
  },
])

const canSave = computed(() => {
  if (mode.value === 'custom') return certificate.value.trim() !== '' && privateKey.value.trim() !== ''
  if (mode.value === 'cert-manager') return !!props.certManagerIssuer
  return true
})

function fieldError(key) {
  const v = errors.value?.[key]
  return Array.isArray(v) ? v[0] : (v ?? null)
}

async function save() {
  if (!canSave.value || saving.value) return
  saving.value  = true
  errors.value  = {}
  message.value = null
  try {
    const payload = { mode: mode.value }
    if (mode.value === 'custom') {
      payload.certificate = certificate.value
      payload.privateKey  = privateKey.value
    }
    const { data } = await projectsApi.updateDomainTls(props.projectId, props.domain.id, payload)
    emit('saved', { domain: data.data ?? null, message: data.message ?? null })
  } catch (e) {
    const body = e.response?.data ?? {}
    // Saved, but routing on the region failed: the row carries the new state
    if (body.data) emit('updated', body.data)
    errors.value = body.errors ?? {}
    const hasFieldError = ['mode', 'certificate', 'privateKey'].some(k => fieldError(k))
    message.value = hasFieldError ? null : (body.message ?? t('common.error'))
  } finally {
    saving.value = false
  }
}

function onKeydown(e) {
  if (e.key === 'Escape' && !saving.value) emit('close')
}
onMounted(() => window.addEventListener('keydown', onKeydown))
onUnmounted(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-[2px] p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col">

      <!-- Header -->
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-800">
        <div class="min-w-0">
          <h3 class="text-sm font-semibold text-gray-100">{{ t('project.domains.tls.title') }}</h3>
          <p class="text-xs font-mono text-gray-400 truncate mt-0.5">{{ domain.domain }}</p>
        </div>
        <button class="text-gray-400 hover:text-gray-100 transition-colors text-sm" :disabled="saving" @click="emit('close')">✕</button>
      </div>

      <!-- Body -->
      <div class="px-6 py-5 space-y-4 overflow-y-auto">

        <!-- Current state (server-rendered explanation) -->
        <div v-if="domain.tls_note" class="flex items-start gap-2 bg-gray-800/60 rounded-md px-3 py-2.5">
          <Lock class="w-3.5 h-3.5 text-gray-400 mt-0.5 shrink-0" />
          <p class="text-xs text-gray-300 leading-relaxed">
            <span class="text-gray-400">{{ t('project.domains.tls.current') }}:</span> {{ domain.tls_note }}
          </p>
        </div>

        <!-- Mode -->
        <div>
          <label class="label">{{ t('project.domains.tls.mode') }}</label>
          <div class="space-y-2">
            <label
              v-for="opt in options" :key="opt.value"
              class="flex items-start gap-2.5 rounded-md border px-3 py-2.5 transition-colors"
              :class="[
                mode === opt.value ? 'border-brand-500/50 bg-brand-900/20' : 'border-gray-800',
                opt.disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:border-gray-700',
              ]"
            >
              <input
                v-model="mode" type="radio" :value="opt.value" :disabled="opt.disabled || saving"
                class="mt-0.5 accent-brand-500 focus:ring-brand-500 bg-gray-800 border-gray-700"
              />
              <span class="min-w-0">
                <span class="block text-sm text-gray-100">{{ opt.label }}</span>
                <span class="block text-xs text-gray-400 mt-0.5">{{ opt.hint }}</span>
              </span>
            </label>
          </div>
          <p v-if="fieldError('mode')" class="text-xs text-red-400 mt-1.5">{{ fieldError('mode') }}</p>
        </div>

        <!-- Custom certificate -->
        <div v-if="mode === 'custom'" class="space-y-3">
          <p v-if="domain.tls_mode === 'custom'" class="text-xs text-gray-400">{{ t('project.domains.tls.customReplaceHint') }}</p>
          <div>
            <label class="label">{{ t('project.domains.tls.certificate') }}</label>
            <textarea
              v-model="certificate" rows="6" spellcheck="false"
              class="input font-mono text-xs resize-y"
              placeholder="-----BEGIN CERTIFICATE-----"
            />
            <p v-if="fieldError('certificate')" class="text-xs text-red-400 mt-1">{{ fieldError('certificate') }}</p>
          </div>
          <div>
            <label class="label">{{ t('project.domains.tls.privateKey') }}</label>
            <textarea
              v-model="privateKey" rows="6" spellcheck="false"
              class="input font-mono text-xs resize-y"
              placeholder="-----BEGIN PRIVATE KEY-----"
            />
            <p v-if="fieldError('privateKey')" class="text-xs text-red-400 mt-1">{{ fieldError('privateKey') }}</p>
          </div>
        </div>

        <p v-if="message" class="text-xs text-red-400">{{ message }}</p>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-gray-800">
        <button
          class="text-sm text-gray-400 hover:text-gray-100 transition-colors px-4 py-2"
          :disabled="saving"
          @click="emit('close')"
        >{{ t('common.cancel') }}</button>
        <button class="btn-primary" :disabled="saving || !canSave" @click="save">
          {{ saving ? t('common.saving') : t('project.domains.tls.save') }}
        </button>
      </div>

    </div>
  </div>
</template>
