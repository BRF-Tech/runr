<?php

namespace App\Support\Projects;

/**
 * The queue of what a deploy waits for: the Knative apply of a new deployment (ProcessDeployment)
 * and its readiness checks (CheckDeploymentReadyJob), until the deployment is active.
 *
 * Horizon's supervisor-1 works it next to `default` with a process pool of its own (balance
 * auto: at least one worker per queue, config/horizon.php). On `default` a deploy waited behind
 * whatever ran before it on the one worker an idle Horizon keeps: the teardown of the deployment
 * it replaced (queued first, each of its deletes a round trip to the region's API server), the
 * minute jobs, a long install. The readiness checks are delayed jobs, which Horizon's autoscaler
 * does not see waiting: a busy worker held them back until it was free.
 *
 * Without Redis as the queue (tests run sync) everything stays on the connection's default queue.
 */
final class DeployQueue
{
    public const NAME = 'deploys';

    /** The queue name to use: NAME on Redis (Horizon works it), null (the default queue) otherwise. */
    public static function name(): ?string
    {
        return config('queue.default') === 'redis' ? self::NAME : null;
    }
}
