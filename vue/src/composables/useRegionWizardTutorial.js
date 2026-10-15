import { driver } from 'driver.js'
import 'driver.js/dist/driver.css'
import { onUnmounted, watch, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useTutorialStore } from '@/stores/tutorial'

/**
 * Wizard-aware region tutorial.
 * Advances wizardStep before driver.js tries to find the next element.
 */
export function useRegionWizardTutorial(wizardStepRef, { onComplete = null } = {}) {
  const { t, locale } = useI18n()
  const tutorialStore = useTutorialStore()
  let driverObj = null
  let savedIndex = 0

  function getSteps() {
    return [
      { element: '[data-tour="region-general"]',   popover: { title: t('tutorial.region.general'),   description: t('tutorial.region.generalDesc') } },
      { element: '[data-tour="region-workers"]',   popover: { title: t('tutorial.region.workers'),   description: t('tutorial.region.workersDesc') } },
      { element: '[data-tour="region-operator"]',  popover: { title: t('tutorial.region.operator'),  description: t('tutorial.region.operatorDesc') } },
      { element: '[data-tour="region-serving"]',   popover: { title: t('tutorial.region.serving'),   description: t('tutorial.region.servingDesc') } },
      { element: '[data-tour="region-redis"]',     popover: { title: t('tutorial.region.redis'),     description: t('tutorial.region.redisDesc') } },
      { element: '[data-tour="region-sandbox"]',   popover: { title: t('tutorial.region.sandbox'),   description: t('tutorial.region.sandboxDesc') } },
      { element: '[data-tour="region-migration"]', popover: { title: t('tutorial.region.migration'), description: t('tutorial.region.migrationDesc') } },
    ]
  }

  function start(fromIndex = 0) {
    if (driverObj) driverObj.destroy()
    const steps = getSteps()
    const total = steps.length

    // Inject onNextClick / onPrevClick to advance wizard before driver moves
    steps.forEach((step, idx) => {
      step.popover.onNextClick = () => {
        if (idx < total - 1) {
          wizardStepRef.value = idx + 2
          nextTick(() => driverObj.moveNext())
        } else {
          driverObj.destroy()
        }
      }
      if (idx > 0) {
        step.popover.onPrevClick = () => {
          wizardStepRef.value = idx
          nextTick(() => driverObj.movePrevious())
        }
      }
    })

    driverObj = driver({
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
        const counter = popover.wrapper.querySelector('.driver-popover-progress-text')
          || document.createElement('span')
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

    // Ensure wizard is on the correct step before driving
    wizardStepRef.value = fromIndex + 1
    tutorialStore.tutorialActive = true
    nextTick(() => driverObj.drive(Math.min(fromIndex, total - 1)))
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
