import client from './client'

export const settingsApi = {
  get:        ()     => client.get('/settings'),
  update:     (data) => client.patch('/settings', data),
  seedDemo:   ()     => client.post('/demo'),
  removeDemo: ()     => client.delete('/demo'),
}
