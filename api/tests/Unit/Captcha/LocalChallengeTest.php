<?php

namespace Tests\Unit\Captcha;

use App\Services\Captcha\LocalChallenge;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The local proof-of-work CAPTCHA against the shared contract's conformance gate:
 * tests/Fixtures/captcha/captcha-local-vectors.json, a byte-for-byte copy (LF) of the contract's
 * docs/captcha-local-vectors.json (20 vectors: 7 positive, 13 negative, app_key "TEST"). The
 * console's twin (vue/src/modules/white-label/utils/captchaPow.js) reads the same file
 * (vue/tests/white-label/captchaPow.spec.js). Never edit the file by hand: a contract change
 * regenerates it.
 */
class LocalChallengeTest extends TestCase
{
    private const VECTORS = __DIR__.'/../../Fixtures/captcha/captcha-local-vectors.json';

    private static function file(): array
    {
        return json_decode((string) file_get_contents(self::VECTORS), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, array{array}> */
    public static function vectors(): array
    {
        $out = [];
        foreach (self::file()['vectors'] as $vector) {
            $out[$vector['id']] = [$vector];
        }

        return $out;
    }

    /** @return array<string, array{array}> */
    public static function positives(): array
    {
        return array_filter(self::vectors(), fn (array $row) => $row[0]['kind'] === 'positive');
    }

    public function test_the_fixture_is_the_contracts_file(): void
    {
        // sha256 of the contract's LF file (commit d3d027c7 of the shared docs)
        $this->assertSame('b587c15f2aa2f044b9f0897f519cb226f7a2f6a010d26a50afd8bd27349604ae', hash_file('sha256', self::VECTORS));

        $file = self::file();
        $this->assertSame(LocalChallenge::ALGORITHM, $file['algorithm']);
        $this->assertSame(LocalChallenge::DEFAULT_TTL, $file['defaults']['ttl_seconds']);
        $this->assertSame(LocalChallenge::DEFAULT_MAXNUMBER, $file['defaults']['maxnumber']);
        $this->assertSame(LocalChallenge::FIELD, $file['defaults']['payload_field']);
        $this->assertSame(LocalChallenge::USED_KEY_PREFIX, $file['defaults']['used_cache_key_prefix']);
        $this->assertSame(LocalChallenge::REASONS, $file['verify_order']);
        $this->assertCount(7, array_filter($file['vectors'], fn ($v) => $v['kind'] === 'positive'));
        $this->assertCount(13, array_filter($file['vectors'], fn ($v) => $v['kind'] === 'negative'));
    }

    #[DataProvider('vectors')]
    public function test_every_vector_verifies_as_expected(array $vector): void
    {
        $input = $vector['input'];
        $seen = array_fill_keys(array_map('strtolower', $input['seen_signatures']), true);
        $marked = [];

        $result = LocalChallenge::verify(
            $vector['solution']['payload_base64'],
            $input['app_key'],
            $input['now'],
            $input['maxnumber'],
            function (string $signature, int $ttl) use (&$seen, &$marked) {
                if (isset($seen[$signature])) {
                    return false;
                }
                $seen[$signature] = true;
                $marked[$signature] = $ttl;

                return true;
            },
        );

        $this->assertSame($vector['expected']['verify']['ok'], $result['ok'], $vector['id'].': '.$vector['description']);
        $this->assertSame($vector['expected']['verify']['reason'], $result['reason'], $vector['id'].': '.$vector['description']);

        if ($result['ok']) {
            // single use: lower(signature), for the challenge's remaining lifetime
            $signature = strtolower($vector['expected']['signature']);
            $this->assertSame([$signature => max(1, $input['expires'] - $input['now'])], $marked);
        } else {
            $this->assertSame([], $marked, 'a refused answer is not marked as used');
        }
    }

    #[DataProvider('vectors')]
    public function test_the_challenge_side_of_every_vector_is_reproduced(array $vector): void
    {
        $challenge = $vector['challenge'];
        $key = $vector['input']['app_key'];

        // signature = HMAC-SHA256(app_key, lower(hash) as ASCII)
        $this->assertSame(strtolower($vector['expected']['signature']), LocalChallenge::sign($key, $challenge['hash']));
        $this->assertSame($vector['expected']['hash'], strtolower($challenge['hash']));

        if ($vector['kind'] === 'positive') {
            $this->assertSame($vector['expected']['hash'], LocalChallenge::hash($challenge['salt'], $vector['input']['number']));
            $this->assertSame($vector['input']['expires'], LocalChallenge::expiresOf($challenge['salt']));
        }
    }

    #[DataProvider('positives')]
    public function test_a_positive_vector_is_encoded_like_the_contract(array $vector): void
    {
        $challenge = $vector['challenge'];
        $decoded = json_decode($vector['solution']['payload_json'], true);

        // The same JSON (field order algorithm, salt, number, hash, signature) in padded standard base64
        $this->assertSame($vector['solution']['payload_base64'], LocalChallenge::encode([
            'algorithm' => $decoded['algorithm'],
            'salt' => $decoded['salt'],
            'hash' => $decoded['hash'],
            'signature' => $decoded['signature'],
        ], $decoded['number']));
        $this->assertSame($vector['input']['number'], LocalChallenge::decode($vector['solution']['payload_base64'])['number']);

        if (strtolower($decoded['hash']) === $decoded['hash']) {
            $this->assertSame($vector['solution']['payload_base64'], LocalChallenge::encode($challenge, $vector['input']['number']));
        }
    }

    public function test_a_created_challenge_is_solved_and_verified_once(): void
    {
        $now = 1762300000;
        $challenge = LocalChallenge::create('k3y', 5000, 600, $now, 1234, str_repeat('ab', 16));

        $this->assertSame('SHA-256', $challenge['algorithm']);
        $this->assertSame(str_repeat('ab', 16).'?expires='.($now + 600), $challenge['salt']);
        $this->assertSame(5000, $challenge['maxnumber']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $challenge['signature']);
        $this->assertArrayNotHasKey('number', $challenge, 'the number stays on the server');

        // the client's scan
        $found = null;
        for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
            if (LocalChallenge::hash($challenge['salt'], $n) === $challenge['hash']) {
                $found = $n;
                break;
            }
        }
        $this->assertSame(1234, $found);

        $used = [];
        $mark = function (string $signature) use (&$used) {
            if (isset($used[$signature])) {
                return false;
            }

            return $used[$signature] = true;
        };
        $payload = LocalChallenge::encode($challenge, $found);

        $this->assertSame(['ok' => true, 'reason' => 'ok'], array_intersect_key(LocalChallenge::verify($payload, 'k3y', $now + 5, 5000, $mark), ['ok' => 1, 'reason' => 1]));
        $this->assertSame('replayed', LocalChallenge::verify($payload, 'k3y', $now + 6, 5000, $mark)['reason']);
        $this->assertSame('bad_signature', LocalChallenge::verify($payload, 'another-key', $now + 5, 5000, fn () => true)['reason']);
        $this->assertSame('expired', LocalChallenge::verify($payload, 'k3y', $now + 600, 5000, fn () => true)['reason']);
        $this->assertSame('bad_number', LocalChallenge::verify($payload, 'k3y', $now + 5, 1000, fn () => true)['reason']);
    }

    public function test_odd_payloads_are_bad_payloads(): void
    {
        $never = fn () => $this->fail('never marked');

        foreach ([null, 42, '', str_repeat('A', LocalChallenge::MAX_PAYLOAD_BYTES + 4), base64_encode('[1,2]'), base64_encode('"x"'),
            base64_encode('{"algorithm":"SHA-256","salt":"a?expires=9","number":true,"hash":"h","signature":"s"}'),
            base64_encode('{"algorithm":"SHA-256","salt":"a?expires=9","number":1.5,"hash":"h","signature":"s"}'),
            'eyJ_bad-url-safe'] as $payload) {
            $this->assertSame('bad_payload', LocalChallenge::verify($payload, 'k', 1, 10, $never)['reason'], var_export($payload, true));
        }

        // unpadded base64 is accepted
        $json = '{"algorithm":"SHA-256","salt":"a?expires=1","number":1,"hash":"h","signature":"s"}';
        $this->assertSame('expired', LocalChallenge::verify(rtrim(base64_encode($json), '='), 'k', 5, 10, $never)['reason']);
    }

    public function test_the_salt_needs_an_integer_expires(): void
    {
        $this->assertNull(LocalChallenge::expiresOf('abc'));
        $this->assertNull(LocalChallenge::expiresOf('abc?v=1'));
        $this->assertNull(LocalChallenge::expiresOf('abc?expires=soon'));
        $this->assertNull(LocalChallenge::expiresOf('abc?expires=-5'));
        $this->assertNull(LocalChallenge::expiresOf(str_repeat('a', 250).'?expires=1'));
        $this->assertSame(17, LocalChallenge::expiresOf('abc?v=1&expires=17'));
        // a trailing newline is not part of the number (\z, not $)
        $this->assertNull(LocalChallenge::expiresOf("abc?expires=17\n"));
    }

    public function test_a_trailing_newline_is_refused(): void
    {
        $now = 1_762_300_000;
        $challenge = LocalChallenge::create('k3y', 5000, 600, $now, 42);
        $payload = LocalChallenge::encode($challenge, 42);
        $never = fn () => $this->fail('never marked');

        // the base64 check: a newline after the padding or the last character
        $this->assertSame('bad_payload', LocalChallenge::verify($payload."\n", 'k3y', $now + 5, 5000, $never)['reason']);
        $this->assertSame('bad_payload', LocalChallenge::verify(rtrim($payload, '=')."\n", 'k3y', $now + 5, 5000, $never)['reason']);

        // the salt's expires: a newline after its digits
        $broken = ['salt' => $challenge['salt']."\n"] + $challenge;
        $this->assertSame('bad_salt', LocalChallenge::verify(LocalChallenge::encode($broken, 42), 'k3y', $now + 5, 5000, $never)['reason']);

        // and the clean answer still passes
        $this->assertTrue(LocalChallenge::verify($payload, 'k3y', $now + 5, 5000, fn () => true)['ok']);
    }
}
