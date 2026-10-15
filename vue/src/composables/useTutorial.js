import { driver } from 'driver.js'
import 'driver.js/dist/driver.css'
import { onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTutorialStore } from '@/stores/tutorial'
import {
  projectsSteps, createSteps, settingsSteps,
  detailVersionsSteps, detailDeploymentsSteps, detailMetricsSteps,
  editSteps, tokensSteps, usersSteps,
  accountProfileSteps, accountSupportSteps,
} from './tutorialSteps'

function filterSteps(steps) {
  return steps.filter((s) => !s.element || document.querySelector(s.element))
}

function buildDriver(steps, { t, tutorialStore, onComplete }) {
  const total = steps.length
  return driver({
    showProgress: false,
    animate: true,
    allowClose: true,
    overlayColor: 'rgba(0,0,0,0.75)',
    stagePadding: 8,
    stageRadius: 12,
    popoverClass: 'runr-tour-popover',
    nextBtnText: t('tutorial.next'),
    prevBtnText: t('tutorial.prev'),
    doneBtnText: t('tutorial.done'),
    progressText: t('tutorial.progress'),
    onPopoverRender: (popover, { state }) => {
      const idx = state.activeIndex
      const counter = popover.wrapper.querySelector('.driver-popover-progress-text') ||
                      document.createElement('span')
      counter.className = 'driver-popover-progress-text'
      counter.textContent = `${idx + 1} / ${total}`
      if (!popover.wrapper.querySelector('.driver-popover-progress-text')) {
        popover.footer.prepend(counter)
      }
    },
    onDestroyed: () => {
      tutorialStore.tutorialActive = false
      if (onComplete) onComplete()
    },
    steps,
  })
}

export function createTutorial(getSteps, { onComplete = null } = {}) {
  const { t, locale } = useI18n()
  const tutorialStore = useTutorialStore()
  let driverObj = null
  let savedIndex = 0

  function start(fromIndex = 0) {
    if (driverObj) driverObj.destroy()
    const steps = filterSteps(getSteps(t))
    if (!steps.length) return
    driverObj = buildDriver(steps, { t, tutorialStore, onComplete })
    tutorialStore.tutorialActive = true
    driverObj.drive(Math.min(fromIndex, steps.length - 1))
  }

  watch(locale, () => {
    if (!tutorialStore.tutorialActive || !driverObj) return
    savedIndex = driverObj.getActiveIndex() ?? 0
    driverObj.destroy()
    nextTick(() => start(savedIndex))
  })

  onUnmounted(() => {
    if (driverObj) {
      driverObj.destroy()
      driverObj = null
    }
  })

  return { start }
}

export function useProjectsTutorial() {
  const tutorialStore = useTutorialStore()
  return createTutorial(projectsSteps, {
    onComplete: () => tutorialStore.markCompleted(),
  })
}

export function useCreateTutorial() {
  return createTutorial(createSteps)
}

export function useSettingsTutorial() {
  return createTutorial(settingsSteps)
}

export function useDetailVersionsTutorial() {
  return createTutorial(detailVersionsSteps)
}

export function useDetailDeploymentsTutorial() {
  return createTutorial(detailDeploymentsSteps)
}

export function useDetailMetricsTutorial() {
  return createTutorial(detailMetricsSteps)
}

export function useEditTutorial() {
  return createTutorial(editSteps)
}

export function useTokensTutorial() {
  return createTutorial(tokensSteps)
}

export function useUsersTutorial() {
  return createTutorial(usersSteps)
}

export function useAccountProfileTutorial() {
  return createTutorial(accountProfileSteps)
}

export function useAccountSupportTutorial() {
  return createTutorial(accountSupportSteps)
}
