import client from '@/api/client'

/** API product of the gateways (api/routes/api-product.php, docs/api-product.md). */
export const apiProductApi = {
  // Gateways and their routes
  gateways:        () => client.get('/gateways'),
  gateway:         (id) => client.get(`/gateways/${id}`),
  // the caller's gateway in a region, created when there is none
  createGateway:   (regionId) => client.post('/gateways', { knative_region_id: regionId }),
  updateGateway:   (id, payload) => client.patch(`/gateways/${id}`, payload),
  // { project_id, environment?, path, methods? } — environment: a project environment's name (docs/environments.md)
  addRoute:        (gatewayId, payload) => client.post(`/gateways/${gatewayId}/routes`, payload),
  updateRoute:     (gatewayId, routeId, payload) => client.patch(`/gateways/${gatewayId}/routes/${routeId}`, payload),
  removeRoute:     (gatewayId, routeId) => client.delete(`/gateways/${gatewayId}/routes/${routeId}`),

  // The gateway's own domain
  setDomain:       (gatewayId, domain) => client.put(`/gateways/${gatewayId}/custom-domain`, { domain }),
  verifyDomain:    (gatewayId) => client.post(`/gateways/${gatewayId}/custom-domain/verify`),
  removeDomain:    (gatewayId) => client.delete(`/gateways/${gatewayId}/custom-domain`),

  // Consumers and their keys
  consumers:       (params = {}) => client.get('/api-consumers', { params }),
  createConsumer:  (payload) => client.post('/api-consumers', payload),
  updateConsumer:  (id, payload) => client.patch(`/api-consumers/${id}`, payload),
  deleteConsumer:  (id) => client.delete(`/api-consumers/${id}`),
  keys:            (consumerId) => client.get(`/api-consumers/${consumerId}/keys`),
  createKey:       (consumerId, payload) => client.post(`/api-consumers/${consumerId}/keys`, payload),
  rotateKey:       (keyId, payload = {}) => client.post(`/api-keys/${keyId}/rotate`, payload),
  revokeKey:       (keyId) => client.post(`/api-keys/${keyId}/revoke`),

  // Plans
  plans:           (params = {}) => client.get('/api-plans', { params }),
  createPlan:      (payload) => client.post('/api-plans', payload),
  updatePlan:      (id, payload) => client.patch(`/api-plans/${id}`, payload),
  deletePlan:      (id) => client.delete(`/api-plans/${id}`),

  // Usage
  usage:           (params = {}) => client.get('/api-usage', { params }),

  // Public developer page (no sign-in)
  developerPage:   (key) => client.get(`/public/developer-pages/${encodeURIComponent(key)}`),
}
