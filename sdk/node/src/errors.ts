/** Throw inside step.run (or the workflow) to fail without further retries. */
export class NonRetriableError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'NonRetriableError'
  }
}

/**
 * Throw inside step.run to retry after `retryAfter` instead of the backoff: a duration
 * ("30s", "5m"), a number of seconds or a Date.
 */
export class RetryAfterError extends Error {
  constructor(message: string, public readonly retryAfter: string | number | Date) {
    super(message)
    this.name = 'RetryAfterError'
  }
}

/**
 * A step failed for good (retries exhausted, non-retriable, failed invoke). Thrown into the
 * workflow on the invocation after the failure; catch it to compensate. Uncaught, it fails
 * the run.
 */
export class StepError extends Error {
  constructor(
    public readonly stepId: string,
    public readonly errorName: string,
    public readonly errorMessage: string,
  ) {
    super(`step ${stepId} failed: ${errorMessage}`.trim())
    this.name = 'StepError'
  }
}

/** The request was not signed by the RunR engine. */
export class SignatureError extends Error {
  constructor(message = 'invalid signature') {
    super(message)
    this.name = 'SignatureError'
  }
}

export interface ErrorPayload {
  name: string
  message: string
  stack?: string
}

export function errorPayload(err: unknown): ErrorPayload {
  if (err instanceof Error) {
    return { name: err.name || 'Error', message: err.message, stack: err.stack?.slice(-16000) }
  }
  return { name: 'Error', message: typeof err === 'string' ? err : JSON.stringify(err) ?? String(err) }
}
