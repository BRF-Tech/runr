<?php

namespace App\Mcp\Tools;

use App\Support\Rbac\Rbac;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The tool catalog of the RunR MCP server: every Tool class under app/Mcp/Tools/Catalog (one
 * class per tool), plus classes other features add with ToolRegistry::extend(). tools/list shows
 * a caller the tools whose permissions() the caller holds — the API token's scope included.
 */
final class ToolRegistry
{
    /** Order of the groups in tools/list and the catalog page. */
    public const GROUPS = [
        'account', 'projects', 'code', 'git', 'deployments', 'operations', 'observability',
        'configuration', 'domains', 'regions', 'runtimes', 'sandboxes', 'workflows', 'bindings',
        'models', 'security', 'audit', 'mcp',
    ];

    /** @var list<class-string<Tool>> */
    private static array $extra = [];

    /** @var array<string, Tool>|null */
    private static ?array $tools = null;

    /** Adds tool classes (features outside app/Mcp/Tools/Catalog). */
    public static function extend(string ...$classes): void
    {
        foreach ($classes as $class) {
            if (! in_array($class, self::$extra, true)) {
                self::$extra[] = $class;
            }
        }
        self::$tools = null;
    }

    public static function flush(): void
    {
        self::$tools = null;
    }

    /** @return array<string, Tool> name => tool, in catalog order */
    public function all(): array
    {
        if (self::$tools !== null) {
            return self::$tools;
        }

        $tools = [];
        foreach (array_merge(self::discover(), self::$extra) as $class) {
            if (! class_exists($class) || ! is_subclass_of($class, Tool::class) || (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            /** @var Tool $tool */
            $tool = app($class);
            $tools[$tool->name()] = $tool;
        }

        $order = array_flip(self::GROUPS);
        uasort($tools, fn (Tool $a, Tool $b) => [$order[$a->group()] ?? PHP_INT_MAX, $a->name()] <=> [$order[$b->group()] ?? PHP_INT_MAX, $b->name()]);

        return self::$tools = $tools;
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /** @return array<string, Tool> the tools $user (with the request's token) may see and call */
    public function visibleFor(Authenticatable $user): array
    {
        return array_filter($this->all(), fn (Tool $tool) => $this->permits($tool, $user));
    }

    public function permits(Tool $tool, Authenticatable $user): bool
    {
        $rbac = app(Rbac::class);

        foreach ($tool->permissions() as $requirement) {
            $any = array_filter(explode('|', $requirement));
            $held = false;
            foreach ($any as $permission) {
                if ($rbac->allowsAnywhere($user, $permission)) {
                    $held = true;
                    break;
                }
            }
            if (! $held) {
                return false;
            }
        }

        return true;
    }

    /** @return list<class-string<Tool>> */
    private static function discover(): array
    {
        $dir = __DIR__.'/Catalog';
        $classes = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($dir) + 1, -4);
            $classes[] = __NAMESPACE__.'\\Catalog\\'.str_replace(['/', '\\'], '\\', $relative);
        }
        sort($classes);

        return $classes;
    }
}
