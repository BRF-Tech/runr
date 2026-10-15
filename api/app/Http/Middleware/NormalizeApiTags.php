<?php

namespace App\Http\Middleware;

/**
 * Tag normalization mapping for API docs.
 * Used by both Scramble's afterOpenApiGenerated callback and GroupApiSchemas middleware.
 */
class NormalizeApiTags
{
    private static array $extraTags = [];

    private const TAG_MAP = [
        // Auth
        'Auth' => 'Auth',
        // Projects
        'Project' => 'Projects',
        'Code' => 'Projects > Code',
        'Deployment' => 'Projects > Deployments',
        'Exec' => 'Projects > Exec',
        // Regions
        'KnativeRegion' => 'Regions',
        'Knative Regions' => 'Regions',
        'KnativeInstall' => 'Regions > Install',
        'Knative Install' => 'Regions > Install',
        'KnativeUninstall' => 'Regions > Install',
        'Knative Uninstall' => 'Regions > Install',
        'KnativeNode' => 'Regions > Workers',
        'Knative Nodes' => 'Regions > Workers',
        'KnativeRedis' => 'Regions > Redis',
        'Knative Redis' => 'Regions > Redis',
        'Sandbox' => 'Regions > Sandbox',
        'Sandbox (Runtime Isolation)' => 'Regions > Sandbox',
        'Registry' => 'Regions > Registry',
        'RegistrySetup' => 'Regions > Registry',
        'RegionDemo' => 'Regions > Demo',
        // Gateways
        'Gateway' => 'Gateways',
        // Notifications
        'Notification' => 'Notifications',
        // Settings & Users
        'Settings' => 'Settings',
        'User' => 'Users',
        'Demo' => 'Demo',
    ];

    public static function registerTags(array $tags): void
    {
        self::$extraTags = array_merge(self::$extraTags, $tags);
    }

    private static function tagMap(): array
    {
        return array_merge(self::TAG_MAP, self::$extraTags);
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
     * Normalize tags on a serialized spec array (used in middleware).
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

        $tagNames = array_keys($usedTags);
        sort($tagNames);
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
