<?php

/*
|--------------------------------------------------------------------------
| AI agents: the RunR MCP server and hosted MCP servers (docs/mcp.md)
|--------------------------------------------------------------------------
|
| /mcp                 the RunR MCP server (Streamable HTTP, JSON-RPC 2.0) on the console domain
| /oauth2/*            the OAuth 2.1 authorization server MCP clients connect through
| /mcp/servers/{name}  the gateway in front of MCP servers hosted as RunR projects
|
*/

return [

    'enabled' => (bool) env('MCP_ENABLED', true),

    // Public base URL of the console (scheme + host, no path). Empty: the request's own origin
    // (behind the gateway: X-Forwarded-Host / -Proto of the trusted proxies).
    'public_url' => env('MCP_PUBLIC_URL', ''),

    // Name shown to MCP clients (serverInfo.title); empty: the brand name, else "RunR"
    'server_title' => env('MCP_SERVER_TITLE', ''),

    // Protocol revisions this server speaks, newest first; the first is offered by default
    'protocol_versions' => ['2025-11-25', '2025-06-18', '2025-03-26'],

    // Largest JSON-RPC request body accepted on /mcp and the hosted gateway (bytes)
    'max_request_bytes' => (int) env('MCP_MAX_REQUEST_BYTES', 4 * 1024 * 1024),

    // Browser origins allowed to call /mcp, the OAuth endpoints and the gateway, besides the
    // console's own origin. Requests without an Origin header (CLI and desktop clients) are
    // always accepted; '*' wildcards a part of the origin (http://localhost:*).
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MCP_ALLOWED_ORIGINS',
        'http://localhost:*,http://127.0.0.1:*',
    ))))),

    'sessions' => [
        // A session that sees no request for this long ends (minutes)
        'idle_minutes' => (int) env('MCP_SESSION_IDLE_MINUTES', 1440),
    ],

    'tools' => [
        // Longest a long-running tool (deploy, build wait) waits before returning a status (seconds)
        'max_wait_seconds' => (int) env('MCP_TOOL_MAX_WAIT_SECONDS', 600),
        // Poll interval of long-running tools (milliseconds)
        'poll_interval_ms' => (int) env('MCP_TOOL_POLL_INTERVAL_MS', 2000),
        // Largest file content returned by a read tool or resource (bytes)
        'max_file_bytes' => (int) env('MCP_TOOL_MAX_FILE_BYTES', 512 * 1024),
        // Log lines a log tool returns at most
        'max_log_lines' => (int) env('MCP_TOOL_MAX_LOG_LINES', 500),
        // Largest tar archive a sandbox archive tool moves (bytes, before base64)
        'max_archive_bytes' => (int) env('MCP_TOOL_MAX_ARCHIVE_BYTES', 4 * 1024 * 1024),
    ],

    // runr://docs/{page}: the Markdown pages of the repository's docs/ directory. Empty: ../docs
    // next to the API (repository checkout, Docker Compose mount), else resources/docs (the API
    // image: CI copies docs/ there before the build).
    'docs_path' => env('MCP_DOCS_PATH', ''),

    'oauth' => [
        'enabled' => (bool) env('MCP_OAUTH_ENABLED', true),
        // Dynamic client registration (RFC 7591) without an initial access token
        'open_registration' => (bool) env('MCP_OAUTH_OPEN_REGISTRATION', true),
        // An authorization request waits this long for the consent (minutes)
        'request_ttl_minutes' => 10,
        // An authorization code is exchanged within this many seconds, once
        'code_ttl_seconds' => 120,
        // Token lifetimes the consent screen offers (days); null = until revoked
        'token_lifetimes' => [7, 30, 90, 180, 365, null],
        'default_token_lifetime' => 30,
        // Registered clients nobody connected with for this many days are removed
        'unused_client_days' => 30,
        // Redirect URIs: https, or http on a loopback address (native clients, RFC 8252)
        'allow_http_loopback_redirects' => true,
        // Requests per minute and client IP to /oauth2/* (registration, authorization, token, revocation)
        'per_minute' => (int) env('MCP_OAUTH_PER_MINUTE', 60),
    ],

    'gateway' => [
        // Requests per minute and principal (user, else client IP) for one hosted server
        'per_minute' => (int) env('MCP_GATEWAY_PER_MINUTE', 600),
        'connect_timeout' => (int) env('MCP_GATEWAY_CONNECT_TIMEOUT', 10),
        // Longest a forwarded request (including an SSE stream) stays open (seconds)
        'timeout' => (int) env('MCP_GATEWAY_TIMEOUT', 900),
        'verify_tls' => (bool) env('MCP_GATEWAY_VERIFY_TLS', true),
        // Signature freshness the starters check (seconds)
        'signature_tolerance' => 300,
        // Call log retention (days); 0 keeps every call
        'calls_retention_days' => (int) env('MCP_GATEWAY_CALLS_RETENTION_DAYS', 30),
        // Path on the function the gateway forwards to, unless the server sets its own
        'default_path' => '/mcp',
    ],

];
