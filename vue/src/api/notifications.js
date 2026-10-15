import client from './client'

export const notificationsApi = {
  list: (params) => client.get('/notifications', { params }),
  unreadCount: () => client.get('/notifications/unread-count'),
  markRead: (id) => client.patch(`/notifications/${id}/read`),
  markAllRead: () => client.post('/notifications/read-all'),
  remove: (id) => client.delete(`/notifications/${id}`),
  removeAll: () => client.delete('/notifications'),
}
