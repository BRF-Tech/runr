import client from './client'

// Git deploy (docs/git-deploy.md): the project's Git tab and Settings → Git providers
export const gitApi = {
  get: (projectId) =>
    client.get(`/projects/${projectId}/git`),

  update: (projectId, data) =>
    client.put(`/projects/${projectId}/git`, data),

  deploys: (projectId, params = {}) =>
    client.get(`/projects/${projectId}/git/deploys`, { params }),

  versionSources: (projectId) =>
    client.get(`/projects/${projectId}/git/versions`),

  redeploy: (projectId) =>
    client.post(`/projects/${projectId}/git/redeploy`),

  repositories: (projectId, payload) =>
    client.post(`/projects/${projectId}/git/repos`, payload),

  connect: (projectId, payload) =>
    client.post(`/projects/${projectId}/git/connect`, payload),

  disconnect: (projectId) =>
    client.delete(`/projects/${projectId}/git/connect`),

  installations: (projectId, providerId) =>
    client.get(`/projects/${projectId}/git/installations`, { params: { provider_id: providerId } }),

  githubInstallUrl: (projectId, providerId) =>
    client.post(`/projects/${projectId}/git/github/install`, { provider_id: providerId }),

  gitlabAuthorizeUrl: (projectId, providerId) =>
    client.post(`/projects/${projectId}/git/gitlab/authorize`, { provider_id: providerId }),

  // Preview environments
  previews: (projectId, all = false) =>
    client.get(`/projects/${projectId}/previews`, { params: all ? { all: 1 } : {} }),

  redeployPreview: (projectId, previewId) =>
    client.post(`/projects/${projectId}/previews/${previewId}/redeploy`),

  deletePreview: (projectId, previewId) =>
    client.delete(`/projects/${projectId}/previews/${previewId}`),

  // Settings → Git providers
  providers: () =>
    client.get('/git-providers'),

  createProvider: (data) =>
    client.post('/git-providers', data),

  updateProvider: (id, data) =>
    client.put(`/git-providers/${id}`, data),

  deleteProvider: (id) =>
    client.delete(`/git-providers/${id}`),
}
