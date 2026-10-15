<?php

namespace App\Services\Runtimes;

/**
 * Placeholders of runtime manifest strings (template files, dependency / build commands, run
 * command and env): {version}, {image}, {file}, {handler}, {Handler}, {mode}, {trigger} and
 * {semver} (the version when it is a plain X.Y or X.Y.Z number, else empty). A conditional
 * segment {if:NAME}...{end} is kept only when NAME is non-empty. Anything else in braces —
 * ${HOME}, a JSON object, {result} in a Python f-string — stays as written.
 */
final class Placeholders
{
    public const NAMES = ['version', 'image', 'file', 'handler', 'Handler', 'mode', 'trigger', 'semver'];

    /**
     * @param  array<string, string|null>  $values
     */
    public static function render(string $text, array $values): string
    {
        $values = self::withDerived($values);

        $text = preg_replace_callback(
            '/\{if:('.implode('|', self::NAMES).')\}(.*?)\{end\}/s',
            fn (array $m) => ($values[$m[1]] ?? '') !== '' ? $m[2] : '',
            $text,
        );

        return preg_replace_callback(
            '/\{('.implode('|', self::NAMES).')\}/',
            fn (array $m) => (string) ($values[$m[1]] ?? ''),
            $text,
        );
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private static function withDerived(array $values): array
    {
        $version = (string) ($values['version'] ?? '');

        $values['semver'] ??= preg_match('/^\d+\.\d+(\.\d+)?$/', $version) === 1 ? $version : '';
        if (! isset($values['Handler']) && isset($values['handler'])) {
            $values['Handler'] = ucfirst((string) $values['handler']);
        }

        return array_map(fn ($v) => (string) ($v ?? ''), $values);
    }
}
