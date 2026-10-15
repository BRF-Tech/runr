<?php

namespace App\Mcp\Tools;

/**
 * The result of a tools/call: concise structured JSON (structuredContent) plus a short text for
 * models and clients that read only the text. A failed call is a result with isError — the
 * model sees what went wrong and can correct the call (MCP: tool execution errors).
 */
final class ToolResult
{
    private function __construct(
        public readonly mixed $structured,
        public readonly string $text,
        public readonly bool $isError = false,
    ) {}

    /**
     * @param  array<array-key, mixed>|object  $structured
     * @param  string|null  $summary  one line for the text content; the JSON follows it
     */
    public static function ok(array|object $structured, ?string $summary = null): self
    {
        return new self(self::object($structured), self::text($summary, $structured));
    }

    public static function error(string $message, array $details = []): self
    {
        $structured = ['error' => array_merge(['message' => $message], $details)];

        return new self($structured, $message, true);
    }

    /** MCP CallToolResult. structuredContent exists from protocol revision 2025-06-18. */
    public function toArray(string $protocolVersion): array
    {
        $result = [
            'content' => [['type' => 'text', 'text' => $this->text]],
        ];
        if ($protocolVersion >= '2025-06-18') {
            $result['structuredContent'] = $this->structured;
        }
        if ($this->isError) {
            $result['isError'] = true;
        }

        return $result;
    }

    /** structuredContent must be a JSON object: lists are wrapped as {"items": [...]}. */
    private static function object(array|object $value): array|object
    {
        if (is_object($value)) {
            return $value;
        }
        if ($value === []) {
            return new \stdClass;
        }

        return array_is_list($value) ? ['items' => $value] : $value;
    }

    private static function text(?string $summary, array|object $structured): string
    {
        $json = json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $summary === null || $summary === '' ? (string) $json : $summary."\n\n".$json;
    }
}
