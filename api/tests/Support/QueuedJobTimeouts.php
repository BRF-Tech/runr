<?php

namespace Tests\Support;

use App\Jobs\Concerns\RunsOnLongQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Every queued class of the API — jobs and queued listeners under app/ and the modules, in
 * subdirectories too — with its own $timeout and the queue connection it runs on in production
 * (Redis: `redis`, or `redis-long` for RunsOnLongQueue), and Horizon's supervisors of each
 * connection. HorizonTimeoutsTest and ProjectTeardownTest check them against each other.
 */
final class QueuedJobTimeouts
{
    /** @return list<string> */
    private static function directories(): array
    {
        $dirs = [app_path('Jobs'), app_path('Listeners'), app_path('Workflows/Jobs')];
        foreach (['Jobs', 'Listeners'] as $kind) {
            foreach (glob(base_path("Modules/*/app/{$kind}"), GLOB_ONLYDIR) ?: [] as $dir) {
                $dirs[] = $dir;
            }
        }

        return array_values(array_filter($dirs, 'is_dir'));
    }

    /**
     * Every queued class: its own timeout (null: its supervisor's; a string when the check cannot
     * read it) and its connection.
     *
     * @return array<class-string, array{timeout: int|string|null, connection: string}>
     */
    public static function jobs(): array
    {
        $jobs = [];
        foreach (Finder::create()->files()->in(self::directories())->name('*.php') as $file) {
            $source = $file->getContents();
            if (! preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
                || ! preg_match('/^(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)/m', $source, $name)) {
                continue;
            }
            $class = $namespace[1].'\\'.$name[1];
            if (! class_exists($class)) {
                continue;
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }
            $jobs[$class] = ['timeout' => self::timeout($reflection, $source), 'connection' => self::connection($class)];
        }
        ksort($jobs);

        return $jobs;
    }

    /**
     * The class's own $timeout: its default, or what its constructor sets from the configuration
     * ("$this->timeout = (int) config('key', …) [+ n];"); null without a $timeout of its own.
     */
    private static function timeout(ReflectionClass $reflection, string $source): int|string|null
    {
        if (! $reflection->hasProperty('timeout')) {
            return null;
        }
        $default = $reflection->getDefaultProperties()['timeout'] ?? null;
        if (is_int($default)) {
            return $default;
        }
        if (preg_match('/\$this->timeout\s*=\s*\(int\)\s*config\(\'([^\']+)\'[^)]*\)\s*(?:\+\s*(\d+))?\s*;/', $source, $m)) {
            return (int) config($m[1]) + (int) ($m[2] ?? 0);
        }

        return "{$reflection->getName()}: a \$timeout the check cannot read (declare it as a default or set it from config('…'))";
    }

    private static function connection(string $class): string
    {
        return in_array(RunsOnLongQueue::class, class_uses_recursive($class), true) ? 'redis-long' : 'redis';
    }

    /**
     * Horizon's supervisors of a connection, in every environment: "env.name" => timeout.
     *
     * @return array<string, int>
     */
    public static function supervisors(string $connection): array
    {
        $defaults = (array) config('horizon.defaults');
        $plans = ['defaults' => $defaults];
        foreach ((array) config('horizon.environments') as $env => $supervisors) {
            // Horizon's ProvisioningPlan: every environment gets the defaults, replaced by its own
            $plans[$env] = array_replace_recursive($defaults, (array) $supervisors);
        }

        $out = [];
        foreach ($plans as $env => $supervisors) {
            foreach ((array) $supervisors as $name => $supervisor) {
                if (($supervisor['connection'] ?? 'redis') === $connection) {
                    $out["{$env}.{$name}"] = (int) ($supervisor['timeout'] ?? 60);
                }
            }
        }

        return $out;
    }

    public static function retryAfter(string $connection): int
    {
        return (int) config("queue.connections.{$connection}.retry_after");
    }
}
