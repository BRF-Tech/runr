import client from './client'

export const accountApi = {
  show:       ()           => client.get('/account'),
  updateProfile: (data)    => client.patch('/account/profile', data),
  uploadAvatar:  (file)    => {
    const fd = new FormData()
    fd.append('avatar', file)
    return client.post('/account/avatar', fd, { headers: { 'Content-Type': 'multipart/form-data' } })
  },
  removeAvatar:  ()        => client.delete('/account/avatar'),
  changePassword:(data)    => client.post('/account/password', data),
  disableAccount:()        => client.post('/account/disable'),
  deleteAccount: (password)=> client.delete('/account', { data: { password } }),

  // 2FA
  setup2FA:       ()       => client.post('/account/2fa/setup'),
  confirm2FA:     (code)   => client.post('/account/2fa/confirm', { code }),
  disable2FA:     (password)=> client.delete('/account/2fa', { data: { password } }),
  regenerateCodes:()       => client.post('/account/2fa/recovery-codes'),

  // Sessions
  sessions:      ()        => client.get('/account/sessions'),
  revokeSession: (id)      => client.delete(`/account/sessions/${id}`),
  revokeAll:     ()        => client.post('/account/sessions/revoke-all'),

  // Notification preferences
  getPreferences:  ()      => client.get('/account/notification-preferences'),
  savePreferences: (prefs) => client.put('/account/notification-preferences', { preferences: prefs }),

  // Contact form
  sendContact: (data) => client.post('/contact', data),
}
