import client from './client'

// The organization's secret stores (docs/secret-stores.md): Vault / OpenBao, AWS Secrets Manager,
// GCP Secret Manager, Azure Key Vault. Settings only — a store trusts the region's identity, RunR
// takes no credential for it (SecretStoreController).
export const secretStoresApi = {
  // { data: [...stores], providers: [{ provider, auth: [...], settings: { name: required } }] }
  list:    ()           => client.get('/secret-stores'),
  get:     (id)         => client.get(`/secret-stores/${id}`),
  create:  (payload)    => client.post('/secret-stores', payload),
  update:  (id, payload) => client.put(`/secret-stores/${id}`, payload),
  remove:  (id)         => client.delete(`/secret-stores/${id}`),
  // What the store has to trust for a region: issuer, JWKS, PEM keys, service account, subject, audience
  trust:   (id, regionId) => client.get(`/secret-stores/${id}/trust`, { params: { region_id: regionId } }),
  // Applies the store in the region now and returns its Ready condition
  verify:  (id, regionId) => client.post(`/secret-stores/${id}/verify`, { region_id: regionId }),
}

// External Secrets Operator of a region (the optional feature that syncs references)
export const externalSecretsApi = {
  status:    (regionId) => client.get(`/knative-regions/${regionId}/external-secrets`),
  install:   (regionId) => client.post(`/knative-regions/${regionId}/external-secrets`),
  uninstall: (regionId) => client.delete(`/knative-regions/${regionId}/external-secrets`),
}

// The region's own OpenBao (OpenBaoController): RunR installs and operates it; while it runs, new
// secret values of the region's projects go there (default_for_values) and Postgres bindings can
// get dynamic credentials from it. Needs External Secrets Operator in the region.
export const openBaoApi = {
  status:    (regionId)          => client.get(`/knative-regions/${regionId}/openbao`),
  install:   (regionId)          => client.post(`/knative-regions/${regionId}/openbao`),
  // { default_for_values: bool }
  update:    (regionId, payload) => client.patch(`/knative-regions/${regionId}/openbao`, payload),
  uninstall: (regionId)          => client.delete(`/knative-regions/${regionId}/openbao`),
}
