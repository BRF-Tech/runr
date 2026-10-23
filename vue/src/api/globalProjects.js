import client from './client'

/**
 * Global projects (docs/global-projects.md): one project in several regions behind one global
 * host name. GET answers the project's `global` object; PUT changes mode, primary and regions.
 */
export const globalProjectsApi = {
  get:    (projectId)          => client.get(`/projects/${projectId}/global`),
  /** { mode?: 'ha'|'proximity', failover?: 'automatic'|'manual', primary_region_id?, region_ids? } */
  update: (projectId, payload) => client.put(`/projects/${projectId}/global`, payload),
  /** Rolls the active version out again where it is not ready, re-applies the routes → 202 */
  sync:   (projectId)          => client.post(`/projects/${projectId}/global/sync`),
  /**
   * Failover of the primary to another region (the primary does not answer): its replicas are
   * promoted at once, the old primary is fenced until it rejoins → 202 with the global object.
   * regionId null: the first healthy region in HA order.
   */
  failover: (projectId, regionId = null) => client.post(`/projects/${projectId}/global/failover`, regionId ? { region_id: regionId } : {}),
}

/**
 * Global DNS: the zone RunR serves from a PowerDNS in every participating region, the records
 * the operator delegates once, and each region's server.
 */
export const globalDnsApi = {
  overview: ()         => client.get('/global-dns'),
  sync:     ()         => client.post('/global-dns/sync'),
  /** The region's server object + eligible / reason */
  region:   (regionId) => client.get(`/knative-regions/${regionId}/global-dns`),
  enable:   (regionId) => client.post(`/knative-regions/${regionId}/global-dns`),
  disable:  (regionId) => client.delete(`/knative-regions/${regionId}/global-dns`),
  /** The server's GeoIP source: { source: 'default'|'url'|'file'|'none', url? } */
  setGeoip:    (regionId, payload) => client.put(`/knative-regions/${regionId}/global-dns/geoip`, payload),
  /** The server reads its GeoIP source again (its pod restarts) → 202 */
  reloadGeoip: (regionId)          => client.post(`/knative-regions/${regionId}/global-dns/geoip/reload`),
}
