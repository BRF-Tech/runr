# Browser shell

Browser terminal access to the pods of an active deployment.

## Overall flow

```
Browser (xterm.js, ShellTerminalModal.vue)
       │
       │  WSS /ws/shell?token={uuid}
       │  (gateway Caddy / Vite dev proxy strips /ws → Reverb route /shell)
       ▼
   Reverb (Laravel, :8080)
   ┌──────────────────────────────────────────────────────┐
   │  ShellAwareFactory → ShellController                 │
   │                                                      │
   │  1. Redis GET exec:config:{token}  →  config         │
   │  2. Redis DEL exec:config:{token}  →  one-time use   │
   │  3. Wake phase: `php artisan shell:wake` subprocess  │
   │     finds a Running pod or wakes the Knative service │
   │     status frames → WS: searching, waking_up, waiting│
   │  4. PTY phase: kubeconfig → /tmp/runr-shell-{random} │
   │     (chmod 600), ShellPtyProcess: kubectl exec -it   │
   │  5. PTY ↔ WebSocket bridge                           │
   │     WS  → PTY : {input: base64}   → stdin            │
   │     PTY → WS  : stdout            → {output: base64} │
   │     WS  → PTY : {resize:{cols,rows}} → stty          │
   │  6. Keep-alive every 20s (prevents scale-to-zero)    │
   │  7. 5 min idle timeout (checked every 15s)           │
   │  8. Cleanup: kill process + delete kubeconfig file   │
   └──────────────────────────────────────────────────────┘
       │
       │  kubectl exec -it {pod} -n {ns} [-c {container}]
       │  --kubeconfig=/tmp/runr-shell-{random} -- /bin/sh
       ▼
  Kubernetes Pod
  (the user's deployment, /bin/sh)
```

## Token flow

```
1. The user clicks the "Shell" button

2. POST /api/projects/{p}/deployments/{d}/exec   { cols, rows }
   ├── auth:sanctum — authenticated users only
   ├── Deployment status must be active or running
   ├── Region must have a kubeconfig and must not be a demo region
   ├── Redis SETEX "exec:config:{uuid}" 120s  ← config JSON
   └── Response: { session_id: uuid }

3. The browser opens the WebSocket: /ws/shell?token={uuid}

4. ShellController accepts the connection:
   ├── Redis GET "exec:config:{uuid}"  → config found
   ├── Redis DEL "exec:config:{uuid}"  → token can no longer be used
   ├── shell:wake: finds a Running pod of the deployed version, or wakes the
   │   Knative service through the activator and waits for a pod
   ├── ShellPtyProcess writes the kubeconfig and spawns kubectl exec
   └── Sends { connected: true, pod }

5. Terminal session runs (bidirectional PTY ↔ WS)

6. Close: WS close / idle timeout / process exit
   └── process killed + kubeconfig file deleted
```

Errors at any step are sent as `{ error }` and the connection is closed.

## Security

| Layer | Mechanism |
|-------|-----------|
| API endpoint | Laravel Sanctum auth — no token without a login |
| Token | UUID v4 (122 bits of entropy), 120 second TTL |
| One-time use | The Redis key is deleted on the first connection |
| Kubeconfig | Written to `/tmp` with mode 600 on the Reverb host, never sent into the pod, deleted on close |
| kubectl exec | Only the target pod + namespace |
| Reverb port | Internal (8080); reached through the gateway's `/ws/*` route (port 8064 in the local Docker Compose setup) |
| Idle timeout | Session closes after 5 minutes without input, resize or output |

What the user can reach from inside the pod is governed by that pod's own Kubernetes service account and network policy, not by the shell session.

## Why it runs inside Reverb

The shell is embedded in the Reverb process instead of a separate service. Reverb's ReactPHP event loop handles the PTY streams without blocking. `ShellPtyProcess` allocates a real PTY with `proc_open()`, so terminal colors, Ctrl+C and tab completion work. One process, no extra container.

## Files

| File | Role |
|------|------|
| `app/Services/Shell/ShellAwareFactory.php` | Reverb factory; registers the `/shell` route (and the `/agent/{regionId}` route for runr-agent) |
| `app/Services/Shell/ShellController.php` | WebSocket handler: wake phase, PTY bridge, keep-alive, idle timeout |
| `app/Services/Shell/ShellPtyProcess.php` | PTY spawn, kubectl exec, I/O, resize |
| `app/Services/Shell/SafeRouter.php` | Reverb router fix (WebSocket upgrade detection, 400 for non-WS requests) |
| `app/Console/Commands/ShellWakeCommand.php` | `shell:wake` — find or wake a pod, JSON-line status output |
| `app/Console/Commands/StartReverbCommand.php` | `reverb:start-shell` artisan command |
| `app/Http/Controllers/Api/ExecController.php` | Access checks, one-time token |
| `vue/src/components/ShellTerminalModal.vue` | xterm.js terminal and WebSocket client |

See also: [websocket-events.md](websocket-events.md), [development.md](development.md).
