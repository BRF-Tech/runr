import { describe, it, expect, vi } from 'vitest'

// Private channels (docs/security.md): every subscription is authorized through the API client.
const client = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('@/api/client', () => ({ default: client }))
vi.mock('laravel-echo', () => ({ default: vi.fn(function Echo(options) { this.options = options }) }))
vi.mock('pusher-js', () => ({ default: vi.fn() }))

import echo, { authorizeChannel } from '@/plugins/echo'

describe('Echo channel authorizer', () => {
  it('is the authorizer of the Echo instance', () => {
    expect(echo.options.authorizer).toBe(authorizeChannel)
  })

  it('posts the socket and channel to /broadcasting/auth and hands back the signature', async () => {
    client.post.mockResolvedValue({ data: { auth: 'key:signature' } })
    const callback = vi.fn()

    authorizeChannel({ name: 'private-tenant.t-1.platform' }).authorize('1234.5678', callback)
    await vi.waitFor(() => expect(callback).toHaveBeenCalled())

    expect(client.post).toHaveBeenCalledWith('/broadcasting/auth', { socket_id: '1234.5678', channel_name: 'private-tenant.t-1.platform' })
    expect(callback).toHaveBeenCalledWith(null, { auth: 'key:signature' })
  })

  it('reports a refused subscription', async () => {
    const refused = { response: { status: 403 } }
    client.post.mockRejectedValue(refused)
    const callback = vi.fn()

    authorizeChannel({ name: 'private-support-tickets' }).authorize('1.2', callback)
    await vi.waitFor(() => expect(callback).toHaveBeenCalled())

    expect(callback).toHaveBeenCalledWith(refused, null)
  })
})
