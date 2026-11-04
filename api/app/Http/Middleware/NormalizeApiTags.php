<?php

namespace App\Http\Middleware;

use Dedoc\Scramble\Support\RouteInfo;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;

/**
 * The tags of the API reference: each operation is in one group (forRoute(), Scramble's tag
 * resolver), named from GROUPS. Used by AppServiceProvider (Scramble::resolveTagsUsing,
 * afterOpenApiGenerated) and App\Support\ApiDocument::finish().
 */
class NormalizeApiTags
{
    private static array $extraTags = [];

    /**
     * The groups of the API reference ("Parent > Child" for a part of a larger group). A module adds
     * its own with registerTags(); ApiDocsTest refuses an operation in any other.
     */
    public const GROUPS = [
        'Account', 'AI > GPU', 'AI > Hub', 'AI > Model services', 'AI > Models', 'API product',
        'Audit log', 'Auth', 'Bindings', 'Bot protection', 'Branding', 'Demo', 'Environments',
        'Gateways', 'Git', 'Global projects', 'Images', 'MCP', 'Notifications', 'Observability',
        'Organization transfer', 'Projects', 'Projects > Code', 'Projects > Deployments',
        'Projects > Exec', 'Regions', 'Regions > Demo', 'Regions > Install', 'Regions > Redis',
        'Regions > Registry', 'Regions > Runtime isolation', 'Regions > Workers',
        'Roles & Permissions', 'Runtimes', 'Sandboxes', 'Sandboxes > Commands', 'Sandboxes > Files',
        'Sandboxes > Processes', 'Sandboxes > Settings', 'Secrets', 'Settings', 'Supply chain',
        'Support', 'Triggers', 'Users', 'Workflows',
    ];

    /**
     * The group of a controller (its class name without "Controller") or of a controller's
     * `@tags`; a key ending in "\" is a namespace (registerTags of a module)
     */
    private const TAG_MAP = [
        // Account
        'Account' => 'Account',
        'ApiToken' => 'Account',
        'Session' => 'Account',
        'TwoFactor' => 'Account',
        // Auth
        'Auth' => 'Auth',
        'Signup' => 'Auth',
        'Broadcast' => 'Auth',
        'AuditEvent' => 'Audit log',
        // AI
        'ModelServiceSecurity' => 'AI > Model services',
        // Projects
        'Project' => 'Projects',
        'ProjectOwnership' => 'Projects',
        'ProjectStop' => 'Projects',
        'Rightsizing' => 'Projects',
        'ScaleSchedule' => 'Projects',
        'Code' => 'Projects > Code',
        'Deployment' => 'Projects > Deployments',
        'DeploymentRollout' => 'Projects > Deployments',
        'DeployStrategy' => 'Projects > Deployments',
        'Exec' => 'Projects > Exec',
        'GitProject' => 'Git',
        'GitPreview' => 'Git',
        'GitProvider' => 'Git',
        'GitCallback' => 'Git',
        'GitWebhook' => 'Git',
        'GlobalProject' => 'Global projects',
        'GlobalDns' => 'Global projects',
        'PrivateDatabaseBinding' => 'Bindings',
        'ProjectSecret' => 'Secrets',
        'SecretStore' => 'Secrets',
        'ProjectSecurity' => 'Supply chain',
        'RegionSupplyChain' => 'Supply chain',
        'Runtime' => 'Runtimes',
        'RuntimePatch' => 'Runtimes',
        'RuntimePatchAdmin' => 'Runtimes',
        'ProjectRuntimePatch' => 'Runtimes',
        'ProjectTrigger' => 'Triggers',
        'Topic' => 'Triggers',
        'TriggerEvent' => 'Triggers',
        'WebhookInbox' => 'Triggers',
        'Workflow' => 'Workflows',
        'WorkflowEvent' => 'Workflows',
        'WorkflowRun' => 'Workflows',
        'WorkflowUsage' => 'Workflows',
        'HistoricalMetrics' => 'Observability',
        // MCP server
        'HostedServer' => 'MCP',
        'McpSettings' => 'MCP',
        'OAuthConsent' => 'MCP',
        // Regions
        'KnativeRegion' => 'Regions',
        'Knative Regions' => 'Regions',
        'RegionMigrate' => 'Regions',
        'Residency' => 'Regions',
        'KnativeInstall' => 'Regions > Install',
        'Knative Install' => 'Regions > Install',
        'KnativeUninstall' => 'Regions > Install',
        'Knative Uninstall' => 'Regions > Install',
        'KnativeNode' => 'Regions > Workers',
        'Knative Nodes' => 'Regions > Workers',
        'KnativeRedis' => 'Regions > Redis',
        'Knative Redis' => 'Regions > Redis',
        'Sandbox' => 'Regions > Runtime isolation',
        'Sandbox (Runtime Isolation)' => 'Regions > Runtime isolation',
        'Registry' => 'Regions > Registry',
        'RegistrySetup' => 'Regions > Registry',
        'RegionDemo' => 'Regions > Demo',
        // Gateways
        'Gateway' => 'Gateways',
        // Notifications
        'Notification' => 'Notifications',
        'NotificationPreference' => 'Notifications',
        // Settings & Users
        'Settings' => 'Settings',
        'HorizonLink' => 'Settings',
        'User' => 'Users',
        'Branding' => 'Branding',
        'Demo' => 'Demo',
        'Contact' => 'Support',
    ];

    public static function registerTags(array $tags): void
    {
        self::$extraTags = array_merge(self::$extraTags, $tags);
    }

    private static function tagMap(): array
    {
        return array_merge(self::TAG_MAP, self::$extraTags);
    }

    /** GROUPS and the groups the modules registered */
    public static function groups(): array
    {
        return array_values(array_unique([...self::GROUPS, ...array_values(self::$extraTags)]));
    }

    /** Scramble's tag resolver (Scramble::resolveTagsUsing) */
    public static function forRoute(RouteInfo $routeInfo): array
    {
        return self::forController((string) $routeInfo->className(), $routeInfo->methodName());
    }

    /**
     * The group of a controller action: the `@tags` line of the class that declares the method
     * (only that line: the text below it describes the controller), else the controller's
     * namespace or class name, each through the map. Scramble's own resolver added the class
     * name to every `@tags`, so an operation was in two groups, and read a description below
     * `@tags` as more comma-separated tags.
     *
     * @return list<string>
     */
    public static function forController(string $class, ?string $method = null): array
    {
        $class = ltrim($class, '\\');
        try {
            $declaring = $method !== null && $method !== ''
                ? (new ReflectionMethod($class, $method))->getDeclaringClass()
                : new ReflectionClass($class);
            $doc = (string) $declaring->getDocComment();
        } catch (ReflectionException) {
            $doc = '';
        }

        $names = preg_match('/^[ \t]*\*[ \t]*@tags[ \t]+(\S.*?)[ \t]*$/m', $doc, $match)
            ? array_map('trim', explode(',', $match[1]))
            : [self::classGroup($class)];

        $map = self::tagMap();

        return array_values(array_unique(array_map(fn (string $name) => $map[$name] ?? $name, array_filter($names))));
    }

    /** A namespace's group (the longest registered one), else the class name without "Controller" */
    private static function classGroup(string $class): string
    {
        $group = null;
        $matched = 0;
        foreach (self::tagMap() as $key => $value) {
            if (str_ends_with($key, '\\') && str_starts_with($class, $key) && strlen($key) > $matched) {
                [$group, $matched] = [$value, strlen($key)];
            }
        }

        return $group ?? str_replace('Controller', '', class_basename($class));
    }

    /**
     * Normalize tags on a Scramble Operation object (used in afterOpenApiGenerated).
     */
    public static function normalizeOperationTags(object $operation): void
    {
        if (empty($operation->tags)) {
            return;
        }

        $mapped = [];
        foreach ($operation->tags as $tag) {
            $tagName = is_object($tag) ? $tag->name : $tag;
            $group = self::tagMap()[$tagName] ?? $tagName;
            if (! in_array($group, $mapped)) {
                $mapped[] = $group;
            }
        }

        $operation->tags = $mapped;
    }

    /**
     * Normalize tags on a serialized spec array (ApiDocument::finish).
     */
    public static function normalizeSpecArray(array &$spec): void
    {
        if (empty($spec['paths'])) {
            return;
        }

        $usedTags = [];

        foreach ($spec['paths'] as &$pathItem) {
            foreach ($pathItem as &$operation) {
                if (! is_array($operation) || empty($operation['tags'])) {
                    continue;
                }

                $mapped = [];
                foreach ($operation['tags'] as $tag) {
                    $group = self::tagMap()[$tag] ?? $tag;
                    if (! in_array($group, $mapped)) {
                        $mapped[] = $group;
                    }
                }
                $operation['tags'] = $mapped;

                foreach ($mapped as $t) {
                    $usedTags[$t] = true;
                }
            }
        }

        // In the reference's order: alphabetical, "Account" before "AI > GPU"
        $tagNames = array_keys($usedTags);
        sort($tagNames, SORT_STRING | SORT_FLAG_CASE);
        $spec['tags'] = array_map(
            fn ($name) => ['name' => $name],
            $tagNames,
        );

        // x-tagGroups disabled — causes infinite recursion in Scalar standalone bundle
        // $spec['x-tagGroups'] = self::buildTagGroups(array_keys($usedTags));
    }

    /**
     * Build x-tagGroups from used tags for nested sidebar navigation (Scalar).
     * Groups are derived from the "Parent > Child" separator convention.
     */
    public static function buildTagGroups(array $usedTags): array
    {
        $groups = [];
        foreach ($usedTags as $tag) {
            $parent = explode(' > ', $tag)[0];
            $groups[$parent][] = $tag;
        }

        return array_map(
            fn ($name, $tags) => ['name' => $name, 'tags' => $tags],
            array_keys($groups),
            array_values($groups),
        );
    }
}
