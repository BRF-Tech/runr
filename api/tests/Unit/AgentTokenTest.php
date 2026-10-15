<?php

namespace Tests\Unit;

use App\Services\Helpers\AgentToken;
use PHPUnit\Framework\TestCase;

class AgentTokenTest extends TestCase
{
    public function test_generated_tokens_are_random_and_256_bit(): void
    {
        $a = AgentToken::generate();
        $b = AgentToken::generate();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_verify_accepts_only_the_matching_token(): void
    {
        $token = AgentToken::generate();
        $hash = AgentToken::hash($token);

        $this->assertSame(64, strlen($hash));
        $this->assertNotSame($token, $hash);
        $this->assertTrue(AgentToken::verify($hash, $token));
        $this->assertFalse(AgentToken::verify($hash, AgentToken::generate()));
        $this->assertFalse(AgentToken::verify($hash, ''));
        $this->assertFalse(AgentToken::verify($hash, null));
        $this->assertFalse(AgentToken::verify(null, $token));
        $this->assertFalse(AgentToken::verify('', $token));
    }

    public function test_revision_changes_with_the_token_and_does_not_expose_the_hash(): void
    {
        $hashA = AgentToken::hash('a');
        $hashB = AgentToken::hash('b');

        $this->assertSame(16, strlen(AgentToken::revision($hashA)));
        $this->assertSame(AgentToken::revision($hashA), AgentToken::revision($hashA));
        $this->assertNotSame(AgentToken::revision($hashA), AgentToken::revision($hashB));
        $this->assertStringNotContainsString(AgentToken::revision($hashA), $hashA);
    }
}
