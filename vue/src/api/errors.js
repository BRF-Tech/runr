import axios from 'axios'
import i18n from '@/i18n'

/**
 * Requests that got no answer from the server. The browser reports one failure for all of these:
 * the network is down, DNS fails, the browser rejected the server's TLS certificate, CORS blocked
 * the answer, the connection was dropped, or the request timed out. Without a word about it the
 * console showed "An error occurred." for each of them.
 */

/**
 * Whether a request was cancelled on purpose (an AbortController signal, an axios cancel, an
 * upload loop stopped between chunks): there is nothing to show.
 */
export function isCancelled(e) {
  return axios.isCancel(e) || e?.code === 'ERR_CANCELED' || e?.name === 'CanceledError' || e?.name === 'AbortError'
}

/**
 * Why a request got no answer: 'timeout', 'network', or null when it got one (any HTTP status),
 * was cancelled on purpose, or is not a request error at all.
 */
export function connectionFailure(e) {
  if (!e || typeof e !== 'object') return null
  if (e.connectionFailure) return e.connectionFailure
  if (!axios.isAxiosError(e) || e.response || isCancelled(e)) return null
  // axios reports a timeout as ETIMEDOUT, or as ECONNABORTED with a "timeout … exceeded" message
  // (transitional.clarifyTimeoutError off, its default); ECONNABORTED otherwise is a dropped connection
  if (e.code === 'ETIMEDOUT' || (e.code === 'ECONNABORTED' && /timeout/i.test(e.message ?? ''))) return 'timeout'
  return e.code === 'ERR_NETWORK' || e.code === 'ECONNABORTED' ? 'network' : null
}

/** The console's text for a request that got no answer, in the current language; else null. */
export function connectionFailureMessage(e) {
  const failure = connectionFailure(e)
  if (!failure) return null
  return i18n.global.t(failure === 'timeout' ? 'common.timeoutError' : 'common.networkError')
}

/**
 * Gives a request that got no answer the console's message where the error displays read the
 * API's own: error.response.data.message (`e.response?.data?.message ?? t('common.error')` and the
 * apiErrorMessage-style helpers). The API client's response interceptor calls this for every
 * failed request.
 *
 * The stand-in response carries that message and nothing else: no status, no field errors, no
 * code. Status checks (304/401/403/404/409/422, retries on a missing status or a 5xx) therefore
 * see exactly what they saw before, and an HTTP error keeps its real response untouched.
 * error.connectionFailure says 'network' or 'timeout'.
 */
export function describeConnectionFailure(error) {
  const failure = connectionFailure(error)
  if (!failure || error.response) return error
  error.connectionFailure = failure
  error.response = { data: { message: connectionFailureMessage(error) }, headers: {}, config: error.config }
  return error
}

/**
 * The text to show for a failed API call: null when it was cancelled on purpose, the connection
 * message when it got no answer, else the API's message or its first field error, else `fallback`.
 */
export function requestErrorMessage(e, fallback = null) {
  if (isCancelled(e)) return null
  const data = e?.response?.data
  return connectionFailureMessage(e)
    ?? data?.message
    ?? Object.values(data?.errors ?? {}).flat()[0]
    ?? fallback
}
