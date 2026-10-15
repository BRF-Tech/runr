<script setup>
import { useI18n } from 'vue-i18n'
import { useConfirm } from '@/composables/useConfirm'
import { Monitor, Smartphone, Tablet, Globe } from 'lucide-vue-next'

const { t } = useI18n()
const { confirm } = useConfirm()
const props = defineProps({ sessions: { type: Array, default: () => [] }, loading: Boolean })
const emit  = defineEmits(['revoke', 'revokeAll'])

function deviceIcon(name) {
  if (name === 'Mobile') return Smartphone
  if (name === 'Tablet') return Tablet
  return Monitor
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

async function revokeOne(session) {
  const ok = await confirm({
    title:       t('account.revokeSession'),
    message:     t('account.revokeConfirm'),
    destructive: true,
  })
  if (ok) emit('revoke', session.id)
}

async function revokeAll() {
  const ok = await confirm({
    title:       t('account.revokeAll'),
    message:     t('account.revokeAllConfirm'),
    destructive: true,
  })
  if (ok) emit('revokeAll')
}
</script>

<template>
  <div class="card p-6">
    <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-800">
      <div class="flex items-center gap-2">
        <Globe class="w-4 h-4" style="color:rgb(var(--brand-500))" />
        <h3 class="text-sm font-semibold text-gray-100">{{ t('account.sessionsTitle') }}</h3>
      </div>
      <button v-if="props.sessions?.length > 1" class="btn-secondary text-xs text-red-400 hover:text-red-300" @click="revokeAll">
        {{ t('account.revokeAll') }}
      </button>
    </div>

    <p class="text-xs text-gray-400 mb-4">{{ t('account.sessionsDesc') }}</p>

    <div v-if="props.loading" class="py-8 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>

    <div v-else class="space-y-2">
      <div v-for="session in props.sessions" :key="session.id"
        class="flex items-center gap-3 rounded-md border border-gray-800 bg-gray-900/60 px-4 py-3">
        <component :is="deviceIcon(session.device_name)" class="w-5 h-5 text-gray-500 shrink-0" />
        <div class="flex-1 min-w-0">
          <div class="flex items-center gap-2">
            <span class="text-sm text-gray-200 truncate">{{ session.device_name || 'Unknown' }}</span>
            <span v-if="session.is_current" class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-brand-500/20 text-brand-500 border border-brand-500/30">
              {{ t('account.currentSession') }}
            </span>
          </div>
          <div class="flex items-center gap-3 text-xs text-gray-500 mt-0.5">
            <span v-if="session.ip_address">{{ session.ip_address }}</span>
            <span>{{ t('account.lastActive') }}: {{ formatTime(session.last_used_at) }}</span>
          </div>
        </div>
        <button v-if="!session.is_current" class="text-xs text-red-400 hover:text-red-300 shrink-0" @click="revokeOne(session)">
          {{ t('account.revokeSession') }}
        </button>
      </div>
    </div>
  </div>
</template>
