import client from './client'

// Runtime plugins (built-in, platform-registered and custom runtimes)
export const runtimesApi = {
  list:       (params = {})        => client.get('/runtimes', { params }),
  get:        (id)                 => client.get(`/runtimes/${id}`),
  // files: names of the files uploaded with the runtime (dockerfile versions reference them)
  validate:   (manifest, files)    => client.post('/runtimes/validate', files ? { manifest, files } : { manifest }),
  create:     (payload)            => client.post('/runtimes', payload),
  update:     (id, payload)        => client.put(`/runtimes/${id}`, payload),
  destroy:    (id)                 => client.delete(`/runtimes/${id}`),
  testDeploy: (id, payload)        => client.post(`/runtimes/${id}/test-deploy`, payload),
  builds:     (id)                 => client.get(`/runtimes/${id}/builds`),
  build:      (id, payload)        => client.post(`/runtimes/${id}/builds`, payload),
  template:   (id, params = {})    => client.get(`/runtimes/${id}/template`, { params }),
}
