import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import client from '@/api/client'

export const useTutorialStore = defineStore('tutorial', () => {
  const authStore = useAuthStore()

  const tutorialActive = ref(false)
  const restartCounter = ref(0)

  const shouldAutoStart = computed(() => authStore.user?.tutorial_completed_at === null)

  async function markCompleted() {
    try {
      await client.post('/tutorial/complete')
      authStore.markTutorialCompleted()
    } catch {
      // silent
    }
  }

  function requestRestart() {
    restartCounter.value++
  }

  return { tutorialActive, shouldAutoStart, restartCounter, markCompleted, requestRestart }
})
