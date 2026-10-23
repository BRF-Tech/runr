<?php

namespace App\Services\Concerns;

trait RegionDemoCodeTrait
{
    public function frontendCode(string $backendUrl): string
    {
        return <<<JS
const http = require('http');

const HTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Contact Form</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: system-ui, sans-serif; background: #0a0a0a; color: #e5e5e5;
           display: flex; justify-content: center; align-items: center; min-height: 100vh; }
    .card { background: #1a1a1a; border: 1px solid #333; border-radius: 12px;
            padding: 2rem; width: 100%; max-width: 420px; }
    h1 { font-size: 1.25rem; margin-bottom: 1.5rem; color: #fff; }
    label { display: block; font-size: 0.75rem; color: #999; margin-bottom: 0.25rem; }
    input, textarea { width: 100%; padding: 0.5rem 0.75rem; margin-bottom: 1rem;
                      background: #111; border: 1px solid #333; border-radius: 6px;
                      color: #e5e5e5; font-size: 0.875rem; outline: none; }
    textarea { resize: vertical; min-height: 80px; }
    input:focus, textarea:focus { border-color: #009fe3; }
    button { width: 100%; padding: 0.6rem; background: #009fe3; color: #fff;
             border: none; border-radius: 6px; font-size: 0.875rem; cursor: pointer; }
    button:hover { background: #0086c3; }
    button:disabled { opacity: 0.5; cursor: not-allowed; }
    .msg { margin-top: 1rem; padding: 0.75rem; border-radius: 6px; font-size: 0.8rem; }
    .msg.ok { background: #052e16; color: #4ade80; border: 1px solid #166534; }
    .msg.err { background: #2d0a0a; color: #f87171; border: 1px solid #7f1d1d; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Contact Us</h1>
    <form id="cf">
      <label for="name">Name</label>
      <input id="name" name="name" required placeholder="Your name" />
      <label for="email">Email</label>
      <input id="email" name="email" type="email" required placeholder="you@example.com" />
      <label for="message">Message</label>
      <textarea id="message" name="message" required placeholder="Your message..."></textarea>
      <button type="submit">Send</button>
    </form>
    <div id="result"></div>
  </div>
  <script>
    document.getElementById('cf').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = e.target.querySelector('button');
      btn.disabled = true;
      const body = JSON.stringify({
        name: document.getElementById('name').value,
        email: document.getElementById('email').value,
        message: document.getElementById('message').value,
      });
      try {
        const res = await fetch('/', {
          method: 'POST', headers: { 'Content-Type': 'application/json' }, body
        });
        const data = await res.json();
        document.getElementById('result').innerHTML =
          '<div class="msg ok">' + (data.message || 'Sent!') + '</div>';
        e.target.reset();
      } catch (err) {
        document.getElementById('result').innerHTML =
          '<div class="msg err">Error: ' + err.message + '</div>';
      } finally { btn.disabled = false; }
    });
  </script>
</body>
</html>`;

exports.handler = async (event, context) => {
  if (context.method === 'POST') {
    try {
      const payload = event;
      const backendRes = await fetch(BACKEND, {
        method: 'POST',
        // the backend admits the frontend on the region's internal network by this token
        headers: { 'Content-Type': 'application/json', 'X-Runr-Internal-Token': process.env.RUNR_INTERNAL_TOKEN || '' },
        body: JSON.stringify(payload),
      });
      const result = await backendRes.json();
      // body is the raw parsed value here, not a re-stringified one: the runtime (server.js)
      // already JSON-encodes a non-string body exactly once. Encoding it again here would
      // double-encode it, so the browser's res.json() would get a JSON string back instead
      // of the object.
      return { statusCode: backendRes.status, headers: { 'Content-Type': 'application/json' }, body: result };
    } catch (err) {
      return { statusCode: 502, headers: { 'Content-Type': 'application/json' }, body: { error: err.message } };
    }
  }
  return { statusCode: 200, headers: { 'Content-Type': 'text/html; charset=utf-8' }, body: HTML };
};

const BACKEND = process.env.BACKEND_URL || '{$backendUrl}';
JS;
    }

    public function backendFiles(string $redisHost, string $redisPort): array
    {
        $handler = <<<'PHP'
<?php

require_once __DIR__ . '/vendor/autoload.php';

function handler($event, $context): array
{
    $body = is_string($event['body'] ?? null)
        ? json_decode($event['body'], true)
        : ($event['body'] ?? $event);

    $name    = trim($body['name'] ?? '');
    $email   = trim($body['email'] ?? '');
    $message = trim($body['message'] ?? '');

    if (!$name || !$email || !$message) {
        return ['statusCode' => 422, 'body' => ['error' => 'All fields required']];
    }

    $host   = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port   = (int)(getenv('REDIS_PORT') ?: 6379);
    $stream = getenv('QUEUE_STREAM') ?: 'contact-forms';

    try {
        $redis = new \Predis\Client(['host' => $host, 'port' => $port]);
        $id = $redis->executeRaw([
            'XADD', $stream, '*',
            'name', $name, 'email', $email, 'message', $message, 'time', date('c'),
        ]);
        error_log("Contact form saved: {$id}");
        // body is a raw array here, not a pre-encoded JSON string: the runtime (server.php)
        // already json_encodes a non-string body exactly once. Encoding it again here would
        // double-encode it, so the frontend's backendRes.json() would get a JSON string back
        // instead of the object.
        return ['statusCode' => 200, 'body' => ['message' => 'Message received', 'id' => $id]];
    } catch (\Throwable $e) {
        error_log("Redis error: " . $e->getMessage());
        return ['statusCode' => 500, 'body' => ['error' => 'Internal error']];
    }
}
PHP;

        $composer = json_encode([
            'require' => ['predis/predis' => '^2.0'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return [
            'handler.php' => $handler,
            'composer.json' => $composer,
        ];
    }

    public function emailSenderCode(): string
    {
        return <<<'GO'
package main

import (
	"encoding/json"
	"fmt"
	"log"
	"os"
	"time"
)

type ContactForm struct {
	Name    string `json:"name"`
	Email   string `json:"email"`
	Message string `json:"message"`
	Time    string `json:"time"`
}

// Handler processes queue messages from the contact form stream (QUEUE_STREAM of the backend).
func Handler(event interface{}, context interface{}) (interface{}, error) {
	raw, err := json.Marshal(event)
	if err != nil {
		return map[string]interface{}{"error": "marshal failed"}, nil
	}

	var form ContactForm
	if err := json.Unmarshal(raw, &form); err != nil {
		log.Printf("Parse error: %v — raw: %s", err, string(raw))
		return map[string]interface{}{"status": "skipped"}, nil
	}

	log.Printf("New contact form from %s <%s>", form.Name, form.Email)
	log.Printf("Message: %s", form.Message)

	time.Sleep(100 * time.Millisecond)
	log.Printf("Email sent to %s at %s", form.Email, time.Now().Format(time.RFC3339))

	hostname, _ := os.Hostname()
	return map[string]interface{}{
		"status":    "sent",
		"recipient": form.Email,
		"worker":    hostname,
		"timestamp": fmt.Sprintf("%d", time.Now().Unix()),
	}, nil
}
GO;
    }

    public function statsDockerImage(): string
    {
        return 'ghcr.io/brf-tech/runr/reqs/redis:7-alpine';
    }

    public function statsCommand(): array
    {
        return ['/bin/sh', '-c', implode("\n", [
            'REDIS_HOST="${REDIS_HOST:-127.0.0.1}"',
            'REDIS_PORT="${REDIS_PORT:-6379}"',
            'STREAM="${QUEUE_STREAM:-contact-forms}"',
            'echo "=== Contact Form Stats ($STREAM) ==="',
            'TOTAL=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" XLEN "$STREAM" 2>/dev/null || echo 0)',
            'echo "Total submissions: $TOTAL"',
            'echo "--- Last 5 entries ---"',
            'redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" XREVRANGE "$STREAM" + - COUNT 5 2>/dev/null || echo "(no data)"',
            'echo "=== Done ==="',
        ])];
    }
}
