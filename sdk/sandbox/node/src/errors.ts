/** An error answered by the RunR API, or raised by the SDK itself. */
export class RunrSandboxError extends Error {
  /** HTTP status (null for errors without a response, e.g. an `error` event of a stream). */
  readonly status: number | null
  /** Machine-readable reason (the API's `reason`, e.g. `quota_concurrent`). */
  readonly reason: string | null
  /** The decoded response body, or its text. */
  readonly body: unknown

  constructor(status: number | null, message: string, reason: string | null = null, body: unknown = null) {
    super(message)
    this.name = new.target.name
    this.status = status
    this.reason = reason
    this.body = body
  }
}

/** 401 (no or invalid token) or 403 (the token lacks the ability). */
export class AuthError extends RunrSandboxError {}

/** 404: unknown sandbox, process or file (or another organization's). */
export class NotFoundError extends RunrSandboxError {}

/** 409: the sandbox is not in a state that allows the call (ended or paused). */
export class ConflictError extends RunrSandboxError {}

/** 422: invalid options, unavailable region / runtime / sandbox class. */
export class ValidationError extends RunrSandboxError {}

/** 422 with a `quota_*` reason: the organization's sandbox quota does not allow the call. */
export class QuotaError extends ValidationError {}

/** 413: a file above the upload / download limit. */
export class PayloadTooLargeError extends RunrSandboxError {}

/** 429: too many requests; `retryAfter` holds the seconds to wait when the API sent them. */
export class RateLimitError extends RunrSandboxError {
  readonly retryAfter: number | null

  constructor(status: number | null, message: string, reason: string | null = null, body: unknown = null, retryAfter: number | null = null) {
    super(status, message, reason, body)
    this.retryAfter = retryAfter
  }
}

/** The sandbox did not reach `running` within `startTimeoutMs`. */
export class SandboxStartTimeoutError extends RunrSandboxError {}

/** Map an error response to the matching error class. */
export function errorFromResponse(status: number, body: unknown, text = '', retryAfter: string | null = null): RunrSandboxError {
  let message = ''
  let reason: string | null = null
  if (body && typeof body === 'object' && !Array.isArray(body)) {
    const record = body as Record<string, unknown>
    message = typeof record.message === 'string' ? record.message : ''
    reason = typeof record.reason === 'string' && record.reason !== '' ? record.reason : null
  }
  if (!message) {
    message = text.trim() ? text.trim().slice(0, 500) : `HTTP ${status}`
  }
  const payload = body ?? text

  if (status === 401 || status === 403) return new AuthError(status, message, reason, payload)
  if (status === 404) return new NotFoundError(status, message, reason, payload)
  if (status === 409) return new ConflictError(status, message, reason, payload)
  if (status === 413) return new PayloadTooLargeError(status, message, reason ?? 'file_too_large', payload)
  if (status === 422) {
    return reason?.startsWith('quota_')
      ? new QuotaError(status, message, reason, payload)
      : new ValidationError(status, message, reason, payload)
  }
  if (status === 429) {
    const seconds = retryAfter !== null && retryAfter.trim() !== '' && Number.isFinite(Number(retryAfter)) ? Number(retryAfter) : null
    return new RateLimitError(status, message, reason, payload, seconds)
  }
  return new RunrSandboxError(status, message, reason, payload)
}
