import client from './client'

let cached = null

export async function fetchVersion() {
  if (cached) return cached
  try {
    const { data } = await client.get('/version')
    cached = data.version
    return cached
  } catch {
    return null
  }
}
