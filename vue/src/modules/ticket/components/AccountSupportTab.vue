<script setup>
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useTutorialStore } from '@/stores/tutorial'
import { createTutorial } from '@/composables/useTutorial'
import { accountSupportSteps } from '@/composables/tutorialSteps'
import { Plus, Ticket, Clock, CheckCircle, AlertCircle, Search, Building2, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { fetchTickets, fetchTicketStats, fetchAdminTickets, fetchAdminTicketStats, fetchTicket, fetchAdminTicket } from '../api/support'
import { useTicketListRealtime } from '../composables/useTicketRealtime'
import CreateTicketModal from './CreateTicketModal.vue'
import TicketDetailModal from './TicketDetailModal.vue'

const { t }  = useI18n()
const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()
const tutorialStore = useTutorialStore()
const createModalRef = ref(null)
const { start: startTutorial } = createTutorial(accountSupportSteps, {
  onComplete: () => {
    showCreate.value = true
    setTimeout(() => createModalRef.value?.startTutorial(), 400)
  },
})
watch(() => tutorialStore.restartCounter, () => { nextTick(() => startTutorial()) })

// Support staff view: platform admins (the API also lets tickets.manage holders answer tickets)
const isAdmin    = computed(() => auth.isAdmin)
const loading    = ref(true)
const tickets    = ref([])
const stats      = ref({ open: 0, in_progress: 0, resolved: 0, total: 0 })
const showCreate = ref(false)
const selected   = ref(null)
const search     = ref('')
const filter     = ref('')
const page       = ref(1)
const totalPages = ref(1)

const priorityClass = {
  low:      'bg-gray-700 text-gray-300',
  medium:   'bg-yellow-900/60 text-yellow-300',
  high:     'bg-orange-900/60 text-orange-300',
  critical: 'bg-red-900/60 text-red-300',
}
const statusClass = {
  open:        'bg-blue-900/60 text-blue-300',
  in_progress: 'bg-yellow-900/60 text-yellow-300',
  resolved:    'bg-green-900/60 text-green-300',
  closed:      'bg-gray-700 text-gray-300',
}

function formatDate(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

async function load() {
  loading.value = true
  const params = { page: page.value }
  if (filter.value) params.status = filter.value
  if (search.value.trim()) params.search = search.value.trim()
  const [ticketRes, statsRes] = isAdmin.value
    ? await Promise.all([fetchAdminTickets(params), fetchAdminTicketStats()])
    : await Promise.all([fetchTickets(params), fetchTicketStats()])
  tickets.value = ticketRes.data.data || []
  totalPages.value = ticketRes.data.last_page || 1
  stats.value = statsRes.data
  loading.value = false
}

async function silentLoad() {
  const params = { page: page.value }
  if (filter.value) params.status = filter.value
  if (search.value.trim()) params.search = search.value.trim()
  const [ticketRes, statsRes] = isAdmin.value
    ? await Promise.all([fetchAdminTickets(params), fetchAdminTicketStats()])
    : await Promise.all([fetchTickets(params), fetchTicketStats()])
  tickets.value = ticketRes.data.data || []
  totalPages.value = ticketRes.data.last_page || 1
  stats.value = statsRes.data
}

function onFilter(status) { filter.value = filter.value === status ? '' : status; page.value = 1; load() }
function onSearch() { page.value = 1; load() }
function goPage(p) { page.value = p; load() }
function onCreated() { showCreate.value = false; load() }
function onUpdated() { selected.value = null; load() }

const { subscribe: subscribeToTickets } = useTicketListRealtime(
  () => silentLoad(),
  (ticketId, status) => {
    const tk = tickets.value.find(t => t.id === ticketId)
    if (tk) tk.status = status
  },
)

async function openTicketById(id, tenantId) {
  if (!id) return
  router.replace({ query: { tab: 'support-tickets' } })
  try {
    const { data } = isAdmin.value ? await fetchAdminTicket(id, tenantId) : await fetchTicket(id)
    if (tenantId && !data.tenant_id) data.tenant_id = tenantId
    selected.value = data
  } catch { /* ticket not found or no access */ }
}

watch(() => route.query.ticketId, (id) => openTicketById(id, route.query.tenantId))

onMounted(async () => { await load(); subscribeToTickets(); if (route.query.ticketId) openTicketById(route.query.ticketId, route.query.tenantId) })
</script>

<template>
  <div>
    <!-- Stats ribbon -->
    <div data-tour="support-stats" class="grid gap-4 mb-6" :class="isAdmin ? 'grid-cols-4' : 'grid-cols-3'">
      <button v-if="isAdmin"
        class="rounded-xl border bg-gray-900/80 p-4 relative overflow-hidden transition-colors text-left"
        :class="!filter ? 'border-brand-500' : 'border-gray-800'"
        @click="filter = ''; page = 1; load()">
        <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
            <AlertCircle class="w-5 h-5" style="color:rgb(var(--brand-500))" />
          </div>
          <div>
            <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('adminTickets.totalTickets') }}</p>
            <p class="text-xl font-bold text-gray-100 font-mono leading-tight">{{ stats.total ?? 0 }}</p>
          </div>
        </div>
      </button>
      <button v-for="(stat, key) in { open: 'statOpen', in_progress: 'statInProgress', resolved: 'statResolved' }" :key="key"
        class="rounded-xl border bg-gray-900/80 p-4 relative overflow-hidden transition-colors text-left"
        :class="filter === key ? 'border-brand-500' : 'border-gray-800'"
        @click="onFilter(key)">
        <div class="absolute inset-x-0 top-0 h-px" style="background:linear-gradient(90deg,rgb(var(--brand-500)),rgb(var(--brand-700)))" />
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-lg flex items-center justify-center shrink-0" style="background:rgb(var(--brand-500)/0.1)">
            <Ticket v-if="key === 'open'" class="w-5 h-5" style="color:rgb(var(--brand-500))" />
            <Clock v-else-if="key === 'in_progress'" class="w-5 h-5" style="color:rgb(var(--brand-500))" />
            <CheckCircle v-else class="w-5 h-5" style="color:rgb(var(--brand-500))" />
          </div>
          <div>
            <p class="text-[11px] text-gray-500 font-medium uppercase tracking-wider">{{ t('account.' + stat) }}</p>
            <p class="text-xl font-bold text-gray-100 font-mono leading-tight">{{ stats[key] ?? 0 }}</p>
          </div>
        </div>
      </button>
    </div>

    <!-- Ticket table card -->
    <div class="card p-0">
      <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-800">
        <h3 class="text-sm font-semibold text-gray-100 shrink-0">{{ t('account.ticketTitle') }}</h3>
        <div class="flex items-center gap-3 flex-1 justify-end">
          <div data-tour="support-search" class="relative w-64">
            <Search class="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-500" />
            <input v-model="search" class="input pl-9 text-xs h-8" :placeholder="t('adminTickets.searchPlaceholder')"
              @keyup.enter="onSearch()" />
          </div>
          <button v-if="!isAdmin" data-tour="support-new-ticket" class="btn-primary text-xs gap-1.5 h-8" @click="showCreate = true">
            <Plus class="w-3.5 h-3.5" />{{ t('account.newTicket') }}
          </button>
        </div>
      </div>

      <div v-if="loading" class="py-12 text-center text-gray-400 text-sm">{{ t('common.loading') }}</div>
      <div v-else-if="!tickets.length" class="py-12 text-center text-gray-500 text-sm">
        {{ isAdmin ? t('adminTickets.noTickets') : t('account.noTickets') }}
      </div>

      <div v-else data-tour="support-ticket-list" class="overflow-x-auto">
        <table class="w-full text-left">
          <thead>
            <tr class="border-b border-gray-800 text-xs text-gray-500 font-medium uppercase tracking-wider">
              <th class="px-5 py-3 w-14">#</th>
              <th class="px-4 py-3">{{ t('account.ticketSubject') }}</th>
              <th class="px-4 py-3 w-28">{{ t('account.ticketStatus') }}</th>
              <th class="px-4 py-3 w-24">{{ t('account.ticketPriority') }}</th>
              <th class="px-4 py-3 w-36">{{ t('account.ticketCategory') }}</th>
              <th v-if="isAdmin" class="px-4 py-3 w-32">{{ t('account.colTenant') }}</th>
              <th class="px-4 py-3 w-36">{{ t('account.colUser') }}</th>
              <th class="px-4 py-3 w-40 text-right">{{ t('account.ticketCreated') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="tk in tickets" :key="tk.id"
              class="border-b border-gray-800/50 hover:bg-gray-800/40 transition-colors cursor-pointer"
              @click="selected = tk">
              <td class="px-5 py-4 text-xs text-gray-500 font-mono">{{ tk.id }}</td>
              <td class="px-4 py-4"><p class="text-sm text-gray-100 font-medium truncate max-w-sm">{{ tk.subject }}</p></td>
              <td class="px-4 py-4">
                <span class="text-xs font-semibold px-2 py-1 rounded-sm whitespace-nowrap" :class="statusClass[tk.status]">
                  {{ t('account.st_' + tk.status) }}
                </span>
              </td>
              <td class="px-4 py-4">
                <span class="text-xs font-semibold px-2 py-1 rounded-sm whitespace-nowrap" :class="priorityClass[tk.priority]">
                  {{ t('account.pri_' + tk.priority) }}
                </span>
              </td>
              <td class="px-4 py-4">
                <span class="text-xs font-semibold px-2 py-1 rounded-sm bg-gray-800 text-gray-400 whitespace-nowrap">
                  {{ t('account.cat_' + tk.category) }}
                </span>
              </td>
              <td v-if="isAdmin" class="px-4 py-4">
                <span v-if="tk.tenant_name" class="inline-flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-full bg-purple-900/30 text-purple-300 border border-purple-800/30 whitespace-nowrap">
                  <Building2 class="w-3 h-3" />{{ tk.tenant_name }}
                </span>
              </td>
              <td class="px-4 py-4 text-sm text-gray-400 truncate max-w-40">{{ tk.user?.name || tk.user?.email || '—' }}</td>
              <td class="px-4 py-4 text-xs text-gray-500 text-right whitespace-nowrap">{{ formatDate(tk.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div v-if="totalPages > 1" class="flex items-center justify-between px-5 py-3 border-t border-gray-800">
        <span class="text-[10px] text-gray-500">{{ t('account.pageInfo', { page: page, total: totalPages }) }}</span>
        <div class="flex gap-1">
          <button class="h-7 w-7 flex items-center justify-center rounded-sm border border-gray-700 text-gray-400 hover:text-gray-200 hover:border-gray-600 transition-colors disabled:opacity-30 disabled:pointer-events-none"
            :disabled="page <= 1" @click="goPage(page - 1)"><ChevronLeft class="w-3.5 h-3.5" /></button>
          <button class="h-7 w-7 flex items-center justify-center rounded-sm border border-gray-700 text-gray-400 hover:text-gray-200 hover:border-gray-600 transition-colors disabled:opacity-30 disabled:pointer-events-none"
            :disabled="page >= totalPages" @click="goPage(page + 1)"><ChevronRight class="w-3.5 h-3.5" /></button>
        </div>
      </div>
    </div>

    <CreateTicketModal v-if="showCreate" ref="createModalRef" @close="showCreate = false" @created="onCreated" />
    <TicketDetailModal v-if="selected" :ticket="selected" :admin="isAdmin" @close="selected = null" @updated="onUpdated" />
  </div>
</template>
