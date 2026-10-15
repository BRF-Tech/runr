<script setup>
import { ref, computed, reactive, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { X, Send, Paperclip, Download, Maximize2 } from 'lucide-vue-next'
import client from '@/api/client'
import { fetchTicket, sendReply, fetchAdminTicket, updateTicketStatus, sendAdminReply, attachmentUrl as userAttUrl, adminAttachmentUrl, markRead, adminMarkRead } from '../api/support'
import { useAuthStore } from '@/stores/auth'
import { useTicketDetailRealtime } from '../composables/useTicketRealtime'
import FilePreview from './FilePreview.vue'
import TicketInternalNotes from './TicketInternalNotes.vue'
import TicketSeenIndicator from './TicketSeenIndicator.vue'

const props = defineProps({
  ticket: { type: Object, required: true },
  admin:  { type: Boolean, default: false },
})
const emit  = defineEmits(['close', 'updated'])
const { t } = useI18n()
const auth  = useAuthStore()

const detail = ref(null), replies = ref([]), message = ref(''), sending = ref(false)
const chatEl = ref(null), files = ref([]), fileInput = ref(null)
const statusLoading = ref(false), lightboxSrc = ref(null), activeTab = ref('conversation')
const adminReadAt = ref(null), userReadAt = ref(null)
const statuses = ['open', 'in_progress', 'resolved', 'closed']
const priorityClass = { low: 'bg-gray-700 text-gray-300', medium: 'bg-yellow-900/60 text-yellow-300', high: 'bg-orange-900/60 text-orange-300', critical: 'bg-red-900/60 text-red-300' }
const statusClass = { open: 'bg-blue-900/60 text-blue-300', in_progress: 'bg-yellow-900/60 text-yellow-300', resolved: 'bg-green-900/60 text-green-300', closed: 'bg-gray-700 text-gray-300' }
const publicReplies   = computed(() => replies.value.filter(r => !r.is_internal))
const internalReplies = computed(() => replies.value.filter(r => r.is_internal))
const currentTags     = computed(() => detail.value?.tags || [])
const tenantId        = computed(() => props.ticket.tenant_id || null)
const ticketUser      = props.ticket.user || { name: auth.user?.name, email: auth.user?.email }

function formatTime(iso) { return iso ? new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '' }
function scrollToBottom() { nextTick(() => { if (chatEl.value) chatEl.value.scrollTop = chatEl.value.scrollHeight }) }
function isImage(path) { return /\.(jpg|jpeg|png|gif|webp)$/i.test(path) }
function getApiPath(path) { return props.admin ? adminAttachmentUrl(props.ticket.id, path, tenantId.value) : userAttUrl(props.ticket.id, path) }

const blobCache = reactive({})
const blobUrls  = []

async function fetchBlobUrl(path) {
  if (blobCache[path]) return blobCache[path]
  try {
    const url = getApiPath(path)
    const relative = url.replace(client.defaults.baseURL, '')
    const { data } = await client.get(relative, { responseType: 'blob' })
    const blobUrl = URL.createObjectURL(data)
    blobCache[path] = blobUrl
    blobUrls.push(blobUrl)
    return blobUrl
  } catch { return null }
}

async function downloadFile(path) {
  const blobUrl = await fetchBlobUrl(path)
  if (!blobUrl) return
  const a = document.createElement('a')
  a.href = blobUrl; a.download = path.split('/').pop(); a.click()
}

async function openLightbox(path) {
  const blobUrl = await fetchBlobUrl(path)
  if (blobUrl) lightboxSrc.value = blobUrl
}

function prefetchImages() {
  const allAtts = [...(detail.value?.attachments || []), ...replies.value.flatMap(r => r.attachments || [])]
  allAtts.filter(isImage).forEach(fetchBlobUrl)
}

onBeforeUnmount(() => { blobUrls.forEach(URL.revokeObjectURL) })

async function loadDetail() {
  const { data } = props.admin
    ? await fetchAdminTicket(props.ticket.id, tenantId.value)
    : await fetchTicket(props.ticket.id)
  detail.value  = data
  replies.value = data.replies || []
  scrollToBottom()
  prefetchImages()
}

async function changeStatus(status) {
  statusLoading.value = true
  try {
    await updateTicketStatus(props.ticket.id, status, tenantId.value)
    detail.value.status = status
    emit('updated')
  } finally { statusLoading.value = false }
}

function onFileSelect(e) {
  files.value = [...files.value, ...Array.from(e.target.files || [])].slice(0, 3)
  e.target.value = ''
}
function removeFile(idx) { files.value.splice(idx, 1) }

async function send() {
  if ((!message.value.trim() && !files.value.length) || sending.value) return
  sending.value = true
  try {
    const fd = new FormData()
    fd.append('message', message.value.trim())
    files.value.forEach(f => fd.append('attachments[]', f))
    props.admin
      ? await sendAdminReply(props.ticket.id, fd, tenantId.value)
      : await sendReply(props.ticket.id, fd)
    message.value = ''
    files.value = []
  } finally { sending.value = false }
}

const { subscribe: subscribeToTicket } = useTicketDetailRealtime(
  props.ticket.id,
  (reply) => { replies.value.push(reply); scrollToBottom(); (reply.attachments || []).filter(isImage).forEach(fetchBlobUrl) },
  (data) => { adminReadAt.value = data.admin_read_at; userReadAt.value = data.user_read_at },
  (note) => { replies.value.push(note) },
  (status) => { if (detail.value) detail.value.status = status },
)

function markAsRead() {
  props.admin ? adminMarkRead(props.ticket.id, tenantId.value).catch(() => {}) : markRead(props.ticket.id).catch(() => {})
}

onMounted(() => { loadDetail(); subscribeToTicket(); markAsRead() })
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60" @click.self="emit('close')">
    <div class="w-full max-w-4xl rounded-xl border border-gray-800 bg-gray-900 shadow-2xl flex flex-col" style="max-height:92vh">
      <!-- Header -->
      <div class="px-8 py-5 border-b border-gray-800 shrink-0">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-3 min-w-0">
            <h2 class="text-lg font-semibold text-gray-100 truncate">{{ ticket.subject }}</h2>
            <span class="text-xs text-gray-500 font-mono shrink-0">#{{ ticket.id }}</span>
          </div>
          <button class="text-gray-500 hover:text-gray-300 transition-colors shrink-0" @click="emit('close')"><X class="w-5 h-5" /></button>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
          <span class="text-xs font-semibold px-2.5 py-1 rounded-sm" :class="statusClass[detail?.status || ticket.status]">
            {{ t('account.st_' + (detail?.status || ticket.status)) }}
          </span>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-sm" :class="priorityClass[ticket.priority]">
            {{ t('account.pri_' + ticket.priority) }}
          </span>
          <span class="text-xs font-semibold px-2.5 py-1 rounded-sm bg-gray-800 text-gray-400">{{ t('account.cat_' + ticket.category) }}</span>
          <span v-for="(tag, i) in currentTags" :key="i"
            class="text-xs font-medium px-2 py-0.5 rounded-full bg-brand-500/10 text-brand-500 border border-brand-500/20">{{ tag }}</span>
          <span v-if="admin" class="text-xs text-gray-500">· {{ ticketUser.name || ticketUser.email }}</span>
          <span class="ml-auto text-xs text-gray-500">{{ formatTime(ticket.created_at) }}</span>
        </div>
        <!-- Admin: status + tabs -->
        <div v-if="admin" class="flex items-center justify-between gap-3 mt-4 pt-4 border-t border-gray-800">
          <div class="flex items-center gap-2">
            <span class="text-xs text-gray-500 font-medium uppercase tracking-wider">{{ t('adminTickets.changeStatus') }}</span>
            <div class="flex gap-1.5">
              <button v-for="s in statuses" :key="s"
                class="text-xs font-semibold px-2.5 py-1 rounded-lg transition-colors border"
                :class="(detail?.status || ticket.status) === s ? statusClass[s] + ' border-transparent' : 'border-gray-700 text-gray-500 hover:text-gray-300 hover:border-gray-600'"
                :disabled="statusLoading" @click="changeStatus(s)">
                {{ t('account.st_' + s) }}
              </button>
            </div>
          </div>
          <div class="flex gap-1">
            <button v-for="tab in [['conversation', t('account.tabConversation')], ['notes', t('account.tabNotes')]]" :key="tab[0]"
              class="text-xs px-3 py-1.5 rounded-lg border transition-colors"
              :class="activeTab === tab[0] ? 'bg-brand-500/10 text-brand-500 border-brand-500/30' : 'border-gray-700 text-gray-500 hover:text-gray-300'"
              @click="activeTab = tab[0]">
              {{ tab[1] }}
            </button>
          </div>
        </div>
      </div>

      <!-- Conversation tab -->
      <template v-if="!admin || activeTab === 'conversation'">
        <div ref="chatEl" class="flex-1 overflow-y-auto px-8 py-7 space-y-7" style="min-height:350px">
          <!-- Original description -->
          <div class="flex gap-4">
            <div class="w-10 h-10 rounded-full shrink-0 flex items-center justify-center text-sm font-bold text-gray-900" style="background:linear-gradient(135deg,rgb(var(--brand-500)),rgb(var(--brand-700)))">
              {{ (ticketUser.email || '?')[0]?.toUpperCase() }}
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm text-gray-500 mb-2">{{ ticketUser.name || ticketUser.email }} · {{ formatTime(ticket.created_at) }}</p>
              <div class="text-sm text-gray-300 leading-relaxed whitespace-pre-wrap bg-gray-800/60 rounded-xl rounded-tl-none px-5 py-4 border border-gray-800">
                {{ ticket.description || detail?.description }}
              </div>
              <div v-if="(detail?.attachments || []).length" class="flex flex-wrap gap-3 mt-3">
                <template v-for="(att, i) in detail.attachments" :key="i">
                  <button v-if="isImage(att)" class="relative group rounded-lg overflow-hidden border border-gray-800 hover:border-gray-600 transition-colors" @click="openLightbox(att)">
                    <img v-if="blobCache[att]" :src="blobCache[att]" class="h-24 w-auto object-cover" />
                    <div v-else class="h-24 w-24 flex items-center justify-center bg-gray-800 text-gray-500 text-xs">...</div>
                    <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><Maximize2 class="w-5 h-5 text-white" /></div>
                  </button>
                  <button v-else class="inline-flex items-center gap-2 text-sm text-brand-500 hover:underline bg-gray-800/60 rounded-lg px-4 py-2.5 border border-gray-800" @click="downloadFile(att)">
                    <Download class="w-4 h-4" />{{ att.split('/').pop() }}
                  </button>
                </template>
              </div>
            </div>
          </div>
          <!-- Public replies -->
          <template v-for="reply in publicReplies" :key="reply.id">
            <div class="flex gap-4" :class="reply.is_staff ? 'flex-row-reverse' : ''">
              <div class="w-10 h-10 rounded-full shrink-0 flex items-center justify-center text-sm font-bold"
                :class="reply.is_staff ? 'bg-emerald-600 text-white' : 'text-gray-900'"
                :style="reply.is_staff ? '' : 'background:linear-gradient(135deg,rgb(var(--brand-500)),rgb(var(--brand-700)))'">
                {{ reply.is_staff ? 'S' : (reply.user?.email?.[0]?.toUpperCase() || '?') }}
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-500 mb-2" :class="reply.is_staff ? 'text-right' : ''">
                  {{ reply.is_staff ? t('account.ticketStaffLabel') : (reply.user?.name || reply.user?.email) }} · {{ formatTime(reply.created_at) }}
                </p>
                <div class="text-sm text-gray-300 leading-relaxed whitespace-pre-wrap rounded-xl px-5 py-4 border"
                  :class="reply.is_staff ? 'bg-emerald-900/20 border-emerald-800/40 rounded-tr-none' : 'bg-gray-800/60 border-gray-800 rounded-tl-none'">
                  {{ reply.message }}
                </div>
                <div v-if="(reply.attachments || []).length" class="flex flex-wrap gap-3 mt-3" :class="reply.is_staff ? 'justify-end' : ''">
                  <template v-for="(att, i) in reply.attachments" :key="i">
                    <button v-if="isImage(att)" class="relative group rounded-lg overflow-hidden border border-gray-800 hover:border-gray-600 transition-colors" @click="openLightbox(att)">
                      <img v-if="blobCache[att]" :src="blobCache[att]" class="h-24 w-auto object-cover" />
                      <div v-else class="h-24 w-24 flex items-center justify-center bg-gray-800 text-gray-500 text-xs">...</div>
                      <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center"><Maximize2 class="w-5 h-5 text-white" /></div>
                    </button>
                    <button v-else class="inline-flex items-center gap-2 text-sm text-brand-500 hover:underline bg-gray-800/60 rounded-lg px-4 py-2.5 border border-gray-800" @click="downloadFile(att)">
                      <Download class="w-4 h-4" />{{ att.split('/').pop() }}
                    </button>
                  </template>
                </div>
              </div>
            </div>
          </template>
          <div v-if="publicReplies.length" class="flex justify-end pr-1">
            <TicketSeenIndicator :replies="publicReplies" :is-admin="admin" :admin-read-at="adminReadAt" :user-read-at="userReadAt" />
          </div>
          <p v-if="!publicReplies.length && detail" class="text-center text-sm text-gray-500 py-12">{{ t('account.ticketNoReplies') }}</p>
        </div>
        <!-- Reply input -->
        <div class="px-8 py-5 border-t border-gray-800 shrink-0">
          <FilePreview :files="files" @remove="removeFile" />
          <div class="flex gap-3 mt-3">
            <input v-model="message" class="input flex-1 text-sm h-11"
              :placeholder="admin ? t('adminTickets.replyAsStaff') : t('account.ticketReplyPlaceholder')"
              @keyup.enter="(message.trim() || files.length) && send()" />
            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" multiple class="hidden" @change="onFileSelect" />
            <button class="h-11 w-11 flex items-center justify-center rounded-lg border border-gray-700 text-gray-400 hover:text-gray-200 hover:border-gray-600 transition-colors"
              :title="t('adminTickets.addAttachment')" @click="fileInput?.click()">
              <Paperclip class="w-4.5 h-4.5" />
            </button>
            <button class="btn-primary text-sm px-5 h-11" :disabled="(!message.trim() && !files.length) || sending" @click="send">
              <Send class="w-4 h-4" />
            </button>
          </div>
          <p class="text-[11px] text-gray-600 mt-2">{{ t('adminTickets.maxFiles') }}</p>
        </div>
      </template>

      <!-- Internal Notes tab (admin only) -->
      <div v-else-if="activeTab === 'notes'" class="flex-1 overflow-hidden px-8 py-6 flex flex-col">
        <TicketInternalNotes :ticket-id="ticket.id" :tenant-id="tenantId" :replies="internalReplies" />
      </div>
    </div>

    <!-- Image lightbox -->
    <div v-if="lightboxSrc" class="fixed inset-0 z-60 flex items-center justify-center bg-black/80" @click="lightboxSrc = null">
      <button class="absolute top-4 right-4 text-gray-400 hover:text-white transition-colors" @click="lightboxSrc = null"><X class="w-6 h-6" /></button>
      <img :src="lightboxSrc" class="max-w-[90vw] max-h-[90vh] rounded-lg shadow-2xl" @click.stop />
    </div>
  </div>
</template>
