# Hugging Face models from a Node.js function

[`main.js`](main.js) is a RunR Node.js function (XaaS) that calls two bound models with the
global `fetch`: embeddings through the alias `EMBED` and a chat answer through `CHAT`. Set up
the models and bindings as in the [Python example](../hf-python/README.md#set-up), paste
`main.js` into the editor and deploy.

```bash
curl -s -X POST https://<function>.<your function domain>/ -d '{"text": "hello"}'
curl -s -X POST https://<function>.<your function domain>/ -d '{"action": "chat", "text": "What is RunR?"}'
```

The requests use the OpenAI-compatible routes (`/v1/embeddings`, `/v1/chat/completions`) that
TEI, vLLM, TGI and RunR's hf-serve answer, so the OpenAI SDK for Node.js works as well:

```js
const OpenAI = require('openai');
const client = new OpenAI({ baseURL: process.env.RUNR_MODEL_CHAT_OPENAI_BASE, apiKey: 'unused' });
const reply = await client.chat.completions.create({
  model: process.env.RUNR_MODEL_CHAT_MODEL,
  messages: [{ role: 'user', content: 'hello' }],
});
```

(add `openai` to a FaaS project's `package.json`). See
[docs/huggingface.md](../../../docs/huggingface.md) for every variable RunR sets per binding.
