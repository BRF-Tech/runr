import axios from 'axios'
import { moduleRegistry } from '@/modules/index'

const client = axios.create({
  baseURL: import.meta.env.VITE_API_URL || '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

// ETag cache: URL+params → { etag, data }
const etagCache = new Map()

function cacheKey(config) {
  const url = config.url || ''
  const params = config.params ? JSON.stringify(config.params) : ''
  const contextId = config.headers?.['X-Context-ID'] || ''
  return contextId + ':' + url + (params ? '?' + params : '')
}

export function clearEtagCache() {
  etagCache.clear()
}

// Token + Locale + ETag header ekle + module interceptors
client.interceptors.request.use((config) => {
  const token = localStorage.getItem('token')
  if (token) config.headers.Authorization = `Bearer ${token}`

  config.headers['Accept-Language'] = localStorage.getItem('locale') || 'tr'

  // Run module request interceptors
  for (const interceptor of moduleRegistry.requestInterceptors) {
    interceptor(config)
  }

  if (config.method === 'get') {
    const key = cacheKey(config)
    const cached = etagCache.get(key)
    if (cached) {
      config.headers['If-None-Match'] = cached.etag
    }
  }

  return config
})

// Response: ETag kaydet + 304 handle
client.interceptors.response.use(
  (res) => {
    if (res.config.method === 'get' && res.status === 200) {
      const etag = res.headers?.etag
      if (etag) {
        const key = cacheKey(res.config)
        etagCache.set(key, { etag, data: res.data })
      }
    }
    return res
  },
  (error) => {
    // 304 Not Modified → cached data döndür
    if (error.response?.status === 304) {
      const key = cacheKey(error.config)
      const cached = etagCache.get(key)
      if (cached) {
        return Promise.resolve({ ...error.response, status: 200, data: cached.data })
      }
    }

    // 401 → login sayfasına yönlendir (mevcut path'i redirect olarak sakla). On the login page
    // itself the page shows the error (a wrong password answers 401): reloading it would drop
    // the message, and a 401 from a background call there would reload it over and over.
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      localStorage.removeItem('user')
      if (window.location.pathname !== '/login') {
        const current = window.location.pathname + window.location.search
        const redirect = current ? `?redirect=${encodeURIComponent(current)}` : ''
        window.location.href = `/login${redirect}`
      }
    }

    return Promise.reject(error)
  },
)

export default client
