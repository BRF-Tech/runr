<?php
/**
 * RunR PHP HTTP Runtime — wraps user handler as HTTP server.
 *
 * Runs on PHP's built-in stream socket server: calls the handler function on every request.
 */

$codeDir     = '/app/code';
$handlerName = getenv('RUNR_HANDLER') ?: 'handler';
$filename    = getenv('RUNR_FILENAME') ?: 'main.php';

require_once $codeDir . '/' . $filename;

if (!function_exists($handlerName)) {
    fwrite(STDERR, "Error: handler '{$handlerName}' not found in {$filename}\n");
    exit(1);
}

$port = (int) (getenv('PORT') ?: 8080);

// TCP socket server
$server = stream_socket_server("tcp://0.0.0.0:{$port}", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "Error: could not start server — {$errstr} ({$errno})\n");
    exit(1);
}

echo "RunR PHP runtime listening on :{$port}\n";

while ($conn = stream_socket_accept($server, -1)) {
    $rawRequest = '';
    while ($line = fgets($conn)) {
        $rawRequest .= $line;
        if (trim($line) === '') break;
    }

    // Parse the method and path
    $firstLine = strtok($rawRequest, "\r\n");
    [$method, $path] = array_pad(explode(' ', $firstLine, 3), 3, '');

    // Parse the headers
    $headers = [];
    $headerLines = explode("\r\n", $rawRequest);
    array_shift($headerLines); // first line (request line)
    foreach ($headerLines as $hl) {
        if (str_contains($hl, ':')) {
            [$k, $v] = explode(':', $hl, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
    }

    // Read the body
    $body = '';
    $contentLength = (int) ($headers['content-length'] ?? 0);
    if ($contentLength > 0) {
        $body = fread($conn, $contentLength);
    }

    // Knative probe — do not pass it to the handler
    if (isset($headers['k-network-probe']) || isset($headers['k-kubelet-probe'])) {
        $resp = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok";
        fwrite($conn, $resp);
        fclose($conn);
        continue;
    }

    // CORS preflight
    if ($method === 'OPTIONS') {
        $resp = "HTTP/1.1 204 No Content\r\n"
            . "Access-Control-Allow-Origin: *\r\n"
            . "Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS\r\n"
            . "Access-Control-Allow-Headers: Content-Type, Authorization\r\n"
            . "Access-Control-Max-Age: 86400\r\n"
            . "Connection: close\r\n\r\n";
        fwrite($conn, $resp);
        fclose($conn);
        echo "[runr] OPTIONS {$path} 204\n";
        continue;
    }

    // Build the event
    $event = [];
    if ($body) {
        $decoded = json_decode($body, true);
        $event = is_array($decoded) ? $decoded : ['body' => $body];
    }

    $context = [
        'method'  => $method,
        'path'    => $path,
        'headers' => $headers,
    ];

    try {
        $result = $handlerName($event, $context);

        $statusCode  = 200;
        $respBody    = $result;
        $respHeaders = ['Access-Control-Allow-Origin' => '*', 'Content-Type' => 'application/json'];

        if (is_array($result) && isset($result['statusCode'])) {
            $statusCode = $result['statusCode'];
            $respBody   = $result['body'] ?? $result;
            if (isset($result['headers']) && is_array($result['headers'])) {
                // Case-insensitive header merge: user headers override defaults
                foreach ($result['headers'] as $k => $v) {
                    foreach ($respHeaders as $dk => $dv) {
                        if (strtolower($dk) === strtolower($k)) {
                            unset($respHeaders[$dk]);
                        }
                    }
                    $respHeaders[$k] = $v;
                }
            }
        }

        $ct = '';
        foreach ($respHeaders as $k => $v) {
            if (strtolower($k) === 'content-type') { $ct = $v; break; }
        }
        $ct = $ct ?: 'application/json';
        $output = (is_string($respBody) && !str_contains($ct, 'application/json'))
            ? $respBody
            : json_encode($respBody, JSON_UNESCAPED_UNICODE);

        $headerLines = '';
        foreach ($respHeaders as $k => $v) {
            $headerLines .= "{$k}: {$v}\r\n";
        }

        $response = "HTTP/1.1 {$statusCode} OK\r\n"
            . $headerLines
            . "Content-Length: " . strlen($output) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $output;
    } catch (\Throwable $e) {
        $errorJson = json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        $response = "HTTP/1.1 500 Internal Server Error\r\n"
            . "Content-Type: application/json\r\n"
            . "Content-Length: " . strlen($errorJson) . "\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $errorJson;
        fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    }

    fwrite($conn, $response);
    fclose($conn);

    echo "[runr] {$method} {$path} " . ($statusCode ?? 500) . "\n";
}
