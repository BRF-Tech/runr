<?php

namespace Tests\Feature\Observability;

use App\Mail\AlertMail;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertDelivery;
use App\Models\Observability\AlertRule;
use App\Services\Observability\Alerts\AlertMessage;
use App\Services\Observability\Alerts\Channels\SlackChannel;
use App\Services\Observability\Alerts\Channels\TeamsChannel;
use App\Services\Observability\Alerts\Channels\TelegramChannel;
use App\Services\Observability\Alerts\Channels\WebhookChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesObservabilityCluster;
use Tests\TestCase;

/**
 * Alert channels: each channel's payload on the wire (Slack blocks, Teams Adaptive Card,
 * Telegram sendMessage, HMAC-signed webhook, e-mail), "send test" with the delivery log, secrets
 * write-only (never returned, kept on update), the SSRF guard, and alerts.manage.
 */
class AlertChannelsTest extends TestCase
{
    use ActsWithRoles, FakesObservabilityCluster, RefreshDatabase;

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeObservabilityCluster();
        $this->dns->addressRecords = [
            'hooks.slack.com' => ['34.226.36.50'],
            'acme.webhook.office.com' => ['52.112.0.10'],
            'hooks.example.com' => ['93.184.216.34'],
            'intranet.example.com' => ['10.0.0.8'],
        ];
        foreach (['https://hooks.slack.com/', 'https://acme.webhook.office.com/', 'https://api.telegram.org/', 'https://hooks.example.com/'] as $prefix) {
            $this->externalRoutes[$prefix] = function (Request $request) {
                $this->sent[] = $request;

                return Http::response(['ok' => true], 200);
            };
        }
    }

    private function create(string $type, array $config, ?string $name = null): string
    {
        $owner = $this->userWithRole('owner');

        return $this->signedIn($owner)->postJson('/api/observability/alert-channels', [
            'name' => $name ?? $type, 'type' => $type, 'config' => $config,
        ])->assertCreated()->json('data.id');
    }

    private function message(): AlertMessage
    {
        return new AlertMessage(
            title: '[CRITICAL] High error rate on orders',
            text: '12.5 % over the last 5 minutes (threshold 5 %).',
            severity: 'critical',
            status: 'firing',
            fields: ['Project' => 'orders', 'Value' => '12.5 %'],
            url: 'https://console.runr.test/projects/01J#traces',
            eventId: '01JEVENT000000000000000000',
            type: 'error_rate',
        );
    }

    public function test_slack_payload(): void
    {
        $payload = SlackChannel::payload($this->message());

        $this->assertSame('[CRITICAL] High error rate on orders', $payload['text']);
        $this->assertSame('header', $payload['blocks'][0]['type']);
        $this->assertSame("*Project*\norders", $payload['blocks'][2]['fields'][0]['text']);
        $this->assertSame('https://console.runr.test/projects/01J#traces', $payload['blocks'][3]['elements'][0]['url']);
        $this->assertSame('#d1242f', $payload['attachments'][0]['color']);

        $id = $this->create('slack', ['webhook_url' => 'https://hooks.slack.com/services/T0/B0/xyz']);
        $this->signedIn($this->userWithRole('admin'))->postJson("/api/observability/alert-channels/{$id}/test")
            ->assertOk()->assertJsonPath('data.ok', true)->assertJsonPath('data.channel.last_test_ok', true);

        $this->assertSame('https://hooks.slack.com/services/T0/B0/xyz', $this->sent[0]->url());
        $this->assertStringStartsWith('[TEST]', $this->sent[0]->data()['text']);
        $this->assertSame(1, AlertDelivery::query()->where(['kind' => 'test', 'status' => 'sent'])->count());
    }

    public function test_teams_adaptive_card(): void
    {
        $payload = TeamsChannel::payload($this->message());

        $this->assertSame('message', $payload['type']);
        $card = $payload['attachments'][0]['content'];
        $this->assertSame('application/vnd.microsoft.card.adaptive', $payload['attachments'][0]['contentType']);
        $this->assertSame('AdaptiveCard', $card['type']);
        $this->assertSame('Attention', $card['body'][0]['color']);
        $this->assertSame([['title' => 'Project', 'value' => 'orders'], ['title' => 'Value', 'value' => '12.5 %']], $card['body'][2]['facts']);
        $this->assertSame('Action.OpenUrl', $card['actions'][0]['type']);

        $id = $this->create('teams', ['webhook_url' => 'https://acme.webhook.office.com/webhookb2/abc']);
        $this->signedIn($this->userWithRole('owner'))->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', true);
        $this->assertSame('message', $this->sent[0]->data()['type']);
    }

    public function test_telegram_send_message_and_the_token_never_leaks(): void
    {
        $payload = TelegramChannel::payload($this->message(), '-100123');
        $this->assertSame('-100123', $payload['chat_id']);
        $this->assertSame('HTML', $payload['parse_mode']);
        $this->assertStringContainsString('<b>[CRITICAL] High error rate on orders</b>', $payload['text']);
        $this->assertStringContainsString('<b>Project:</b> orders', $payload['text']);

        $token = '123456:ABCdefGhIJKlmNoPQRsTuVwxyz012345';
        $id = $this->create('telegram', ['bot_token' => $token, 'chat_id' => '-100123']);

        $list = $this->signedIn($this->userWithRole('owner'))->getJson('/api/observability/alert-channels')->assertOk();
        $this->assertStringNotContainsString($token, $list->getContent());
        $list->assertJsonPath('data.0.config.bot_token_set', true)->assertJsonPath('data.0.config.chat_id', '-100123');

        $this->signedIn($this->userWithRole('owner'))->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', true);
        $this->assertSame("https://api.telegram.org/bot{$token}/sendMessage", $this->sent[0]->url());
        $this->assertSame('-100123', $this->sent[0]->data()['chat_id']);

        // a failing send: the stored error never carries the token
        $this->externalRoutes['https://api.telegram.org/'] = fn () => Http::response("bad request for bot{$token}", 400);
        $this->signedIn($this->userWithRole('owner'))->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', false);
        $this->assertStringNotContainsString($token, (string) AlertChannel::query()->find($id)->last_test_error);
        $this->assertStringContainsString('***', (string) AlertChannel::query()->find($id)->last_test_error);
    }

    public function test_webhook_is_hmac_signed(): void
    {
        $secret = 'whsec_0123456789abcdef';
        $id = $this->create('webhook', ['url' => 'https://hooks.example.com/runr', 'secret' => $secret]);

        $this->signedIn($this->userWithRole('owner'))->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', true);

        $request = $this->sent[0];
        $timestamp = $request->header('X-RunR-Timestamp')[0];
        $this->assertSame('test', $request->header('X-RunR-Event')[0]);
        $this->assertSame('sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret), $request->header('X-RunR-Signature')[0]);
        $this->assertSame('test', json_decode($request->body(), true)['event']);

        $headers = WebhookChannel::headers('{"a":1}', '1700000000', $secret, $this->message());
        $this->assertSame('alert.firing', $headers['X-RunR-Event']);
        $this->assertSame('01JEVENT000000000000000000', $headers['X-RunR-Alert-Id']);
        $this->assertSame('sha256='.WebhookChannel::signature($secret, '1700000000', '{"a":1}'), $headers['X-RunR-Signature']);
        $this->assertArrayNotHasKey('X-RunR-Signature', WebhookChannel::headers('{}', '1', '', $this->message()), 'unsigned without a secret');
    }

    public function test_email_channel(): void
    {
        Mail::fake();
        $id = $this->create('email', ['emails' => ['Ops@Example.com', 'ops@example.com', 'oncall@example.com']]);

        $this->assertSame(['ops@example.com', 'oncall@example.com'], AlertChannel::query()->find($id)->config['emails']);

        $this->signedIn($this->userWithRole('owner'))->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', true);
        Mail::assertSent(AlertMail::class, fn (AlertMail $mail) => $mail->hasTo('oncall@example.com') && str_starts_with($mail->alert->title, '[TEST]'));
    }

    public function test_validation_ssrf_guard_and_write_only_secrets(): void
    {
        $owner = $this->userWithRole('owner');

        $this->signedIn($owner)->postJson('/api/observability/alert-channels', ['name' => 'x', 'type' => 'slack', 'config' => ['webhook_url' => 'http://hooks.slack.com/x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['config.webhook_url']);
        $this->signedIn($owner)->postJson('/api/observability/alert-channels', ['name' => 'x', 'type' => 'webhook', 'config' => ['url' => 'https://intranet.example.com/hook']])
            ->assertUnprocessable()->assertJsonValidationErrors(['config.url']);
        $this->signedIn($owner)->postJson('/api/observability/alert-channels', ['name' => 'x', 'type' => 'telegram', 'config' => ['bot_token' => 'nope', 'chat_id' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['config.bot_token', 'config.chat_id']);
        $this->signedIn($owner)->postJson('/api/observability/alert-channels', ['name' => 'x', 'type' => 'sms', 'config' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $id = $this->create('webhook', ['url' => 'https://hooks.example.com/a', 'secret' => 'whsec_0123456789abcdef']);
        $this->signedIn($owner)->putJson("/api/observability/alert-channels/{$id}", ['name' => 'renamed', 'config' => ['url' => 'https://hooks.example.com/b']])
            ->assertOk()
            ->assertJsonPath('data.config.url_host', 'hooks.example.com')
            ->assertJsonPath('data.config.secret_set', true)
            ->assertJsonMissingPath('data.config.secret');
        $config = AlertChannel::query()->find($id)->config;
        $this->assertSame(['https://hooks.example.com/b', 'whsec_0123456789abcdef'], [$config['url'], $config['secret']]);

        // stored encrypted
        $this->assertStringNotContainsString('whsec_', (string) \DB::table('alert_channels')->where('id', $id)->value('config'));

        // the private-target guard also applies when sending
        AlertChannel::query()->whereKey($id)->first()->forceFill(['config' => ['url' => 'https://intranet.example.com/x']])->save();
        $this->signedIn($owner)->postJson("/api/observability/alert-channels/{$id}/test")->assertJsonPath('data.ok', false);
        $this->assertSame(0, count(array_filter($this->sent, fn ($r) => str_contains($r->url(), 'intranet'))));
    }

    public function test_deleting_a_channel_removes_it_from_rules_and_permissions(): void
    {
        $owner = $this->userWithRole('owner');
        $dev = $this->userWithRole('developer');
        $id = $this->create('webhook', ['url' => 'https://hooks.example.com/a']);
        $keep = $this->create('webhook', ['url' => 'https://hooks.example.com/b'], 'second');
        $rule = AlertRule::query()->create(['name' => 'r', 'type' => 'uptime_down', 'channel_ids' => [$id, $keep]]);

        $this->signedIn($dev)->getJson('/api/observability/alert-channels')->assertForbidden()->assertJsonPath('permissions', ['alerts.manage']);
        $this->signedIn($dev)->postJson("/api/observability/alert-channels/{$id}/test")->assertForbidden();

        $this->signedIn($owner)->deleteJson("/api/observability/alert-channels/{$id}")->assertNoContent();
        $this->assertSame([$keep], $rule->fresh()->channel_ids);
    }
}
