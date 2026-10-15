import { describe, expect, it } from 'vitest'
import { NonRetriableError, RetryAfterError, StepError, Workflows } from '../src'
import { FakeEngine, KEY } from './fakeEngine'

const make = () => new Workflows({ signingKey: KEY })

describe('deterministic replay', () => {
  it('runs each step once and memoizes results', async () => {
    const calls = { a: 0, b: 0 }
    const wf = make().workflow<{ x: number }>('sum', async ({ event, step }) => {
      const a = await step.run('a', () => { calls.a++; return event.data.x * 2 })
      const b = await step.run('b', async () => { calls.b++; return a + 1 })
      return { a, b }
    })

    const engine = new FakeEngine(wf.handler())
    const result = await engine.run('sum', { x: 5 })

    expect(result.status).toBe('completed')
    expect(result.output).toEqual({ a: 10, b: 11 })
    expect(calls).toEqual({ a: 1, b: 1 })
    expect(engine.ops.map((o) => o.op)).toEqual(['step_completed', 'step_completed', 'run_completed'])
    expect(engine.ops[0]).toMatchObject({ id: 'a', name: 'a', output: 10 })
    expect(typeof engine.ops[0].duration_ms).toBe('number')
  })

  it('suffixes repeated ids', async () => {
    const wf = make().workflow('loop', async ({ step }) => {
      let sum = 0
      for (const i of [1, 2, 3]) sum += await step.run('add', () => i)
      return sum
    })
    const result = await new FakeEngine(wf.handler()).run('loop')
    expect(result.output).toBe(6)
    expect(Object.keys(result.steps)).toEqual(['add', 'add:1', 'add:2'])
  })

  it('runs parallel steps one per invocation, in call order', async () => {
    const order: string[] = []
    const wf = make().workflow('parallel', async ({ step }) => {
      const [x, y] = await Promise.all([
        step.run('x', async () => { order.push('x'); return 1 }),
        step.run('y', async () => { order.push('y'); return 2 }),
      ])
      return x + y
    })
    const engine = new FakeEngine(wf.handler())
    const result = await engine.run('parallel')
    expect(result.output).toBe(3)
    expect(order).toEqual(['x', 'y'])
    expect(engine.invocations).toBe(3)
  })

  it('retries failures with attempt numbers', async () => {
    const seen: number[] = []
    const wf = make().workflow('flaky', async ({ step, ctx }) =>
      step.run('call', () => {
        seen.push(ctx.attempt)
        if (ctx.attempt < 2) throw new Error('timeout')
        return 'ok'
      }))
    const engine = new FakeEngine(wf.handler())
    expect((await engine.run('flaky')).output).toBe('ok')
    expect(seen).toEqual([0, 1, 2])
    const failed = engine.ops.filter((o) => o.op === 'step_failed')
    expect(failed).toHaveLength(2)
    expect(failed[0].error).toMatchObject({ name: 'Error', message: 'timeout' })
    expect(failed[0].retriable).toBeUndefined()
  })

  it('reports non-retriable errors and retry_after', async () => {
    const wf = make()
      .workflow('fatal', async ({ step }) => step.run('v', () => { throw new NonRetriableError('bad card') }))
      .workflow('later', async ({ step, ctx }) => step.run('c', () => {
        if (ctx.attempt === 0) throw new RetryAfterError('429', '30s')
        if (ctx.attempt === 1) throw new RetryAfterError('429', new Date('2030-01-01T00:00:00Z'))
        if (ctx.attempt === 2) throw new RetryAfterError('429', 12)
        return 1
      }))

    const fatal = new FakeEngine(wf.handler())
    expect((await fatal.run('fatal')).status).toBe('failed')
    expect(fatal.ops[0]).toMatchObject({ op: 'step_failed', retriable: false })
    expect(fatal.ops[1]).toMatchObject({ op: 'run_failed', retriable: false, error: { name: 'StepError' } })

    const later = new FakeEngine(wf.handler(), 5)
    expect((await later.run('later')).output).toBe(1)
    expect(later.ops.slice(0, 3).map((o) => o.retry_after)).toEqual(['30s', '2030-01-01T00:00:00.000Z', '12000ms'])
  })

  it('lets the workflow handle a failed step', async () => {
    const wf = make().workflow('compensate', async ({ step }) => {
      try {
        await step.run('reserve', () => { throw new NonRetriableError('out of stock') })
      } catch (err) {
        expect(err).toBeInstanceOf(StepError)
        expect((err as StepError).errorMessage).toBe('out of stock')
        return step.run('refund', () => 'refunded')
      }
      return 'reserved'
    })
    expect((await new FakeEngine(wf.handler()).run('compensate')).output).toBe('refunded')
  })

  it('retries errors outside steps as retriable run failures', async () => {
    let calls = 0
    const wf = make().workflow('crash', async () => { calls++; throw new TypeError('bug') })
    const engine = new FakeEngine(wf.handler(), 3)
    expect((await engine.run('crash')).status).toBe('failed')
    expect(calls).toBe(3)
    expect(engine.ops.every((o) => o.op === 'run_failed' && o.retriable === true)).toBe(true)
  })

  it('emits sleep, wait, invoke and send ops', async () => {
    const wf = make().workflow('everything', async ({ step }) => {
      await step.sleep('nap', '1h')
      await step.sleep('secs', 5)
      await step.sleepUntil('until', new Date('2030-01-01T12:00:00Z'))
      const paid = await step.waitForEvent<{ amount: number }>('paid', { event: 'order.paid', match: 'data.order_id', if: 'async.data.ok', timeout: '7d' })
      const child = await step.invoke<number>('child', { workflow: 'double', data: { n: 2 }, timeout: '1h' })
      const sent = await step.sendEvent('notify', { name: 'order.done', data: { id: 1 } })
      const missing = await step.waitForEvent('never', { event: 'never.happens', timeout: 60 })
      return { paid: paid?.data.amount, child, sent, missing }
    })

    const engine = new FakeEngine(wf.handler())
    engine.events['order.paid'] = { id: 'e9', name: 'order.paid', data: { amount: 12 }, ts: 1 }
    engine.children.double = 4
    const result = await engine.run('everything')

    expect(result.output).toEqual({ paid: 12, child: 4, sent: { ids: ['e0'] }, missing: null })
    const byId = Object.fromEntries(engine.ops.filter((o) => o.id).map((o) => [o.id, o]))
    expect(byId.nap).toEqual({ op: 'sleep', id: 'nap', name: 'nap', duration: '1h' })
    expect(byId.secs.duration).toBe('5000ms')
    expect(byId.until.until).toBe('2030-01-01T12:00:00.000Z')
    expect(byId.paid).toEqual({ op: 'wait_for_event', id: 'paid', name: 'paid', event: 'order.paid', match: 'data.order_id', if: 'async.data.ok', timeout: '7d' })
    expect(byId.child).toEqual({ op: 'invoke', id: 'child', name: 'child', workflow: 'double', data: { n: 2 }, timeout: '1h' })
    expect(byId.notify.events).toEqual([{ name: 'order.done', data: { id: 1 } }])
    expect(byId.never.timeout).toBe('60000ms')
    expect(engine.sentEvents).toEqual([{ name: 'order.done', data: { id: 1 } }])
  })

  it('throws StepError for a failed invoke', async () => {
    const wf = make().workflow('parent', async ({ step }) => {
      try {
        await step.invoke('c', { workflow: 'child' })
        return 'ok'
      } catch (err) {
        return (err as StepError).errorName
      }
    })
    const engine = new FakeEngine(wf.handler())
    engine.children.child = new Error('boom')
    expect((await engine.run('parent')).output).toBe('InvokeFailed')
  })

  it('round-trips outputs through JSON', async () => {
    const wf = make().workflow('json', async ({ step }) => {
      const v = await step.run('date', () => ({ when: new Date('2030-01-01T00:00:00Z'), nothing: undefined }))
      return v
    })
    const engine = new FakeEngine(wf.handler())
    expect((await engine.run('json')).output).toEqual({ when: '2030-01-01T00:00:00.000Z' })
  })

  it('fails non-serializable outputs without retry', async () => {
    const wf = make().workflow('cyclic', async ({ step }) => {
      const a: any = {}
      a.self = a
      return step.run('bad', () => a)
    })
    const engine = new FakeEngine(wf.handler())
    expect((await engine.run('cyclic')).status).toBe('failed')
    expect(engine.ops[0]).toMatchObject({ op: 'step_failed', retriable: false })
  })

  it('rejects nested steps', async () => {
    const wf = make().workflow('nested', async ({ step }) => step.run('outer', () => step.run('inner', () => 1)))
    const engine = new FakeEngine(wf.handler(), 1)
    expect((await engine.run('nested')).status).toBe('failed')
    expect(engine.ops[0].error.message).toContain('nested')
  })

  it('validates registration', () => {
    const wf = make()
    expect(() => wf.workflow('bad name', async () => 1)).toThrow()
    wf.workflow('ok', async () => 1)
    expect(() => wf.workflow('ok', async () => 2)).toThrow()
    expect(wf.names).toEqual(['ok'])
  })

  it('answers unknown workflows with a non-retriable failure', async () => {
    const op = await make().execute({ type: 'execute', workflow: 'nope', run: { id: 'r' }, event: { id: 'e', name: 'x', data: {}, ts: 0 } })
    expect(op).toMatchObject({ op: 'run_failed', retriable: false, error: { name: 'WorkflowNotFound' } })
  })
})
