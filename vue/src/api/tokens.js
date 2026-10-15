import client from './client'

export const tokensApi = {
  list()        { return client.get('/tokens') },
  create(data)  { return client.post('/tokens', data) },
  destroy(id)   { return client.delete(`/tokens/${id}`) },
}
