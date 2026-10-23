<script setup>
// Settings of a platform_event trigger: which RunR events (or all of them), optionally only
// those about some projects of the organization, and how long the stored events are kept.
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Search } from 'lucide-vue-next'
import { projectsApi } from '@/api/projects'
import { PLATFORM_EVENTS, eventKey } from '../utils/triggers'

const props = defineProps({
  form:   { type: Object, required: true },     // editor state (utils emptyForm), mutated
  events: { type: Array, default: () => PLATFORM_EVENTS },
  errors: { type: Object, default: () => ({}) },
})

const { t, te } = useI18n()

const projects = ref([])
const projectsError = ref(false)
const search = ref('')

const all = computed(() => props.form.events.includes('*'))
const eventLabel = (e) => (te(`triggers.platform.eventNames.${eventKey(e)}`) ? t(`triggers.platform.eventNames.${eventKey(e)}`) : e)

const shownProjects = computed(() => {
  const q = search.value.trim().toLowerCase()
  const list = q ? projects.value.filter(p => p.name.toLowerCase().includes(q)) : projects.value
  // the chosen ones stay listed while searching
  return [...list, ...projects.value.filter(p => props.form.projects.includes(p.id) && !list.includes(p))]
})

function toggleAll(on) {
  props.form.events = on ? ['*'] : []
}

function toggleEvent(e, on) {
  const rest = props.form.events.filter(x => x !== e && x !== '*')
  props.form.events = on ? [...rest, e] : rest
}

function toggleProject(id, on) {
  const rest = props.form.projects.filter(x => x !== id)
  props.form.projects = on ? [...rest, id] : rest
}

onMounted(async () => {
  try {
    const { data } = await projectsApi.list()
    projects.value = (data?.data ?? data ?? []).map(p => ({ id: p.id, name: p.name }))
  } catch {
    projectsError.value = true
  }
})
</script>

<template>
  <div class="space-y-4" data-test="platform-event-fields">
    <div>
      <label class="label">{{ t('triggers.platform.events') }}</label>
      <label class="flex items-center gap-2 text-sm text-gray-200 mb-2">
        <input type="checkbox" :checked="all" data-field="events.all" @change="toggleAll($event.target.checked)" />
        {{ t('triggers.platform.allEvents') }} <code class="text-[11px] text-gray-500">*</code>
      </label>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 pl-1">
        <label v-for="e in events" :key="e" class="flex items-center gap-2 text-xs" :class="all ? 'text-gray-500' : 'text-gray-300'">
          <input type="checkbox" :checked="all || form.events.includes(e)" :disabled="all" :data-event="e" @change="toggleEvent(e, $event.target.checked)" />
          <span>{{ eventLabel(e) }}</span>
          <code class="text-[10px] text-gray-500">{{ e }}</code>
        </label>
      </div>
      <p v-if="errors['config.events']" class="text-[11px] text-red-400 mt-1">{{ errors['config.events'] }}</p>
    </div>

    <div>
      <label class="label">{{ t('triggers.platform.projects') }}</label>
      <p class="text-[11px] text-gray-400 mb-2">{{ t('triggers.platform.projectsHint') }}</p>
      <p v-if="projectsError" class="text-[11px] text-red-400">{{ t('triggers.platform.projectsLoadFailed') }}</p>
      <template v-else>
        <div class="relative mb-2">
          <Search class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-1/2 -translate-y-1/2" />
          <input v-model="search" type="search" class="input py-1.5! pl-8! text-xs" :placeholder="t('triggers.platform.projectsSearch')" data-field="projects.search" />
        </div>
        <div class="max-h-40 overflow-y-auto rounded-md border border-gray-800 divide-y divide-gray-800/60">
          <label v-for="p in shownProjects" :key="p.id" class="flex items-center gap-2 px-2.5 py-1.5 text-xs text-gray-300 hover:bg-gray-800/40">
            <input type="checkbox" :checked="form.projects.includes(p.id)" :data-project="p.id" @change="toggleProject(p.id, $event.target.checked)" />
            <span class="truncate">{{ p.name }}</span>
          </label>
        </div>
      </template>
      <p v-if="errors['config.projects']" class="text-[11px] text-red-400 mt-1">{{ errors['config.projects'] }}</p>
    </div>

    <div class="max-w-xs">
      <label class="label">{{ t('triggers.fields.retention') }}</label>
      <input v-model="form.retention_days" type="number" min="1" max="30" class="input font-mono text-xs" placeholder="7" data-field="retention_days" />
      <p v-if="errors['config.retention_days']" class="text-[11px] text-red-400 mt-1">{{ errors['config.retention_days'] }}</p>
      <p v-else class="text-[11px] text-gray-400 mt-1">{{ t('triggers.fields.retentionHint') }}</p>
    </div>
  </div>
</template>
