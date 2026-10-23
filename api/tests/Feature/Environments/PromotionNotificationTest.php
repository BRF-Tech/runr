<?php

namespace Tests\Feature\Environments;

use App\Events\DeploymentCreated;
use App\Jobs\Triggers\NudgeEventGatewayJob;
use App\Mail\PromotionMail;
use App\Models\Deployment;
use App\Models\EnvironmentPromotion;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\TriggerEvent;
use App\Models\User;
use App\Services\Environments\PromotionNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\BuildsTriggerFixtures;
use Tests\Concerns\EnvironmentFixtures;
use Tests\TestCase;

/**
 * Who hears about a promotion (docs/environments.md#notifications): the approvers of one that waits
 * (environments.approve on the project, not the requester), the requester of its approval,
 * rejection, deployment and failure — in-app and by mail through the "promotion" notification
 * preference, and as environment.promotion.* platform events.
 */
class PromotionNotificationTest extends TestCase
{
    use ActsWithRoles, EnvironmentFixtures {
        EnvironmentFixtures::caasProject insteadof ActsWithRoles;
    }
    use BuildsTriggerFixtures;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private User $admin;

    private User $developer;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([DeploymentCreated::class]);
        Mail::fake();
        Bus::fake([NudgeEventGatewayJob::class]);

        $this->owner = $this->userWithRole('owner');
        $this->admin = $this->userWithRole('admin');
        $this->developer = $this->userWithRole('developer');
        $this->viewer = $this->userWithRole('viewer');

        $this->project = $this->caasProject('shop', $this->region('eu', false));
        $staging = $this->environment($this->project, 'staging');
        $this->liveDeployment($this->project, $this->caasVersion($this->project, 1));
        $this->liveDeployment($this->project, $this->caasVersion($this->project, 2), 'active', $staging);
    }

    /** The developer asks to promote staging's v2 into production (which requires approval). */
    private function request(string $message = 'release 1.1'): string
    {
        return $this->actingAs($this->developer, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/environments/staging/promote", ['message' => $message])
            ->assertAccepted()
            ->assertJsonPath('data.status', EnvironmentPromotion::PENDING_APPROVAL)
            ->json('data.id');
    }

    /** @return list<string> ids of the users with a notification of $event */
    private function notified(string $event): array
    {
        return Notification::query()->where('type', PromotionNotifier::TYPE_PREFIX.$event)->pluck('user_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    /** @param  list<User>  $users */
    private function ids(array $users): array
    {
        return collect($users)->map(fn (User $u) => (string) $u->id)->sort()->values()->all();
    }

    public function test_the_approvers_hear_of_a_promotion_that_waits_for_them_but_not_the_requester(): void
    {
        $this->request();

        // owner and admin hold environments.approve; the requester (a developer) and a viewer do not
        $this->assertSame($this->ids([$this->owner, $this->admin]), $this->notified(PromotionNotifier::PENDING_APPROVAL));
        $notification = Notification::query()->where('user_id', $this->owner->id)->sole();
        $this->assertStringContainsString('v2', $notification->title);
        $this->assertStringContainsString('production', $notification->title);
        $this->assertStringContainsString('release 1.1', $notification->body);
        $this->assertSame('/approvals', $notification->data['path']);
        $this->assertSame('staging', $notification->data['source_environment']);

        Mail::assertQueued(PromotionMail::class, 2);
        Mail::assertQueued(PromotionMail::class, fn (PromotionMail $mail) => $mail->hasTo($this->admin->email) && str_ends_with((string) $mail->actionUrl, '/approvals'));
        Mail::assertNotQueued(PromotionMail::class, fn (PromotionMail $mail) => $mail->hasTo($this->developer->email));
    }

    public function test_an_approver_with_the_permission_on_the_project_only_hears_of_it_too(): void
    {
        $lead = $this->userWithRole('viewer');
        $this->grantProject($lead, $this->project, 'admin');

        $this->request();

        $this->assertContains((string) $lead->id, $this->notified(PromotionNotifier::PENDING_APPROVAL));
        $this->assertNotContains((string) $this->viewer->id, $this->notified(PromotionNotifier::PENDING_APPROVAL));
    }

    public function test_the_requester_hears_of_the_approval_and_the_deployment(): void
    {
        $id = $this->request();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/promotions/{$id}/approve", ['comment' => 'ship it'])->assertOk();

        $this->assertSame($this->ids([$this->developer]), $this->notified(PromotionNotifier::APPROVED));
        $approved = Notification::query()->where('type', PromotionNotifier::TYPE_PREFIX.PromotionNotifier::APPROVED)->sole();
        $this->assertStringContainsString('ship it', $approved->body);
        $this->assertSame("/projects/{$this->project->id}#environments", $approved->data['path']);

        $deployment = Deployment::query()->findOrFail(EnvironmentPromotion::query()->findOrFail($id)->deployment_id);
        $deployment->update(['status' => 'active', 'ready_at' => now()]);

        $this->assertSame($this->ids([$this->developer]), $this->notified(PromotionNotifier::DEPLOYED));
        Mail::assertQueued(PromotionMail::class, fn (PromotionMail $mail) => $mail->hasTo($this->developer->email) && str_contains($mail->noticeTitle, 'runs in production'));
    }

    public function test_the_requester_hears_of_a_rejection_and_of_a_failure(): void
    {
        $id = $this->request();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/projects/{$this->project->id}/promotions/{$id}/reject", ['comment' => 'not this week'])->assertOk();

        $this->assertSame($this->ids([$this->developer]), $this->notified(PromotionNotifier::REJECTED));
        $this->assertStringContainsString('not this week', Notification::query()->where('type', PromotionNotifier::TYPE_PREFIX.PromotionNotifier::REJECTED)->sole()->body);

        $second = $this->request('second try');
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/promotions/{$second}/approve")->assertOk();
        $deployment = Deployment::query()->findOrFail(EnvironmentPromotion::query()->findOrFail($second)->deployment_id);
        $deployment->update(['status' => 'failed', 'knative_message' => 'Revision not ready: image pull failed']);

        $this->assertSame($this->ids([$this->developer]), $this->notified(PromotionNotifier::FAILED));
        $this->assertStringContainsString('image pull failed', Notification::query()->where('type', PromotionNotifier::TYPE_PREFIX.PromotionNotifier::FAILED)->sole()->body);
    }

    public function test_the_promotion_preference_turns_in_app_and_mail_off(): void
    {
        NotificationPreference::query()->create(['user_id' => $this->owner->id, 'type' => 'promotion', 'channel' => 'email', 'enabled' => false]);
        NotificationPreference::query()->create(['user_id' => $this->admin->id, 'type' => 'promotion', 'channel' => 'in_app', 'enabled' => false]);
        // another type's preference does not matter
        NotificationPreference::query()->create(['user_id' => $this->admin->id, 'type' => 'deployment', 'channel' => 'email', 'enabled' => false]);

        $this->request();

        $this->assertSame($this->ids([$this->owner]), $this->notified(PromotionNotifier::PENDING_APPROVAL), 'the admin turned in-app off');
        Mail::assertQueued(PromotionMail::class, 1);
        Mail::assertQueued(PromotionMail::class, fn (PromotionMail $mail) => $mail->hasTo($this->admin->email));
    }

    public function test_every_change_is_a_platform_event_for_the_subscribed_triggers(): void
    {
        $listener = $this->triggerProject($this->triggerRegion(redis: false), 'http', 'ops-bot');
        $trigger = $this->addTrigger($listener, 'platform_event', ['events' => ['environment.promotion.pending_approval', 'environment.promotion.rejected']]);

        $id = $this->request();
        $this->actingAs($this->owner, 'sanctum')->postJson("/api/projects/{$this->project->id}/promotions/{$id}/reject")->assertOk();

        $events = TriggerEvent::query()->where('trigger_id', $trigger->id)->orderBy('received_at')->orderBy('id')->get();
        $this->assertSame(['dev.runr.environment.promotion.pending_approval', 'dev.runr.environment.promotion.rejected'], $events->pluck('event_type')->all());
        $payload = json_decode($events->first()->rawBody(), true);
        $this->assertSame($this->project->id, $payload['project_id']);
        $this->assertSame($id, $payload['data']['promotion_id']);
        $this->assertSame('production', $payload['data']['target_environment']);
        $this->assertSame(2, $payload['data']['version']);
    }

    public function test_the_mail_renders_with_a_link(): void
    {
        $html = (new PromotionMail('shop: v2 waits for your approval to go to production', "Dev asks to promote v2.\nMessage: release", 'https://runr.example.com/approvals'))->render();

        $this->assertStringContainsString('shop: v2 waits for your approval to go to production', $html);
        $this->assertStringContainsString('Message: release', $html);
        $this->assertStringContainsString('https://runr.example.com/approvals', $html);
    }
}
