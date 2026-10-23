<?php

namespace App\Triggers\Topics;

use App\Models\Project;
use App\Models\Topic;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Topic publishing from functions (docs/triggers.md#publishing): a project in a region where its
 * organization has topics gets RUNR_TOPICS_URL (the region's publisher, TopicPublisher) and
 * RUNR_TOPICS_TOKEN (its publish token, TopicTokens::forProject) — the token through the project's
 * secret env Secret (KnativeSecretEnvTrait, secretKeyRef), never as a value in the spec. The SDK
 * helpers (@runr/workflows `publish`, runr_workflows.publish) read both.
 *
 * Requires the consuming class to provide secretEnvName(Project).
 */
trait KnativeTopicsTrait
{
    /** @var array<string, bool> */
    private array $topicPublishers = [];

    /** Whether the project's organization has a topic in the project's region (and the region Redis). */
    private function publishesTopics(Project $project): bool
    {
        $region = $project->knativeRegion;
        if (! $region || ! $region->has_redis || $region->is_demo) {
            return false;
        }

        return $this->topicPublishers[$project->getKey()] ??= (function () use ($project) {
            try {
                return Schema::hasTable('topics') && Topic::query()->where('knative_region_id', $project->knative_region_id)->exists();
            } catch (\Throwable) {
                return false;
            }
        })();
    }

    /** @return array<string, string> entries of the project's secret env Secret */
    private function topicsSecretEnv(Project $project): array
    {
        if (! $this->publishesTopics($project)) {
            return [];
        }

        return ['RUNR_TOPICS_TOKEN' => TopicTokens::forProject($project->knativeRegion, (string) $project->getKey())];
    }

    private function withTopicsEnv(array $container, Project $project): array
    {
        if (! $this->publishesTopics($project)) {
            return $container;
        }

        $env = array_values(array_filter(
            $container['env'] ?? [],
            fn (array $entry) => ! in_array($entry['name'] ?? null, ['RUNR_TOPICS_URL', 'RUNR_TOPICS_TOKEN'], true),
        ));
        $env[] = ['name' => 'RUNR_TOPICS_URL', 'value' => TopicPublisher::url($project->knativeRegion)];
        $env[] = ['name' => 'RUNR_TOPICS_TOKEN', 'valueFrom' => ['secretKeyRef' => [
            'name' => $this->secretEnvName($project),
            'key' => 'RUNR_TOPICS_TOKEN',
        ]]];
        $container['env'] = $env;

        return $container;
    }

    /** The region's publisher is applied with the first deploy that hands out its URL. */
    private function ensureTopicPublisher(Project $project): void
    {
        if (! $this->publishesTopics($project)) {
            return;
        }

        try {
            app(TopicPublisher::class)->ensure($project->knativeRegion);
        } catch (\Throwable $e) {
            Log::warning('Topic publisher apply failed', ['project' => $project->slug, 'error' => $e->getMessage()]);
        }
    }
}
