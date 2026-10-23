import client from './client'

// Runtime patches (docs/runtimes.md#runtime-patches): the signed patch feed of RunR's runtime
// images, a project's patch state (apply / pin / unpin) and the platform administrators'
// Patches page (/admin/runtime-patches, users.is_admin only)
export const runtimePatchesApi = {
  // The feed's patches, newest first per runtime version (filters: runtime, version)
  list:      (params = {})                 => client.get('/runtime-patches', { params }),
  // Banners the console keeps on screen until resolved (zero-day / critical patches, platform advisories, feed trouble)
  banners:   ()                            => client.get('/runtime-patches/banners'),

  // A project's state, its runtime version's pinnable patches (the newest five) and migrations
  project:   (projectId)                   => client.get(`/projects/${projectId}/runtime-patches`),
  // Redeploy the live version onto the newest patch (a pin goes) — projects.deploy
  apply:     (projectId)                   => client.post(`/projects/${projectId}/runtime-patches/apply`),
  // Pin to one of the pinnable patches; deploy (default true) redeploys onto it
  pin:       (projectId, patchId, deploy = true) => client.put(`/projects/${projectId}/runtime-patches/pin`, { patch_id: patchId, deploy }),
  unpin:     (projectId)                   => client.delete(`/projects/${projectId}/runtime-patches/pin`),

  // Platform administrators
  admin:          ()                       => client.get('/admin/runtime-patches'),
  check:          ()                       => client.post('/admin/runtime-patches/check'),
  setPolicy:      (autoMigrate)            => client.put('/admin/runtime-patches/policy', { auto_migrate: autoMigrate }),
  remind:         (patchId)                => client.post(`/admin/runtime-patches/${encodeURIComponent(patchId)}/remind`),
  force:          (patchId)                => client.post(`/admin/runtime-patches/${encodeURIComponent(patchId)}/force`),
  extendDeadline: (patchId, deadlineAt)    => client.put(`/admin/runtime-patches/${encodeURIComponent(patchId)}/deadline`, { deadline_at: deadlineAt }),
}
