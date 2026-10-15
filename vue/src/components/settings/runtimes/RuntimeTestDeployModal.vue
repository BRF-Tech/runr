<script setup>
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { Rocket } from 'lucide-vue-next'
import RuntimeIcon from '@/components/RuntimeIcon.vue'
import { runtimesApi } from '@/api/runtimes'
import { useSettingsStore } from '@/stores/settings'
import { isRegionReady, runtimeAvailableIn } from '@/composables/useProjectForm'
import { apiErrorMessage } from '@/composables/useRuntimesManager'

const props = defineProps({ runtime: { type: Object, required: true } })
const emit  = defineEmits(['close'])
const { t } = useI18n()
const router = useRouter()
const settingsStore = useSettingsStore()

onMounted(() => { if (!settingsStore.loaded) settingsStore.fetch() })

const regions = computed(() => (settingsStore.data.regions ?? [])
  .filter(r => isRegionReady(r) && runtimeAvailableIn(props.runtime, r.id)))

const versions = computed(() => (props.runtime.versions ?? [])
  .filter(v => !(v.eol_at && new Date(v.eol_at) <= new Date())))

const regionId = ref(null)
const version  = ref(versions.value.find(v => v.is_default)?.version ?? versions.value[0]?.version ?? null)
const running  = ref(false)
const error    = ref(null)

async function start() {
  error.value   = null
  running.value = true
  try {
    const { data } = await runtimesApi.testDeploy(props.runtime.id, {
      knative_region_id: regionId.value,
      version:           version.value || undefined,
    })
    const project = data.data ?? data
    emit('close')
    if (project?.id) router.push({ name: 'project-detail', params: { id: project.id } })
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    running.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-black/70 backdrop-blur-xs" @click="emit('close')" />
    <div class="relative w-full max-w-lg">
      <div class="card">
        <div class="flex items-center gap-3 mb-1">
          <RuntimeIcon :runtime="runtime.id" :size="22" />
          <h2 class="text-base font-semibold text-gray-100">{{ t('runtimes.testDeploy.title', { name: runtime.name }) }}</h2>
        </div>
        <p class="text-xs text-gray-400 mb-5">{{ t('runtimes.testDeploy.description') }}</p>

        <label class="label">{{ t('runtimes.testDeploy.region') }}</label>
        <div v-if="regions.length" class="flex flex-wrap gap-2 mb-4">
          <button
            v-for="r in regions" :key="r.id" type="button"
            class="px-3 py-1.5 rounded-md border text-xs font-mono transition-all"
            :class="regionId === r.id ? 'text-white border-transparent' : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'"
            :style="regionId === r.id ? 'background-color:rgb(var(--brand-600))' : ''"
            @click="regionId = r.id"
          >{{ r.name }}</button>
        </div>
        <p v-else class="text-xs text-amber-400 mb-4">{{ t('runtimes.testDeploy.noRegions') }}</p>

        <label class="label">{{ t('runtimes.testDeploy.version') }}</label>
        <div class="flex flex-wrap gap-2 mb-5">
          <button
            v-for="v in versions" :key="v.version" type="button"
            class="px-3 py-1.5 rounded-md border text-xs font-mono transition-all"
            :class="version === v.version ? 'text-white border-transparent' : 'bg-gray-800 border-gray-700 text-gray-400 hover:border-gray-500'"
            :style="version === v.version ? 'background-color:rgb(var(--brand-600))' : ''"
            @click="version = v.version"
          >{{ v.version }}<span v-if="v.is_default" class="ml-1 text-[10px] opacity-60">{{ t('create.fields.defaultBadge') }}</span></button>
        </div>

        <p v-if="error" class="text-sm text-red-400 mb-3">{{ error }}</p>

        <div class="flex justify-end gap-2 pt-4 border-t border-gray-800/60">
          <button class="btn-secondary" @click="emit('close')">{{ t('common.cancel') }}</button>
          <button class="btn-primary flex items-center gap-1.5" :disabled="!regionId || running" @click="start">
            <Rocket class="w-3.5 h-3.5" />{{ running ? t('runtimes.testDeploy.starting') : t('runtimes.testDeploy.start') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
