/**
 * RunR Node.js HTTP Runtime — wraps user handler as HTTP server.
 */

const http = require('http');
const path = require('path');

const CODE_DIR = '/app/code';
const HANDLER_NAME = process.env.RUNR_HANDLER || 'handler';
const FILENAME = process.env.RUNR_FILENAME || 'main.js';

const modulePath = path.join(CODE_DIR, FILENAME);
const userModule = require(modulePath);

const handlerFn = userModule[HANDLER_NAME];
if (typeof handlerFn !== 'function') {
  console.error(`Error: handler '${HANDLER_NAME}' not found in ${FILENAME}`);
  process.exit(1);
}

const server = http.createServer(async (req, res) => {
  // Knative probe — do not pass it to the handler
  if (req.headers['k-network-probe'] || req.headers['k-kubelet-probe']) {
    res.writeHead(200);
    res.end('ok');
    return;
  }

  // CORS preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, PATCH, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type, Authorization',
      'Access-Control-Max-Age': '86400',
    });
    res.end();
    return;
  }

  let body = '';
  for await (const chunk of req) body += chunk;

  let event;
  try {
    event = body ? JSON.parse(body) : {};
  } catch {
    event = { body };
  }

  const context = {
    method: req.method,
    path: req.url,
    headers: req.headers,
  };

  try {
    const result = await handlerFn(event, context);

    const statusCode = (result && typeof result === 'object' && result.statusCode) || 200;
    const customHeaders = (result && typeof result === 'object' && result.headers) || {};
    const respBody = (result && typeof result === 'object' && result.body !== undefined) ? result.body : result;

    // Case-insensitive header merge: user headers override defaults
    const defaults = { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' };
    const defaultKeys = Object.keys(defaults).map(k => k.toLowerCase());
    const headers = { ...defaults };
    for (const [k, v] of Object.entries(customHeaders)) {
      const idx = defaultKeys.indexOf(k.toLowerCase());
      if (idx !== -1) delete headers[Object.keys(defaults)[idx]];
      headers[k] = v;
    }
    res.writeHead(statusCode, headers);

    const ct = Object.entries(headers).find(([k]) => k.toLowerCase() === 'content-type')?.[1] || '';
    if (typeof respBody === 'string' && !ct.includes('application/json')) {
      res.end(respBody);
    } else {
      res.end(JSON.stringify(respBody));
    }
  } catch (err) {
    console.error(err);
    res.writeHead(500, { 'Access-Control-Allow-Origin': '*', 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: err.message, stack: err.stack }));
  }
});

const port = parseInt(process.env.PORT || '8080', 10);
server.listen(port, '0.0.0.0', () => {
  console.log(`RunR Node.js runtime listening on :${port}`);
});
