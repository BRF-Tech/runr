<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * bind-detach of the bindings e2e: after the external binding is detached the function answers
 * "ext": null (EXT_GREETING unset). The check read it with `?? 'set'`, which turns that null into
 * 'set', so a detach that worked was reported as "EXT_GREETING still set".
 */
class E2eBindingsDetachCheckTest extends TestCase
{
    private function gone(array|string $body): bool
    {
        return (new \ReflectionMethod(E2eTestCommand::class, 'externalBindingGone'))->invoke(null, $body);
    }

    public function test_a_null_ext_is_a_detached_binding(): void
    {
        $this->assertTrue($this->gone(['kv' => ['ok' => true], 'ext' => null]));
    }

    public function test_a_set_ext_a_missing_ext_or_a_failed_call_is_not(): void
    {
        $this->assertFalse($this->gone(['ext' => 'hello-from-external']));
        $this->assertFalse($this->gone(['kv' => ['ok' => true]]));
        $this->assertFalse($this->gone('HTTP 404: 404 page not found'));
    }
}
