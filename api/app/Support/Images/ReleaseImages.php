<?php

namespace App\Support\Images;

/**
 * RunR's images that the regions run and every release builds and publishes at its own version,
 * like the API and UI images (the chart's appVersion): the region agent — also the internal
 * network's authz, the agent in authz mode — and the event gateway / queue consumer, which also
 * publishes topics and drops cdc slots. The version is the API's: APP_VERSION, which the API image
 * bakes in (v<version>) and the Helm chart sets from its appVersion — so a 0.6.0 installation runs
 * the 0.6.0 agent and gateway, and `helm rollback` takes them back with the API. A build that is no
 * release (APP_VERSION dev, main-<hash>) runs :latest.
 *
 * region:reconcile (ImageRegistryStep) moves the workloads that run another tag of them — :latest
 * of a release before 0.6.0, the previous release's version — to the configured references
 * (config/knative.php, RUNR_AGENT_IMAGE, RUNR_INTERNAL_AUTHZ_IMAGE, RUNR_QUEUE_CONSUMER_IMAGE).
 * The publish list of a release: runtime/ci.sh release-images.
 */
final class ReleaseImages
{
    /** The images, by name under RegistryMove::TO */
    public const NAMES = ['agent', 'queue-consumer'];

    /**
     * An image of NAMES under RegistryMove::TO, behind any registry prefix (the region registry's
     * pull-through path {registry}/ghcr.io/brf-tech/runr/…), with its tag and digest.
     */
    private const IMAGE = '~^(?<prefix>(?:.+/)?)ghcr\.io/brf-tech/runr/(?<name>agent|queue-consumer)(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?$~';

    /**
     * The release tag of an APP_VERSION (v0.6.0 → 0.6.0); latest for anything that is no release.
     * config/ passes env('APP_VERSION'); elsewhere it is config('app.version').
     */
    public static function tag(?string $appVersion = null): string
    {
        $version = trim((string) ($appVersion ?? config('app.version', '')));

        return preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?)$/', $version, $m) === 1 ? $m[1] : 'latest';
    }

    /** ghcr.io/brf-tech/runr/<name>:<the release tag> */
    public static function ref(string $name, ?string $appVersion = null): string
    {
        return RegistryMove::TO."/{$name}:".self::tag($appVersion);
    }

    /**
     * The references config gives each image (the first is where another tag moves to). An image
     * whose configured reference is in another registry (an operator's mirror) is not moved.
     *
     * @return array<string, list<string>>
     */
    public static function configured(): array
    {
        return [
            'agent' => array_values(array_unique([(string) config('knative.agent_image'), (string) config('knative.internal_network.authz_image')])),
            'queue-consumer' => [(string) config('knative.queue_consumer_image')],
        ];
    }

    /**
     * An image of NAMES at a reference config does not give it → the configured one, behind the
     * same registry prefix; anything else as it is.
     */
    public static function moved(string $image): string
    {
        if (! preg_match(self::IMAGE, $image, $m)) {
            return $image;
        }
        $wanted = self::configured()[$m['name']] ?? [];
        $target = $wanted[0] ?? '';
        if (! str_starts_with($target, RegistryMove::TO."/{$m['name']}:") || in_array(substr($image, strlen($m['prefix'])), $wanted, true)) {
            return $image;
        }

        return $m['prefix'].$target;
    }
}
