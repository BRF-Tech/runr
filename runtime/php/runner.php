<?php
/**
 * RunR PHP Schedule Runtime — calls handler directly and exits.
 */

$codeDir     = '/app/code';
$handlerName = getenv('RUNR_HANDLER') ?: 'handler';
$filename    = getenv('RUNR_FILENAME') ?: 'main.php';

require_once $codeDir . '/' . $filename;

if (!function_exists($handlerName)) {
    fwrite(STDERR, "Error: handler '{$handlerName}' not found in {$filename}\n");
    exit(1);
}

$event   = ['trigger' => 'schedule'];
$context = ['mode' => 'schedule'];

try {
    $result = $handlerName($event, $context);
    $output = is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : (string) $result;
    echo $output . "\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "Error: " . $e->getMessage() . "\n");
    exit(1);
}
