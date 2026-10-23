import { useI18n } from 'vue-i18n'
import { typeInfo } from '../utils/triggers'

/**
 * Labels of trigger types: the console's own for the core types, the API's (translated with
 * Accept-Language) for the others.
 */
export function useTriggerLabels(types = () => []) {
  const { t, te } = useI18n()

  /** fallback: a label the API sent with the trigger (presented `label`) */
  function typeLabel(type, fallback = null) {
    const key = `triggers.types.${type}`
    if (te(key)) return t(key)
    const info = typeInfo(types(), type)
    return info.label !== type ? info.label : (fallback || type)
  }

  function typeHint(type) {
    const key = `triggers.typeHints.${type}`
    return te(key) ? t(key) : (typeInfo(types(), type).description ?? '')
  }

  return { typeLabel, typeHint }
}
