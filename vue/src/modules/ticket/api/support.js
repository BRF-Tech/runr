import client from '@/api/client'

export function fetchTickets(params = {}) {
  return client.get('/support-tickets', { params })
}

export function fetchTicketStats() {
  return client.get('/support-tickets/stats')
}

export function createTicket(data) {
  return client.post('/support-tickets', data)
}

export function fetchTicket(id) {
  return client.get(`/support-tickets/${id}`)
}

export function sendReply(ticketId, formData) {
  return client.post(`/support-tickets/${ticketId}/replies`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function createTicketWithFiles(formData) {
  return client.post('/support-tickets', formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function fetchTicketSuggestions(subject) {
  return client.get('/support-tickets/suggestions', { params: { subject } })
}

// Admin endpoints — tenant_id passed for cross-tenant context
export function fetchAdminTickets(params = {}) {
  return client.get('/admin/support-tickets', { params })
}

export function fetchAdminTicketStats() {
  return client.get('/admin/support-tickets/stats')
}

export function fetchAdminTicket(id, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.get(`/admin/support-tickets/${id}`, { params })
}

export function updateTicketStatus(id, status, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.patch(`/admin/support-tickets/${id}/status`, { status }, { params })
}

export function assignTicket(id, assignedTo, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.patch(`/admin/support-tickets/${id}/assign`, { assigned_to: assignedTo }, { params })
}

export function updateTicketTags(id, tags, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.patch(`/admin/support-tickets/${id}/tags`, { tags }, { params })
}

export function sendAdminReply(ticketId, formData, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.post(`/admin/support-tickets/${ticketId}/replies`, formData, {
    headers: { 'Content-Type': 'multipart/form-data' },
    params,
  })
}

export function markRead(id) {
  return client.post(`/support-tickets/${id}/read`)
}

export function adminMarkRead(id, tenantId) {
  const params = tenantId ? { tenant_id: tenantId } : {}
  return client.post(`/admin/support-tickets/${id}/read`, {}, { params })
}

// Attachment download URL builders (auth-protected, not public /storage)
export function attachmentUrl(ticketId, filePath) {
  const filename = filePath.split('/').pop()
  const base = import.meta.env.VITE_API_URL || '/api'
  return `${base}/support-tickets/${ticketId}/attachments/${filename}`
}

export function adminAttachmentUrl(ticketId, filePath, tenantId) {
  const filename = filePath.split('/').pop()
  const base = import.meta.env.VITE_API_URL || '/api'
  const qs = tenantId ? `?tenant_id=${tenantId}` : ''
  return `${base}/admin/support-tickets/${ticketId}/attachments/${filename}${qs}`
}
