import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import client from '@/api/client'

window.Pusher = Pusher

// HTTPS or proxy mode → route WS through gateway /ws path
// HTTP (direct, dev) → connect directly to Reverb port
const isSecure = window.location.protocol === 'https:'
const wsProxy = isSecure || import.meta.env.VITE_WS_PROXY === 'true'

/**
 * Every channel is private (docs/security.md): each subscription is authorized by
 * POST /api/broadcasting/auth through the API client, which adds the current Bearer token and
 * the tenant header (X-Tenant-ID) of the active organization.
 */
export function authorizeChannel(channel) {
  return {
    authorize(socketId, callback) {
      client.post('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
        .then(({ data }) => callback(null, data))
        .catch((error) => callback(error, null))
    },
  }
}

const echo = new Echo({
  broadcaster: 'reverb',
  key: import.meta.env.VITE_REVERB_KEY || 'runr-local-key',
  wsHost: window.location.hostname,
  wsPort: wsProxy ? (window.location.port || (isSecure ? 443 : 80)) : (import.meta.env.VITE_REVERB_PORT || 8064),
  wssPort: window.location.port || 443,
  forceTLS: isSecure,
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
  wsPath: wsProxy ? '/ws' : '',
  authorizer: authorizeChannel,
})

export default echo
