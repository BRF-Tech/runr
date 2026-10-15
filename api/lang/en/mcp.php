<?php

return [
    'server_not_found' => 'This project is not served as an MCP server.',
    'unsupported_type' => 'Only xaas, faas and caas projects can be served as MCP servers.',
    'http_trigger_required' => 'An MCP server needs the http trigger.',
    'key_rotated' => 'New gateway key. Deploy the project to give it to the server; the previous key keeps working for :days days.',
    'message_invalid' => 'The message must be a JSON-RPC 2.0 request or notification.',
    'message_too_large' => 'The message is too large.',
    'request_not_found' => 'Unknown authorization request.',
    'request_expired' => 'This authorization request has expired or was already answered. Start the connection again from the application.',
    'lifetime_invalid' => 'Choose one of the offered token lifetimes.',
    'switch_organization' => 'Switch to the organization of this MCP server first.',
    'call_not_permitted' => 'You may not call this MCP server (requires mcp.servers.call).',
    'client_not_connected' => 'This application holds no token of yours here.',
];
