<script setup>
import { ref, watch, computed, inject } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Paperclip, Info } from 'lucide-vue-next'
import { createTicketWithFiles, fetchTicketSuggestions } from '../api/support'
import { useTicketDraft } from '../composables/useTicketDraft'
import { createTutorial } from '@/composables/useTutorial'
import { createTicketSteps } from '@/composables/tutorialSteps'
import FilePreview from './FilePreview.vue'

const emit = defineEmits(['close', 'created'])
const { t } = useI18n()
const { start: startTutorial } = createTutorial(createTicketSteps, {
  onComplete: () => emit('close'),
})
defineExpose({ startTutorial })

const tenantStore = inject('tenantStore', null)
const tenantSlug  = computed(() => tenantStore?.activeTenant?.value?.slug)
const { form, restored, clear: clearDraft } = useTicketDraft(tenantSlug.value)

const saving    = ref(false)
const error     = ref('')
const files     = ref([])
const fileInput = ref(null)

const touched = ref({ subject: false, description: false })
const subjectError = computed(() => touched.value.subject && !form.value.subject.trim())
const descError    = computed(() => touched.value.description && !form.value.description.trim())

const suggestions    = ref([])
const showSuggestions = ref(false)
let suggestTimer = null

const categories = ['faas', 'xaas', 'caas', 'deployment', 'quota', 'region', 'account', 'other']
const priorities = ['low', 'medium', 'high', 'critical']
const priorityChipActive = {
  low:      'bg-gray-700/60 text-gray-200 border-gray-500',
  medium:   'bg-yellow-900/30 text-yellow-300 border-yellow-700/50',
  high:     'bg-orange-900/30 text-orange-300 border-orange-700/50',
  critical: 'bg-red-900/30 text-red-300 border-red-700/50',
}

watch(() => form.value.subject, (val) => {
  clearTimeout(suggestTimer)
  if (val.trim().length < 5) { suggestions.value = []; showSuggestions.value = false; return }
  suggestTimer = setTimeout(async () => {
    try {
      const { data } = await fetchTicketSuggestions(val.trim())
      suggestions.value = data || []
      showSuggestions.value = suggestions.value.length > 0
    } catch { suggestions.value = [] }
  }, 500)
})

function onFileSelect(e) {
  files.value = [...files.value, ...Array.from(e.target.files || [])].slice(0, 3)
  e.target.value = ''
}

function removeFile(idx) { files.value = files.value.filter((_, i) => i !== idx) }

async function submit() {
  touched.value = { subject: true, description: true }
  if (!form.value.subject.trim() || !form.value.description.trim()) return
  saving.value = true
  error.value  = ''
  try {
    const fd = new FormData()
    fd.append('subject', form.value.subject)
    fd.append('category', form.value.category)
    fd.append('priority', form.value.priority)
    fd.append('description', form.value.description)
    files.value.forEach(f => fd.append('attachments[]', f))
    const { data } = await createTicketWithFiles(fd)
    clearDraft()
    emit('created', data)
  } catch (e) {
    error.value = e.response?.data?.message || t('common.error')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="emit('close')">
    <div class="w-full max-w-2xl rounded-xl border border-gray-800 bg-gray-900 shadow-2xl">
      <div class="flex items-center justify-between px-6 py-5 border-b border-gray-800">
        <h2 class="text-base font-semibold text-gray-100">{{ t('account.ticketCreateTitle') }}</h2>
        <button class="text-gray-500 hover:text-gray-300 transition-colors" @click="emit('close')"><X class="w-5 h-5" /></button>
      </div>

      <div class="px-6 py-6 space-y-6 max-h-[75vh] overflow-y-auto">
        <div v-if="restored" class="flex items-center gap-2 text-xs text-amber-400 bg-amber-900/20 rounded-lg px-4 py-3 border border-amber-800/30">
          <Info class="w-4 h-4 shrink-0" />{{ t('account.ticketDraftRestored') }}
        </div>

        <div class="space-y-5">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ t('account.ticketSectionInfo') }}</p>
          <div class="relative" data-tour="ticket-subject">
            <label class="label mb-1.5">{{ t('account.ticketSubject') }}</label>
            <input v-model="form.subject" class="input h-10" :class="{ 'border-red-500': subjectError }" @blur="touched.subject = true" />
            <p v-if="subjectError" class="text-[11px] text-red-400 mt-1.5">{{ t('account.ticketValidationRequired') }}</p>
            <div v-if="showSuggestions" class="absolute z-10 top-full left-0 right-0 mt-1 bg-gray-800 border border-gray-700 rounded-lg shadow-xl overflow-hidden">
              <p class="text-[11px] text-gray-400 px-3 pt-2.5 pb-1 font-medium">{{ t('account.ticketSuggestions') }}</p>
              <p class="text-[11px] text-gray-500 px-3 pb-1.5">{{ t('account.ticketSuggestionHint') }}</p>
              <button v-for="s in suggestions" :key="s.id"
                class="w-full text-left px-3 py-2.5 hover:bg-gray-700/60 transition-colors border-t border-gray-700/50"
                @click="showSuggestions = false">
                <span class="text-xs text-gray-200">{{ s.subject }}</span>
                <span class="text-[11px] text-gray-500 ml-2">#{{ s.id }}</span>
              </button>
            </div>
          </div>

          <div class="grid grid-cols-2 gap-6" data-tour="ticket-category-priority">
            <div data-tour="ticket-category">
              <label class="label mb-2.5">{{ t('account.ticketCategory') }}</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="c in categories" :key="c" type="button"
                  class="text-xs font-medium px-3.5 py-2 rounded-lg border transition-all"
                  :class="form.category === c ? 'bg-brand-500/10 text-brand-500 border-brand-500/40' : 'bg-gray-800/60 text-gray-400 border-gray-700 hover:text-gray-200 hover:border-gray-600'"
                  @click="form.category = c">{{ t('account.cat_' + c) }}</button>
              </div>
            </div>
            <div>
              <label class="label mb-2.5">{{ t('account.ticketPriority') }}</label>
              <div class="flex flex-wrap gap-2">
                <button v-for="p in priorities" :key="p" type="button"
                  class="text-xs font-medium px-3.5 py-2 rounded-lg border transition-all"
                  :class="form.priority === p ? priorityChipActive[p] : 'bg-gray-800/60 text-gray-400 border-gray-700 hover:text-gray-200 hover:border-gray-600'"
                  @click="form.priority = p">{{ t('account.pri_' + p) }}</button>
              </div>
            </div>
          </div>
        </div>

        <div class="space-y-3" data-tour="ticket-description">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ t('account.ticketSectionDesc') }}</p>
          <textarea v-model="form.description" rows="6"
            class="input resize-none" :class="{ 'border-red-500': descError }"
            :placeholder="t('account.ticketDescPlaceholder')"
            @blur="touched.description = true"></textarea>
          <p v-if="descError" class="text-[11px] text-red-400 mt-1">{{ t('account.ticketValidationRequired') }}</p>
        </div>

        <div class="space-y-3" data-tour="ticket-attachments">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider">{{ t('account.ticketSectionAttach') }}</p>
          <FilePreview :files="files" @remove="removeFile" />
          <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple class="hidden" @change="onFileSelect" />
          <button v-if="files.length < 3" type="button"
            class="flex items-center gap-2 text-xs text-gray-400 hover:text-gray-200 transition-colors"
            @click="fileInput?.click()">
            <Paperclip class="w-4 h-4" />{{ t('adminTickets.addAttachment') }}
          </button>
          <p class="text-[11px] text-gray-600">{{ t('adminTickets.maxFiles') }}</p>
        </div>

        <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
      </div>

      <div class="px-6 py-5 border-t border-gray-800 flex justify-end gap-3">
        <button class="btn-secondary text-sm" @click="emit('close')">{{ t('common.cancel') }}</button>
        <button data-tour="ticket-submit" class="btn-primary text-sm" :disabled="saving || !form.subject.trim() || !form.description.trim()" @click="submit">
          {{ saving ? t('common.saving') : t('account.newTicket') }}
        </button>
      </div>
    </div>
  </div>
</template>
