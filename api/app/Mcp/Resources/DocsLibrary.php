<?php

namespace App\Mcp\Resources;

/**
 * The Markdown pages of the repository's docs/ directory, served as runr://docs/{page}:
 * config mcp.docs_path, else ../docs next to the API (repository checkout; Docker Compose mounts
 * it), else resources/docs (the API image — CI copies docs/ there before building it).
 */
final class DocsLibrary
{
    public const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,80}$/';

    public function path(): ?string
    {
        foreach ([(string) config('mcp.docs_path', ''), base_path('../docs'), resource_path('docs')] as $dir) {
            if ($dir !== '' && is_dir($dir)) {
                return rtrim($dir, '/\\');
            }
        }

        return null;
    }

    /** @return list<array{name: string, title: string, size: int}> */
    public function pages(): array
    {
        $dir = $this->path();
        if ($dir === null) {
            return [];
        }

        $pages = [];
        foreach (glob($dir.'/*.md') ?: [] as $file) {
            $name = basename($file, '.md');
            if (! preg_match(self::NAME_PATTERN, $name)) {
                continue;
            }
            $pages[] = ['name' => $name, 'title' => $this->title($file) ?? $name, 'size' => (int) filesize($file)];
        }
        usort($pages, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $pages;
    }

    public function read(string $name): ?string
    {
        $dir = $this->path();
        if ($dir === null || ! preg_match(self::NAME_PATTERN, $name)) {
            return null;
        }

        $file = $dir.'/'.$name.'.md';

        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** @return list<string> page names starting with $prefix */
    public function complete(string $prefix): array
    {
        return array_values(array_filter(
            array_column($this->pages(), 'name'),
            fn (string $name) => $prefix === '' || str_starts_with($name, strtolower($prefix)),
        ));
    }

    private function title(string $file): ?string
    {
        $handle = @fopen($file, 'r');
        if (! $handle) {
            return null;
        }
        try {
            for ($i = 0; $i < 10 && ($line = fgets($handle)) !== false; $i++) {
                if (preg_match('/^#\s+(.+)$/', trim($line), $m)) {
                    return trim($m[1]);
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
