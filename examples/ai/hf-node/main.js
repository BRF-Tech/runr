/**
 * RunR Node.js function using two bound models (Project → Models):
 *   EMBED — an embedding model, CHAT — a text-generation model.
 * RunR injects RUNR_MODEL_<ALIAS>_URL and RUNR_MODEL_<ALIAS>_MODEL; the global fetch of
 * Node.js 18+ is all it takes.
 *
 * POST {"text": "..."}                    → embedding size and the first values
 * POST {"action": "chat", "text": "..."}  → the chat model's answer
 */

function model(alias) {
  const url = process.env[`RUNR_MODEL_${alias}_URL`];
  if (!url) {
    const err = new Error(`no model bound as ${alias}: bind one in the project's Models tab and redeploy`);
    err.statusCode = 501;
    throw err;
  }
  return { url: url.replace(/\/+$/, ''), name: process.env[`RUNR_MODEL_${alias}_MODEL`] || '' };
}

async function post(url, payload) {
  const res = await fetch(url, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
    signal: AbortSignal.timeout(120_000),
  });
  if (!res.ok) {
    const err = new Error(`model answered ${res.status}: ${(await res.text()).slice(0, 500)}`);
    err.statusCode = 502;
    throw err;
  }
  return res.json();
}

async function embed(texts) {
  const { url, name } = model('EMBED');
  const result = await post(`${url}/v1/embeddings`, { model: name, input: texts });
  return result.data.map((item) => item.embedding);
}

async function chat(text) {
  const { url, name } = model('CHAT');
  const result = await post(`${url}/v1/chat/completions`, {
    model: name,
    messages: [
      { role: 'system', content: 'Answer in one short sentence.' },
      { role: 'user', content: text },
    ],
    max_tokens: 64,
    temperature: 0,
  });
  return result.choices[0].message.content;
}

exports.handler = async (event, context) => {
  const input = event && typeof event === 'object' ? event : {};
  const text = input.text || 'RunR runs models next to your functions.';

  try {
    if (input.action === 'chat') {
      return { statusCode: 200, body: { answer: await chat(text) } };
    }
    const [vector] = await embed([text]);
    return { statusCode: 200, body: { dims: vector.length, head: vector.slice(0, 4) } };
  } catch (err) {
    return { statusCode: err.statusCode || 503, body: { error: err.message } };
  }
};
