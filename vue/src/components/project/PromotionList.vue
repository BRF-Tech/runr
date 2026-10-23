<script setup>
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ArrowRight, Check, X, Undo2 } from 'lucide-vue-next'
import { environmentsApi } from '@/api/environments'
import { useToast } from '@/composables/useToast'

/**
 * Promotions (docs/environments.md#promotion), newest first: from → to, the version and image,
 * who asked and why, the status — and, while one waits for approval, approve / reject (someone
 * with environments.approve other than the requester: can_approve) or withdraw it (the requester:
 * can_cancel). The approvals inbox and a project's environments tab show it.
 */
defineProps({
  promotions:  { type: Array, default: () => [] },
  // The approvals inbox lists several projects: name them
  showProject: { type: Boolean, default: false },
})

const emit = defineEmits(['changed'])

const { t, locale } = useI18n()
const toast = useToast()

const busy     = ref({})   // promotion id → 'approve' | 'reject' | 'cancel'
const comments = ref({})   // promotion id → decision comment
const errors   = ref({})   // promotion id → refusal message

const STATUS_CLASS = {
  pending_approval: 'bg-amber-900/40 text-amber-300 border-amber-800/60',
  approved:         'bg-blue-900/40 text-blue-300 border-blue-800/60',
  deploying:        'bg-blue-900/40 text-blue-300 border-blue-800/60',
  deployed:         'bg-emerald-900/40 text-emerald-300 border-emerald-800/60',
  failed:           'bg-red-900/40 text-red-300 border-red-800/60',
  rejected:         'bg-red-900/40 text-red-300 border-red-800/60',
}

function fmt(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString(locale.value === 'tr' ? 'tr-TR' : 'en-US', { dateStyle: 'short', timeStyle: 'short' })
}

async function act(p, action) {
  if (busy.value[p.id]) return
  busy.value = { ...busy.value, [p.id]: action }
  errors.value = { ...errors.value, [p.id]: null }
  try {
    const comment = comments.value[p.id]?.trim() || null
    const { data } = action === 'approve'
      ? await environmentsApi.approve(p.project_id, p.id, comment)
      : action === 'reject'
        ? await environmentsApi.reject(p.project_id, p.id, comment)
        : await environmentsApi.cancel(p.project_id, p.id)
    const updated = data.data ?? data
    toast.success(action === 'approve'
      ? t('environments.promotions.approved', { version: updated.version?.label ?? p.version?.label ?? '?', environment: updated.target ?? p.target })
      : t(action === 'reject' ? 'environments.promotions.rejected' : 'environments.promotions.cancelled'))
    emit('changed', updated)
  } catch (e) {
    errors.value = { ...errors.value, [p.id]: e.response?.data?.message ?? t('common.error') }
  } finally {
    const next = { ...busy.value }
    delete next[p.id]
    busy.value = next
  }
}
</script>

<template>
  <div class="space-y-2" data-testid="promotion-list">
    <div
      v-for="p in promotions"
      :key="p.id"
      class="rounded-md border border-gray-800 px-4 py-3"
      :data-testid="`promotion-${p.id}`"
    >
      <div class="flex items-center gap-3 flex-wrap">
        <span v-if="showProject && p.project" class="text-sm font-semibold text-gray-100">{{ p.project.name }}</span>
        <span class="flex items-center gap-1.5 text-sm text-gray-200">
          <span class="font-medium">{{ p.source }}</span>
          <ArrowRight class="w-3.5 h-3.5 text-gray-400" />
          <span class="font-medium">{{ p.target }}</span>
        </span>
        <span v-if="p.version" class="font-mono text-sm" style="color:rgb(var(--brand-500))">{{ p.version.label }}</span>
        <span
          class="text-[11px] px-2 py-0.5 rounded-md border"
          :class="STATUS_CLASS[p.status] ?? 'bg-gray-800 text-gray-400 border-gray-700'"
        >{{ t(`environments.promotions.status.${p.status}`, p.status) }}</span>
        <span class="text-xs text-gray-400 ml-auto">
          {{ p.requested_by ? t('environments.promotions.requestedBy', { name: p.requested_by }) : '' }} · {{ fmt(p.created_at) }}
        </span>
      </div>

      <p v-if="p.image" class="text-[11px] font-mono text-gray-400 mt-1 truncate" :title="p.image">{{ p.image }}</p>
      <p v-if="p.message" class="text-xs text-gray-300 mt-1.5">“{{ p.message }}”</p>
      <p v-if="p.status_message" class="text-xs text-gray-400 mt-1">{{ p.status_message }}</p>
      <p v-if="p.decided_by" class="text-xs text-gray-400 mt-1">
        {{ t('environments.promotions.decidedBy', { name: p.decided_by }) }}<span v-if="p.decision_comment">: {{ p.decision_comment }}</span>
      </p>

      <!-- Waiting for approval: approve / reject (approvers), withdraw (the requester) -->
      <div v-if="p.can_approve || p.can_cancel" class="flex items-center gap-2 mt-3 flex-wrap">
        <input
          v-if="p.can_approve"
          v-model="comments[p.id]"
          class="input text-xs py-1.5 flex-1 min-w-48"
          :placeholder="t('environments.promotions.comment')"
          maxlength="2000"
          :data-testid="`promotion-comment-${p.id}`"
        />
        <button
          v-if="p.can_approve"
          class="btn-primary text-xs px-3 py-1.5"
          :disabled="!!busy[p.id]"
          :data-testid="`promotion-approve-${p.id}`"
          @click="act(p, 'approve')"
        ><Check class="w-3.5 h-3.5" />{{ t('environments.promotions.approve') }}</button>
        <button
          v-if="p.can_approve"
          class="btn-danger text-xs px-3 py-1.5"
          :disabled="!!busy[p.id]"
          :data-testid="`promotion-reject-${p.id}`"
          @click="act(p, 'reject')"
        ><X class="w-3.5 h-3.5" />{{ t('environments.promotions.reject') }}</button>
        <button
          v-if="p.can_cancel"
          class="btn-secondary text-xs px-3 py-1.5"
          :disabled="!!busy[p.id]"
          :data-testid="`promotion-cancel-${p.id}`"
          @click="act(p, 'cancel')"
        ><Undo2 class="w-3.5 h-3.5" />{{ t('environments.promotions.cancel') }}</button>
      </div>
      <p v-if="errors[p.id]" class="text-xs text-red-400 mt-2">{{ errors[p.id] }}</p>
    </div>

    <p v-if="!promotions.length" class="text-sm text-gray-400 py-6 text-center">{{ t('environments.promotions.none') }}</p>
  </div>
</template>
