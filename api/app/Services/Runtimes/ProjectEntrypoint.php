<?php

namespace App\Services\Runtimes;

use App\Models\Project;

/**
 * The handler file and function of a code project — one rule for the editor, saving, building
 * and deploying:
 *  - XaaS: config.filename (default <handler.file>.<ext>), function config.handler (default the
 *    manifest's handler.function);
 *  - FaaS / AgentFlow: config.handler "file.function" (default "<handler.file>.<handler.function>"),
 *    file = the part before the dot + .<ext>; a handler without a dot names the function and the
 *    file is the default one.
 */
final class ProjectEntrypoint
{
    public function __construct(
        public readonly string $filename,
        public readonly string $handler,
    ) {}

    public static function for(Project $project, RuntimeManifest $manifest, ?object $config = null): self
    {
        $config ??= match ($project->service_type) {
            'xaas' => $project->xaasConfig,
            'faas' => $project->faasConfig,
            'agentflow' => $project->agentFlowConfig,
            'aaas' => $project->aaasConfig,
            default => null,
        };

        if (in_array($project->service_type, ['xaas', 'aaas'], true)) {
            return new self(
                $config?->filename ?: $manifest->defaultFilename(),
                $config?->handler ?: $manifest->handlerFunction(),
            );
        }

        return self::fromHandlerString($config?->handler ?: self::defaultHandlerString($manifest), $manifest);
    }

    /** "main.handler" → main.<ext> + handler. */
    public static function fromHandlerString(string $handler, RuntimeManifest $manifest): self
    {
        $parts = explode('.', $handler);

        if (count($parts) >= 2 && $parts[0] !== '') {
            return new self("{$parts[0]}.{$manifest->extension()}", $parts[1] !== '' ? $parts[1] : $manifest->handlerFunction());
        }

        return new self($manifest->defaultFilename(), $parts[0] !== '' ? $parts[0] : $manifest->handlerFunction());
    }

    /** FaaS handler string of a new project, e.g. "main.handler". */
    public static function defaultHandlerString(RuntimeManifest $manifest): string
    {
        return $manifest->handlerFile().'.'.$manifest->handlerFunction();
    }
}
