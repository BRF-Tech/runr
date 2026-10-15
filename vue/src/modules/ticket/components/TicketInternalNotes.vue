<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ShieldAlert, Send } from 'lucide-vue-next'
import { sendAdminReply } from '../api/support'

const props = defineProps({
  ticketId: { type: [Number, String], required: true },
  tenantId: { type: String, default: null },
  replies:  { type: Array, default: () => [] },
})
const { t } = useI18n()

const noteText = ref('')
const sending  = ref(false)

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString(undefined, {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

async function addNote() {
  if (!noteText.value.trim() || sending.value) return
  sending.value = true
  try {
    const fd = new FormData()
    fd.append('message', noteText.value.trim())
    fd.append('is_internal', '1')
    await sendAdminReply(props.ticketId, fd, props.tenantId)
    noteText.value = ''
  } finally {
    sending.value = false
  }
}
</script>

<template>
  <div class="h-full flex flex-col gap-4">
    <div class="flex items-center gap-2">
      <ShieldAlert class="w-3.5 h-3.5 text-amber-400" />
      <span class="text-[11px] font-semibold text-amber-400 uppercase tracking-wider">
        {{ t('account.ticketInternalNote') }}
      </span>
      <span class="text-[10px] text-amber-600">{{ t('account.ticketInternalLabel') }}</span>
    </div>

    <!-- Notes list -->
    <div class="flex-1 overflow-y-auto space-y-3">
      <p v-if="!replies.length" class="text-center text-sm text-gray-500 py-8">
        {{ t('account.ticketNoInternalNotes') }}
      </p>
      <div v-for="note in replies" :key="note.id"
        class="bg-amber-900/20 rounded-lg p-3 border border-amber-800/30">
        <p class="text-[10px] text-amber-500 mb-1">
          {{ note.user?.name || note.user?.email }} · {{ formatTime(note.created_at) }}
        </p>
        <p class="text-sm text-amber-200 whitespace-pre-wrap">{{ note.message }}</p>
      </div>
    </div>

    <!-- Add note input -->
    <div class="flex gap-2 pt-2 border-t border-amber-800/30">
      <input v-model="noteText"
        class="input flex-1 text-sm bg-amber-900/20 border-amber-800/40 placeholder-amber-600 text-amber-100 focus:border-amber-600"
        :placeholder="t('account.ticketInternalNote') + '...'"
        @keyup.enter="noteText.trim() && addNote()" />
      <button class="h-9 w-9 flex items-center justify-center rounded-lg border border-amber-700/50 text-amber-400 hover:text-amber-200 hover:border-amber-600 transition-colors"
        :disabled="!noteText.trim() || sending" @click="addNote">
        <Send class="w-3.5 h-3.5" />
      </button>
    </div>
  </div>
</template>
