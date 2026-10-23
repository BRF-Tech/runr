<?php

namespace App\Providers;

use App\Console\Commands\TriggersPruneCommand;
use App\Models\ContainerConfig;
use App\Models\Deployment;
use App\Models\FaasConfig;
use App\Models\Project;
use App\Models\XaasConfig;
use App\Triggers\Cleanup\CdcSlotCleanup;
use App\Triggers\PlatformEvents;
use App\Triggers\ProjectTriggers;
use App\Triggers\TriggerTypes;
use App\Triggers\Types\HttpTrigger;
use App\Triggers\Types\PlatformEventTrigger;
use App\Triggers\Types\QueueTrigger;
use App\Triggers\Types\ScheduleTrigger;
use App\Triggers\Types\Sources\CdcTrigger;
use App\Triggers\Types\Sources\EmailTrigger;
use App\Triggers\Types\Sources\KafkaTrigger;
use App\Triggers\Types\Sources\MqttTrigger;
use App\Triggers\Types\Sources\NatsTrigger;
use App\Triggers\Types\Sources\RabbitMqTrigger;
use App\Triggers\Types\Sources\S3Trigger;
use App\Triggers\Types\TopicTrigger;
use App\Triggers\Types\WebhookTrigger;
use App\Triggers\Types\WorkflowTrigger;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Triggers (docs/triggers.md): the registry of trigger types, the routes (routes/triggers.php),
 * the webhook inbox rate limiter, the config row → primary trigger bridge, the platform events of
 * deployments and the daily pruning of stored events.
 *
 * A new trigger type is one TriggerType subclass registered in registerTypes() (or from another
 * provider: app(TriggerTypes::class)->register(...)) plus its Go source in
 * runtime/queue-consumer/sources.
 */
class TriggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TriggerTypes::class, function () {
            $types = new TriggerTypes;
            foreach ([
                new HttpTrigger,
                new ScheduleTrigger,
                new QueueTrigger,
                new WorkflowTrigger,
                new WebhookTrigger,
                new TopicTrigger,
                new PlatformEventTrigger,
                // Broker and external sources (docs/triggers.md#broker-and-external-sources)
                new KafkaTrigger,
                new NatsTrigger,
                new RabbitMqTrigger,
                new MqttTrigger,
                new CdcTrigger,
                new S3Trigger,
                new EmailTrigger,
            ] as $type) {
                $types->register($type);
            }

            return $types;
        });
        $this->app->singleton(ProjectTriggers::class);
        // per request: the cleanups a trigger change scheduled, for the API's answer
        $this->app->scoped(CdcSlotCleanup::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(base_path('routes/triggers.php'));

        // Webhook senders: per inbox key, generous — a sender's burst is stored, not refused
        RateLimiter::for('webhook-inbox', fn (Request $request) => Limit::perMinute(1200)->by('hook:'.(string) $request->route('key')));
        // Event gateways: claim / ack per project
        RateLimiter::for('trigger-inbox', fn (Request $request) => Limit::perMinute(1200)->by('inbox:'.(string) $request->route('projectId')));

        $this->registerLegacySync();
        $this->registerDeploymentEvents();
        $this->registerSchedule();

        if ($this->app->runningInConsole()) {
            $this->commands([TriggersPruneCommand::class]);
        }
    }

    /** A config row's trigger_type / schedule / queue_stream sets the project's primary trigger. */
    private function registerLegacySync(): void
    {
        foreach ([XaasConfig::class, FaasConfig::class, ContainerConfig::class] as $model) {
            $model::saved(fn ($config) => app(ProjectTriggers::class)->syncFromConfig($config));
        }
    }

    /**
     * deployment.finished / deployment.failed: when a deployment becomes active or fails, after
     * the transaction that changed it commits (a rolled-back activation raises nothing).
     */
    private function registerDeploymentEvents(): void
    {
        Deployment::updated(function (Deployment $deployment) {
            if (! $deployment->wasChanged('status') || ! in_array($deployment->status, ['active', 'failed'], true)) {
                return;
            }

            $previous = (string) $deployment->getOriginal('status');
            $connection = DB::connection($deployment->getConnectionName());
            $connection->afterCommit(function () use ($deployment, $previous) {
                try {
                    $project = Project::query()->find($deployment->project_id);
                    if (! $project || ! app(ProjectTriggers::class)->tableExists($project)) {
                        return;
                    }

                    $event = $deployment->status === 'active' ? PlatformEvents::DEPLOYMENT_FINISHED : PlatformEvents::DEPLOYMENT_FAILED;
                    app(PlatformEvents::class)->dispatch($event, [
                        'deployment_id' => $deployment->id,
                        'project_id' => $project->id,
                        'project_name' => $project->name,
                        'version' => $deployment->version()->value('number'),
                        'status' => $deployment->status,
                        'previous_status' => $previous,
                        'message' => $deployment->knative_message,
                    ], $project->id, $deployment->id.':'.$deployment->status);
                } catch (\Throwable $e) {
                    Log::warning('Deployment platform event failed', ['deployment' => $deployment->id, 'error' => $e->getMessage()]);
                }
            });
        });
    }

    private function registerSchedule(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)
                ->command('triggers:prune')
                ->dailyAt('03:40')
                ->name('triggers-prune')
                ->withoutOverlapping(60);
        });
    }
}
