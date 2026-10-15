import client from './client'

export const usersApi = {
  list(params = {})        { return client.get('/users', { params }) },
  create(data)             { return client.post('/users', data) },
  update(id, data)         { return client.patch(`/users/${id}`, data) },
  destroy(id)              { return client.delete(`/users/${id}`) },
  reset2FA(id)             { return client.post(`/users/${id}/reset-2fa`) },
}
