import client from '@/api/client'

const enc = encodeURIComponent

// AI agents (docs/mcp.md): the RunR MCP server, OAuth consent and hosted MCP servers
export const mcpApi = {
  // RunR MCP server: endpoint, protocol, OAuth metadata
  info:          ()                 => client.get('/mcp'),
  tools:         ()                 => client.get('/mcp/tools'),
  clients:       ()                 => client.get('/mcp/clients'),
  revokeClient:  (clientId)         => client.delete(`/mcp/clients/${enc(clientId)}`),

  // OAuth consent (the authorization request an MCP client started)
  consentRequest: (id)              => client.get(`/oauth2/requests/${enc(id)}`),
  approve:       (id, payload)      => client.post(`/oauth2/requests/${enc(id)}/approve`, payload),
  deny:          (id)               => client.post(`/oauth2/requests/${enc(id)}/deny`),

  // Hosted MCP servers
  templates:     ()                 => client.get('/mcp-servers/templates'),
  server:        (projectId)        => client.get(`/projects/${enc(projectId)}/mcp-server`),
  saveServer:    (projectId, data)  => client.put(`/projects/${enc(projectId)}/mcp-server`, data),
  deleteServer:  (projectId)        => client.delete(`/projects/${enc(projectId)}/mcp-server`),
  rotateKey:     (projectId)        => client.post(`/projects/${enc(projectId)}/mcp-server/rotate-key`),
  inspector:     (projectId, data)  => client.post(`/projects/${enc(projectId)}/mcp-server/inspector`, data),
  calls:         (projectId, params = {}) => client.get(`/projects/${enc(projectId)}/mcp-server/calls`, { params }),
}
