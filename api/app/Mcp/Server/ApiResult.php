<?php

namespace App\Mcp\Server;

use App\Mcp\Tools\ToolResult;

/**
 * The answer of one internal API call (InternalApi): status, raw body and decoded JSON.
 */
final class ApiResult
{
    /**
     * @param  array<string, string>  $headers  lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly ?array $json,
        public readonly array $headers = [],
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** The resource payload: JSON "data" when the answer wraps one, else the whole JSON. */
    public function data(): mixed
    {
        if (is_array($this->json) && array_key_exists('data', $this->json)) {
            return $this->json['data'];
        }

        return $this->json;
    }

    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->json, $path, $default);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function message(): string
    {
        $message = is_array($this->json) ? ($this->json['message'] ?? null) : null;
        if (is_string($message) && $message !== '') {
            return $message;
        }

        return match (true) {
            $this->status === 401 => 'Not signed in.',
            $this->status === 403 => 'Not permitted.',
            $this->status === 404 => 'Not found.',
            $this->status === 409 => 'Conflict.',
            $this->status === 422 => 'The input is not valid.',
            $this->status === 429 => 'Too many requests.',
            $this->status >= 500 => 'The server could not complete the request.',
            default => 'Request failed (HTTP '.$this->status.').',
        };
    }

    /** @return array<string, list<string>> validation errors by field */
    public function errors(): array
    {
        $errors = is_array($this->json) ? ($this->json['errors'] ?? []) : [];
        if (! is_array($errors)) {
            return [];
        }

        $out = [];
        foreach ($errors as $field => $messages) {
            $out[(string) $field] = array_values(array_map('strval', (array) $messages));
        }

        return $out;
    }

    /** The message and every validation error, one per line. */
    public function errorText(): string
    {
        $lines = [$this->message()];
        foreach ($this->errors() as $field => $messages) {
            foreach ($messages as $message) {
                if ($message !== $this->message()) {
                    $lines[] = "- {$field}: {$message}";
                }
            }
        }

        return implode("\n", array_unique($lines));
    }

    /** A failed call as a tool error the model can act on. */
    public function toToolError(?string $context = null): ToolResult
    {
        $details = ['status' => $this->status, 'message' => $this->message()];
        if ($errors = $this->errors()) {
            $details['errors'] = $errors;
        }
        $permissions = is_array($this->json) ? ($this->json['permissions'] ?? null) : null;
        if (is_array($permissions) && $permissions !== []) {
            $details['permissions'] = array_values($permissions);
        }

        return ToolResult::error(($context ? $context.': ' : '').$this->errorText(), $details);
    }
}
