import { defineAsyncComponent, markRaw } from 'vue'
import { LockKeyhole } from 'lucide-vue-next'
import { usePermissionsStore } from '@/stores/permissions'

// Secret stores (docs/secret-stores.md): Administration → Secret stores, the organization's Vault /
// OpenBao, AWS, GCP and Azure stores with the trust they need. (The region's External Secrets
// Operator is a core tab of the region detail: RegionDetailModal, "secrets".) Core plugin —
// loaded by modules/index.js with every build; messages: locales/{en,tr}/security.js in the core
// catalog.
export default function (registry) {
  registry.settingsTabs.push({
    key: 'secret-stores',
    labelKey: 'secretStores.tab',
    group: 'admin',
    section: 'access',
    descKey: 'secretStores.navDesc',
    icon: markRaw(LockKeyhole),
    component: markRaw(defineAsyncComponent(() => import('@/components/settings/SecretStoresManager.vue'))),
    visibleIf: () => usePermissionsStore().can('secret_stores.view'),
    order: 35,
  })
}
