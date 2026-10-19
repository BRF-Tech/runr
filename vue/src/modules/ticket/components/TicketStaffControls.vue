<script setup>
import { ref, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { UserCheck, Tag, X } from 'lucide-vue-next'
import { fetchTicketStaff, assignTicket, updateTicketTags } from '../api/support'

// Support staff controls of a ticket: assignee (members who work the queue) and tags
const props = defineProps({
  ticketId:   { type: [Number, String], required: true },
  tenantId:   { type: String, default: null },
  assignedTo: { type: [Number, String], default: null },
  tags:       { type: Array, default: () => [] },
})
const emit = defineEmits(['assigned', 'tags'])
const { t } = useI18n()

const MAX_TAGS = 10
const MAX_TAG_LENGTH = 30

const staff    = ref([])
const assignee = ref(props.assignedTo ?? '')
const tagList  = ref([...(props.tags || [])])
const newTag   = ref('')
const saving   = ref(false)
const error    = ref(null)

watch(() => props.assignedTo, (v) => { assignee.value = v ?? '' })
watch(() => props.tags, (v) => { tagList.value = [...(v || [])] })

onMounted(async () => {
  try {
    const { data } = await fetchTicketStaff()
    staff.value = data.data || []
  } catch { staff.value = [] }
})

function fail(e) {
  error.value = e?.response?.data?.message || t('account.ticketStaffSaveFailed')
}

async function onAssign() {
  error.value = null
  saving.value = true
  const value = assignee.value === '' ? null : Number(assignee.value)
  try {
    const { data } = await assignTicket(props.ticketId, value, props.tenantId)
    emit('assigned', { assigned_to: data?.assigned_to ?? value, assignee: data?.assignee ?? null })
  } catch (e) {
    assignee.value = props.assignedTo ?? ''
    fail(e)
  } finally { saving.value = false }
}

async function saveTags(next) {
  error.value = null
  saving.value = true
  const previous = tagList.value
  tagList.value = next
  try {
    const { data } = await updateTicketTags(props.ticketId, next.length ? next : null, props.tenantId)
    tagList.value = data?.tags ?? next
    emit('tags', tagList.value)
  } catch (e) {
    tagList.value = previous
    fail(e)
  } finally { saving.value = false }
}

function addTag() {
  const tag = newTag.value.trim().slice(0, MAX_TAG_LENGTH)
  newTag.value = ''
  if (!tag || tagList.value.includes(tag) || tagList.value.length >= MAX_TAGS) return
  saveTags([...tagList.value, tag])
}

function removeTag(tag) {
  saveTags(tagList.value.filter(x => x !== tag))
}
</script>

<template>
  <div class="flex items-center gap-4 flex-wrap mt-3" data-test="ticket-staff-controls">
    <label class="flex items-center gap-2">
      <UserCheck class="w-3.5 h-3.5 text-gray-500" />
      <span class="text-xs text-gray-500 font-medium uppercase tracking-wider">{{ t('account.ticketAssignTo') }}</span>
      <select v-model="assignee" class="input h-8 text-xs py-0 w-48" data-test="ticket-assignee" :disabled="saving" @change="onAssign">
        <option value="">{{ t('account.ticketUnassigned') }}</option>
        <option v-for="u in staff" :key="u.id" :value="u.id">{{ u.name || u.email }}</option>
      </select>
    </label>
    <div class="flex items-center gap-2 flex-wrap">
      <Tag class="w-3.5 h-3.5 text-gray-500" />
      <span class="text-xs text-gray-500 font-medium uppercase tracking-wider">{{ t('account.ticketTags') }}</span>
      <span v-for="tag in tagList" :key="tag"
        class="inline-flex items-center gap-1 text-xs font-medium pl-2 pr-1 py-0.5 rounded-full bg-brand-500/10 text-brand-500 border border-brand-500/20"
        :data-test="`ticket-tag-${tag}`">
        {{ tag }}
        <button type="button" class="hover:text-gray-100" :aria-label="t('account.ticketRemoveTag', { tag })"
          :data-test="`ticket-tag-remove-${tag}`" :disabled="saving" @click="removeTag(tag)">
          <X class="w-3 h-3" />
        </button>
      </span>
      <input v-if="tagList.length < MAX_TAGS" v-model="newTag" class="input h-7 text-xs w-32" :maxlength="MAX_TAG_LENGTH"
        :placeholder="t('account.ticketAddTag')" data-test="ticket-tag-input" :disabled="saving" @keydown.enter.prevent="addTag" />
    </div>
    <p v-if="error" class="text-xs text-red-400 w-full" data-test="ticket-staff-error">{{ error }}</p>
  </div>
</template>
