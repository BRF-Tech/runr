/**
 * Order fulfilment with durable workflows — RunR nodejs runtime (FaaS, handler "main.handler").
 *
 * Project settings: trigger "Workflow", optional queue stream "images".
 * The engine injects RUNR_WORKFLOW_SIGNING_KEY into the function on deploy.
 */
const { Workflows, NonRetriableError, RetryAfterError, StepError } = require('@runr/workflows')

const wf = new Workflows()

// ── Business logic (stand-ins for real services) ─────────────────────────────
function reserveInventory(order) {
  if (!order.items?.length) throw new NonRetriableError('order has no items')
  return { reservation: `res-${order.orderId}`, items: order.items.length }
}

async function chargeCard(order, attempt) {
  if (attempt === 0 && Math.random() < 0.5) throw new Error('payment gateway timeout')
  if ((order.total ?? 0) > 10000) throw new RetryAfterError('manual review queue is full', '15m')
  return { chargeId: `ch-${order.orderId}-${Date.now()}`, amount: order.total ?? 0 }
}

// ── Workflows ─────────────────────────────────────────────────────────────────
wf.workflow({
  name: 'process-order',
  description: 'Reserve, charge, wait for the shipment, notify',
  triggers: [{ event: 'order.created' }],
  retries: 4,
  timeout: '7d',
  concurrency: { limit: 10, key: 'event.data.customerId' },
  idempotency: 'event.data.orderId',
  cancelOn: [{ event: 'order.cancelled', match: 'data.orderId' }],
}, async ({ event, step, ctx }) => {
  const order = event.data

  const reservation = await step.run('reserve-inventory', () => reserveInventory(order))
  const charge = await step.run('charge-card', () => chargeCard(order, ctx.attempt))

  await step.sleep('fraud-check-window', '10m')

  const shipment = await step.waitForEvent('wait-for-shipment', { event: 'order.shipped', match: 'data.orderId', timeout: '3d' })
  if (!shipment) {
    await step.run('refund', () => ({ refunded: charge.chargeId }))
    await step.sendEvent('notify-refund', { name: 'order.refunded', data: { orderId: order.orderId } })
    return { status: 'refunded', reservation: reservation.reservation }
  }

  await step.sendEvent('notify-completed', {
    name: 'order.completed',
    data: { orderId: order.orderId, tracking: shipment.data.tracking },
  })
  return { status: 'completed', charge: charge.chargeId }
})

wf.workflow({ name: 'nightly-report', triggers: [{ cron: '0 2 * * *', timezone: 'Europe/Istanbul' }], retries: 2 },
  async ({ step }) => {
    const totals = await step.run('collect', () => ({ orders: Math.floor(Math.random() * 90) + 10 }))
    try {
      await step.invoke('render', { workflow: 'render-report', data: totals, timeout: '30m' })
    } catch (err) {
      if (err instanceof StepError) return { status: 'render failed', reason: err.errorMessage }
      throw err
    }
    return { status: 'sent', ...totals }
  })

wf.workflow({ name: 'render-report', retries: 1 }, async ({ event, step }) =>
  step.run('render', () => `${event.data.orders} orders`))

wf.workflow({ name: 'resize-image', triggers: [{ queue: 'images' }], concurrency: 5 }, async ({ event, step }) =>
  step.run('resize', () => ({ key: event.data.key, sizes: [64, 256, 1024] })))

// RunR nodejs runtime entry point
exports.handler = wf.handler()
exports.workflows = wf
