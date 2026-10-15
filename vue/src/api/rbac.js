import client from './client'

// Roles & permissions (docs/permissions.md)
export const rbacApi = {
  myPermissions()               { return client.get('/auth/permissions') },
  catalog()                     { return client.get('/permissions') },

  roles()                       { return client.get('/roles') },
  createRole(data)              { return client.post('/roles', data) },
  updateRole(key, data)         { return client.put(`/roles/${encodeURIComponent(key)}`, data) },
  deleteRole(key)               { return client.delete(`/roles/${encodeURIComponent(key)}`) },
  setDefaultRole(role)          { return client.put('/roles/default', { role }) },

  members()                     { return client.get('/members') },
  setMemberRole(userId, role)   { return client.put(`/members/${userId}/role`, { role }) },

  groups()                      { return client.get('/groups') },
  group(id)                     { return client.get(`/groups/${id}`) },
  createGroup(data)             { return client.post('/groups', data) },
  updateGroup(id, data)         { return client.put(`/groups/${id}`, data) },
  deleteGroup(id)               { return client.delete(`/groups/${id}`) },
  addGroupMembers(id, userIds)  { return client.post(`/groups/${id}/members`, { user_ids: userIds }) },
  removeGroupMember(id, userId) { return client.delete(`/groups/${id}/members/${userId}`) },

  projectAccess(projectId)              { return client.get(`/projects/${projectId}/access`) },
  grantProjectAccess(projectId, data)   { return client.post(`/projects/${projectId}/access`, data) },
  revokeProjectAccess(projectId, grant) { return client.delete(`/projects/${projectId}/access/${grant}`) },

  ssoMappings()                 { return client.get('/sso/group-mappings') },
  createSsoMapping(data)        { return client.post('/sso/group-mappings', data) },
  updateSsoMapping(id, data)    { return client.put(`/sso/group-mappings/${id}`, data) },
  deleteSsoMapping(id)          { return client.delete(`/sso/group-mappings/${id}`) },
}
