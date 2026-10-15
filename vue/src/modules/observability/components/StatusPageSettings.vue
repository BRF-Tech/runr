<script setup>
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { ExternalLink, Globe } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'
import { observabilityApi } from '../api/observability'

/** Settings → Status page: address, visibility, texts and which uptime checks it shows. */
const { t } = useI18n()
const toast = useToast()

const page = ref(null)
const components = ref([])
const preview = ref(null)
const loading = ref(true)
const saving = ref(false)
const errors = ref({})

const publicUrl = computed(() => page.value ? `${window.location.origin}/status/${page.value.slug}` : '')

async function load() {
  loading.value = true
  try {
    const { data } = await observabilityApi.statusPage()
    apply(data.data)
  } catch (e) {
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    loading.value = false
  }
}

function apply(d) {
  page.value = { ...d.page }
  components.value = d.components.map((c) => ({ ...c }))
  preview.value = d.preview
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    const { data } = await observabilityApi.saveStatusPage({
      slug: page.value.slug,
      title: page.value.title,
      description: page.value.description || null,
      logo_url: page.value.logo_url || null,
      custom_domain: page.value.custom_domain || null,
      is_public: !!page.value.is_public,
      show_incident_history: !!page.value.show_incident_history,
      history_days: Number(page.value.history_days || 90),
      components: components.value.map((c, i) => ({
        id: c.id, show_on_status_page: !!c.show_on_status_page, status_page_name: c.status_page_name || null, status_page_order: c.status_page_order ?? i,
      })),
    })
    apply(data.data)
    toast.success(t('observability.status.saved'))
  } catch (e) {
    errors.value = e.response?.data?.errors ?? {}
    toast.error(e.response?.data?.message ?? t('observability.common.error'))
  } finally {
    saving.value = false
  }
}

const err = (k) => (errors.value?.[k] || [])[0]

onMounted(load)
</script>

<template>
  <div class="space-y-5" data-test="status-page-settings">
    <div>
      <div class="flex items-center gap-2">
        <Globe class="w-4 h-4 text-sky-400" />
        <span class="text-[15px] font-semibold text-gray-100">{{ t('observability.status.title') }}</span>
      </div>
      <p class="text-xs text-gray-400 mt-1.5">{{ t('observability.status.intro') }}</p>
    </div>

    <div v-if="loading" class="flex justify-center py-10"><div class="animate-spin h-6 w-6 border-2 border-sky-500 border-t-transparent rounded-full" /></div>

    <form v-else-if="page" class="space-y-5" @submit.prevent="save">
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="label">{{ t('observability.status.slug') }}</label>
          <input v-model="page.slug" class="input font-mono" required data-test="status-slug">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.status.slugHint') }}</p>
          <p v-if="err('slug')" class="text-[11px] text-red-400 mt-1" data-test="status-slug-error">{{ err('slug') }}</p>
        </div>
        <div>
          <label class="label">{{ t('observability.status.pageTitle') }}</label>
          <input v-model="page.title" class="input" required data-test="status-title">
        </div>
        <div class="md:col-span-2">
          <label class="label">{{ t('observability.status.description') }}</label>
          <textarea v-model="page.description" class="input" rows="2" />
        </div>
        <div>
          <label class="label">{{ t('observability.status.logo') }}</label>
          <input v-model="page.logo_url" class="input" placeholder="https://example.com/logo.svg">
        </div>
        <div>
          <label class="label">{{ t('observability.status.customDomain') }}</label>
          <input v-model="page.custom_domain" class="input font-mono" placeholder="status.example.com" data-test="status-domain">
          <p class="text-[11px] text-gray-500 mt-1">{{ t('observability.status.customDomainHint') }}</p>
          <p v-if="err('custom_domain')" class="text-[11px] text-red-400 mt-1">{{ err('custom_domain') }}</p>
        </div>
        <label class="flex items-center gap-2 text-sm text-gray-200"><input v-model="page.is_public" type="checkbox" data-test="status-public"> {{ t('observability.status.public') }}</label>
        <label class="flex items-center gap-2 text-sm text-gray-200"><input v-model="page.show_incident_history" type="checkbox"> {{ t('observability.status.history') }}</label>
        <div>
          <label class="label">{{ t('observability.status.historyDays') }}</label>
          <input v-model="page.history_days" type="number" min="7" max="90" class="input !w-28">
        </div>
      </div>

      <div v-if="page.exists" class="flex items-center gap-2 text-xs">
        <a :href="publicUrl" target="_blank" rel="noopener" class="text-sky-400 hover:text-sky-300 inline-flex items-center gap-1" data-test="status-open">
          <ExternalLink class="w-3 h-3" />{{ publicUrl }}
        </a>
        <span v-if="!page.is_public" class="text-gray-500">({{ t('observability.status.notPublicYet') }})</span>
      </div>

      <section class="space-y-2">
        <p class="label">{{ t('observability.status.components') }}</p>
        <p class="text-[11px] text-gray-500">{{ t('observability.status.componentsHint') }}</p>
        <p v-if="!components.length" class="text-xs text-gray-500">{{ t('observability.status.noChecks') }}</p>
        <div v-for="(c, i) in components" :key="c.id" class="flex flex-wrap items-center gap-3 bg-gray-900 border border-gray-800 rounded-md px-3 py-2" :data-component="c.id">
          <input v-model="c.show_on_status_page" type="checkbox" :data-test="`component-${c.id}`">
          <span class="text-sm text-gray-100">{{ c.name }}</span>
          <span class="text-[11px] text-gray-500">{{ c.project_name }}</span>
          <input v-if="c.show_on_status_page" v-model="c.status_page_name" class="input !h-8 !w-56 text-xs" :placeholder="t('observability.status.displayName')">
          <input v-if="c.show_on_status_page" v-model.number="c.status_page_order" type="number" class="input !h-8 !w-20 text-xs" :placeholder="String(i)">
        </div>
      </section>

      <button type="submit" class="btn-primary text-xs px-3 py-1.5" :disabled="saving" data-test="status-save">{{ t('observability.common.save') }}</button>

      <section v-if="preview" class="space-y-2" data-test="status-preview">
        <p class="label">{{ t('observability.status.preview') }}</p>
        <p class="text-xs text-gray-300">{{ t('observability.statusPublic.overall.' + preview.status) }} · {{ t('observability.status.previewCounts', { components: preview.components.length, incidents: preview.incidents.length }) }}</p>
      </section>
    </form>
  </div>
</template>
