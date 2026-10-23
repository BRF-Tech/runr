<?php

namespace Tests\Unit\Triggers;

use App\Models\KnativeRegion;
use App\Triggers\Topics\TopicTokens;
use Tests\TestCase;

/**
 * Publish tokens of a region's topic publisher (runtime/queue-consumer/publisher verifies the same
 * format): v1.<base64url claims>.<base64url HMAC-SHA256>, the key derived from the app key and
 * the region.
 */
class TopicTokensTest extends TestCase
{
    private function region(string $id): KnativeRegion
    {
        $region = new KnativeRegion;
        $region->forceFill(['id' => $id]);

        return $region;
    }

    public function test_a_project_token_round_trips_and_never_expires(): void
    {
        $region = $this->region('01JREGIONAAAAAAAAAAAAAAAAA');
        $token = TopicTokens::forProject($region, '01JPROJECTAAAAAAAAAAAAAAAA');

        $this->assertMatchesRegularExpression('/^v1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token);
        $claims = TopicTokens::verify($region, $token);
        $this->assertSame(['sfx' => '', 'prj' => '01JPROJECTAAAAAAAAAAAAAAAA', 'top' => ['*'], 'exp' => 0], $claims);
    }

    public function test_another_region_or_a_changed_payload_does_not_verify(): void
    {
        $region = $this->region('01JREGIONAAAAAAAAAAAAAAAAA');
        $token = TopicTokens::forApi($region, 'orders');

        $this->assertNull(TopicTokens::verify($this->region('01JREGIONBBBBBBBBBBBBBBBBB'), $token));

        [$v, $payload, $sig] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['top'] = ['*'];
        $forged = $v.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$sig;
        $this->assertNull(TopicTokens::verify($region, $forged));
    }

    public function test_an_expired_api_token_does_not_verify(): void
    {
        $region = $this->region('01JREGIONAAAAAAAAAAAAAAAAA');

        $this->assertNull(TopicTokens::verify($region, TopicTokens::mint($region, ['sfx' => '', 'prj' => 'api', 'top' => ['a'], 'exp' => time() - 1])));
        $this->assertTrue(TopicTokens::verify($region, TopicTokens::forApi($region, 'a', admin: true))['adm']);
    }

    public function test_the_key_is_the_hmac_of_the_region_with_the_app_key(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $region = $this->region('01JREGIONAAAAAAAAAAAAAAAAA');

        $this->assertSame(hash_hmac('sha256', 'topics:01JREGIONAAAAAAAAAAAAAAAAA', 'base64:'.base64_encode(str_repeat('k', 32))), TopicTokens::key($region));
    }
}
