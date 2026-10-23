import { ref } from 'vue'
import { defineStore } from 'pinia'
import { environmentsApi } from '@/api/environments'

/**
 * Promotions waiting for the signed-in user's approval (docs/environments.md#approvals): the count
 * on the header's Approvals link. Quiet on failure — an API without environments shows none.
 */
export const useApprovalsStore = defineStore('approvals', () => {
  const pending = ref(0)

  async function fetchCount() {
    try {
      const { data } = await environmentsApi.inbox({ status: 'pending_approval', limit: 100 })
      pending.value = (data.data ?? []).filter(p => p.can_approve).length
    } catch { /* silent */ }
  }

  return { pending, fetchCount }
})
