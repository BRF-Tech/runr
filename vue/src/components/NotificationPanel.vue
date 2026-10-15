<script setup>
import { onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useNotificationStore } from '@/stores/notifications'
import { notificationTitle, notificationAction } from '@/utils/notifications'
import { AlertTriangle, AlertCircle, Info, CheckCheck, X, Trash2 } from 'lucide-vue-next'

const { t }  = useI18n()
const store  = useNotificationStore()
const router = useRouter()

onMounted(() => { store.fetchAll() })

const hasUnread     = computed(() => store.notifications.some(n => !n.read_at))
const hasAny        = computed(() => store.notifications.length > 0)

function levelIcon(level) {
  if (level === 'critical') return AlertCircle
  if (level === 'warning')  return AlertTriangle
  return Info
}

function levelColor(level) {
  if (level === 'critical') return 'text-red-400'
  if (level === 'warning')  return 'text-amber-400'
  return 'text-blue-400'
}

function timeAgo(dateStr) {
  if (!dateStr) return ''
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins  = Math.floor(diff / 60000)
  if (mins < 1)  return t('notifications.justNow')
  if (mins < 60) return t('notifications.minutesAgo', { n: mins })
  const hours = Math.floor(mins / 60)
  if (hours < 24) return t('notifications.hoursAgo', { n: hours })
  const days = Math.floor(hours / 24)
  return t('notifications.daysAgo', { n: days })
}

async function handleClick(n) {
  if (!n.read_at) await store.markRead(n.id)
  const action = notificationAction(n)
  if (action) router.push(action)
}

async function markAllRead() {
  if (hasUnread.value) await store.markAllRead()
}

async function removeOne(e, n) {
  e.stopPropagation()
  await store.remove(n.id)
}

async function removeAll() {
  await store.removeAll()
}
</script>

<template>
  <div class="absolute right-0 mt-2 w-80 max-h-96 rounded-md border border-gray-800 bg-gray-900 shadow-xl z-50 flex flex-col">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 border-b border-gray-800">
      <h3 class="text-sm font-semibold text-gray-100">{{ t('notifications.title') }}</h3>
      <div class="flex items-center gap-2">
        <button
          v-if="hasUnread"
          class="flex items-center gap-1 text-xs text-gray-400 hover:text-brand-500 transition-colors"
          :title="t('notifications.markAllRead')"
          @click="markAllRead"
        >
          <CheckCheck class="w-3.5 h-3.5" />
        </button>
        <button
          v-if="hasAny"
          class="flex items-center gap-1 text-xs text-gray-400 hover:text-red-400 transition-colors"
          :title="t('notifications.deleteAll')"
          @click="removeAll"
        >
          <Trash2 class="w-3.5 h-3.5" />
        </button>
      </div>
    </div>

    <!-- List -->
    <div class="overflow-y-auto flex-1">
      <div v-if="store.loading" class="py-8 text-center text-gray-400 text-xs">
        {{ t('common.loading') }}
      </div>

      <div v-else-if="!store.notifications.length" class="py-8 text-center text-gray-400 text-xs">
        {{ t('notifications.empty') }}
      </div>

      <div
        v-for="n in store.notifications"
        :key="n.id"
        class="group w-full flex items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-gray-800/60 cursor-pointer"
        :class="n.read_at ? 'opacity-60' : ''"
        @click="handleClick(n)"
      >
        <component
          :is="levelIcon(n.level)"
          class="w-4 h-4 mt-0.5 shrink-0"
          :class="levelColor(n.level)"
        />
        <div class="min-w-0 flex-1">
          <p class="text-sm text-gray-100 truncate">{{ notificationTitle(n, t) }}</p>
          <p v-if="n.body" class="text-xs text-gray-400 truncate mt-0.5">{{ n.body }}</p>
          <p class="text-[10px] text-gray-400 mt-1">{{ timeAgo(n.created_at) }}</p>
        </div>
        <div class="flex items-center gap-1.5 shrink-0 mt-0.5">
          <div v-if="!n.read_at" class="w-2 h-2 rounded-full bg-blue-500"></div>
          <button
            class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-red-400 transition-all"
            :title="t('notifications.delete')"
            @click="removeOne($event, n)"
          >
            <X class="w-3.5 h-3.5" />
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
