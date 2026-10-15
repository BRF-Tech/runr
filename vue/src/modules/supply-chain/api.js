import client from '@/api/client'

/** Supply chain + data residency API (docs/supply-chain.md). */
export const supplyChainApi = {
  // Project security
  projectSecurity:    (projectId) => client.get(`/projects/${projectId}/security`),
  versionSecurity:    (projectId, versionId) => client.get(`/projects/${projectId}/versions/${versionId}/security`),
  rescan:             (projectId, versionId) => client.post(`/projects/${projectId}/versions/${versionId}/security/scan`),
  deploymentSecurity: (projectId, deploymentId) => client.get(`/projects/${projectId}/deployments/${deploymentId}/security`),
  sbomUrl:            (projectId, versionId, format) => `/projects/${projectId}/versions/${versionId}/sbom?format=${format}`,
  downloadSbom:       (projectId, versionId, format) =>
    client.get(`/projects/${projectId}/versions/${versionId}/sbom`, { params: { format }, responseType: 'blob' }),

  // Region supply chain
  region:             (regionId) => client.get(`/knative-regions/${regionId}/supply-chain`),
  updateRegion:       (regionId, data) => client.put(`/knative-regions/${regionId}/supply-chain`, data),
  generateKey:        (regionId) => client.post(`/knative-regions/${regionId}/supply-chain/signing-key`),
  forgetRetiredKeys:  (regionId) => client.delete(`/knative-regions/${regionId}/supply-chain/signing-key/retired`),
  publicKey:          (regionId) => client.get(`/knative-regions/${regionId}/supply-chain/public-key`, { responseType: 'blob' }),
  refreshScannerDb:   (regionId) => client.post(`/knative-regions/${regionId}/supply-chain/scanner-db/refresh`),
  installAdmission:   (regionId, mode) => client.post(`/knative-regions/${regionId}/supply-chain/admission`, mode ? { mode } : {}),
  removeAdmission:    (regionId) => client.delete(`/knative-regions/${regionId}/supply-chain/admission`),
  admissionPreview:   (regionId) => client.get(`/knative-regions/${regionId}/supply-chain/admission/preview`),
  runtimeImages:      (regionId) => client.get(`/knative-regions/${regionId}/supply-chain/runtime-images`),
  downloadRuntimeSbom: (regionId, reportId, format) =>
    client.get(`/knative-regions/${regionId}/supply-chain/runtime-images/${reportId}/sbom`, { params: { format }, responseType: 'blob' }),

  // Data residency
  residency:          () => client.get('/residency'),
  tenantPolicy:       (tenantId) => client.get(`/tenants/${tenantId}/security-policy`),
  updateTenantPolicy: (tenantId, data) => client.put(`/tenants/${tenantId}/security-policy`, data),
}

/** Saves a downloaded blob (axios responseType: 'blob') under a file name. */
export function saveBlob(response, fallbackName) {
  const disposition = response?.headers?.['content-disposition'] ?? ''
  const name = disposition.match(/filename="?([^";]+)"?/)?.[1] ?? fallbackName
  const url = URL.createObjectURL(response.data)
  const a = document.createElement('a')
  a.href = url
  a.download = name
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(url)
}
