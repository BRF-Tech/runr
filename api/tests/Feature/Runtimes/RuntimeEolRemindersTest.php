<?php

namespace Tests\Feature\Runtimes;

use App\Jobs\RuntimeEolRemindersJob;
use App\Mail\RuntimePatchMail;
use App\Models\ExecutorImage;
use App\Models\Notification;
use App\Models\User;
use App\Services\Runtimes\Patches\RuntimePatchNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\RegistersRuntimes;
use Tests\TestCase;

/**
 * End-of-life reminders (RuntimeEolRemindersJob, docs/runtimes.md#end-of-life): 13, 4 and 1
 * week(s) before eol_at the project owners and the organization's owners and admins hear about it
 * once per threshold — the nearest one reached only — by mail a week ahead.
 */
class RuntimeEolRemindersTest extends TestCase
{
    use ActsWithRoles, RefreshDatabase, RegistersRuntimes;

    private User $orgOwner;

    private User $projectOwner;

    private User $developer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltinVersions();
        Mail::fake();

        $this->orgOwner = $this->userWithRole('owner');
        $this->projectOwner = $this->userWithRole('developer');
        $this->developer = $this->userWithRole('developer');

        [$project] = $this->runtimeProject('python', 'http', ['main.py' => 'x']);
        $project->forceFill(['runtime_version' => '3.14', 'owner_id' => $this->projectOwner->id])->save();
    }

    private function eolIn(int $days): ExecutorImage
    {
        $image = ExecutorImage::query()->where('runtime', 'python')->where('version', '3.14')->firstOrFail();
        $image->forceFill(['eol_at' => now()->addDays($days)->toDateString()])->save();

        return $image;
    }

    private function reminders(): array
    {
        return Notification::query()->where('type', 'runtime_eol')->get()
            ->map(fn (Notification $n) => [(string) $n->user_id, $n->data['weeks']])->sort()->values()->all();
    }

    public function test_the_nearest_threshold_reached(): void
    {
        $now = Carbon::parse('2026-10-21 09:00');
        $weeks = [1, 4, 13];

        $this->assertNull(RuntimeEolRemindersJob::threshold($now->copy()->addWeeks(20), $weeks, $now));
        $this->assertSame(13, RuntimeEolRemindersJob::threshold($now->copy()->addWeeks(13), $weeks, $now));
        $this->assertSame(13, RuntimeEolRemindersJob::threshold($now->copy()->addWeeks(5), $weeks, $now));
        $this->assertSame(4, RuntimeEolRemindersJob::threshold($now->copy()->addWeeks(3), $weeks, $now));
        $this->assertSame(1, RuntimeEolRemindersJob::threshold($now->copy()->addDays(2), $weeks, $now));
        $this->assertNull(RuntimeEolRemindersJob::threshold($now->copy()->subDay(), $weeks, $now), 'past its end');
    }

    public function test_four_weeks_ahead_in_app_once(): void
    {
        $this->eolIn(25);

        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));
        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));

        $this->assertSame(collect([[(string) $this->orgOwner->id, 4], [(string) $this->projectOwner->id, 4]])->sort()->values()->all(), $this->reminders());
        Mail::assertNothingQueued();
    }

    public function test_a_week_ahead_also_by_mail(): void
    {
        $this->eolIn(5);

        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));

        $this->assertCount(2, $this->reminders());
        $this->assertSame('critical', Notification::query()->where('type', 'runtime_eol')->value('level'));
        Mail::assertQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->projectOwner->email));
        Mail::assertNotQueued(RuntimePatchMail::class, fn (RuntimePatchMail $m) => $m->hasTo($this->developer->email));
    }

    public function test_far_away_or_past_ends_send_nothing(): void
    {
        $this->eolIn(200);
        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));
        $this->eolIn(-3);
        (new RuntimeEolRemindersJob)->handle(app(RuntimePatchNotifier::class));

        $this->assertSame([], $this->reminders());
    }
}
