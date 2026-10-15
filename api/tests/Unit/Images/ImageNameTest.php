<?php

namespace Tests\Unit\Images;

use App\Services\Images\ImageName;
use Tests\TestCase;

/** Names and references of uploaded images (docs/images.md). */
class ImageNameTest extends TestCase
{
    public function test_names_parse_with_the_default_tag(): void
    {
        $this->assertSame(['app', '1.0'], ImageName::parse('app:1.0'));
        $this->assertSame(['app', 'latest'], ImageName::parse('app'));
        $this->assertSame(['team/web-ui', 'v2_rc.1'], ImageName::parse('team/web-ui:v2_rc.1'));
        $this->assertSame(['a/b/c', 'latest'], ImageName::parse('a/b/c'));

        foreach (['App:1', 'a/b/c/d', 'app:', ':1', 'app:-x', 'app name', '../app', 'app//x', str_repeat('a', 129)] as $bad) {
            $this->assertNull(ImageName::parse($bad), $bad);
        }
    }

    public function test_references(): void
    {
        $digest = 'sha256:'.str_repeat('0f', 32);

        $this->assertSame(['type' => 'tag', 'repository' => 'q-interpreter', 'tag' => '1.0'], ImageName::reference('q-interpreter:1.0'));
        $this->assertSame(['type' => 'digest', 'repository' => 'app', 'digest' => $digest], ImageName::reference("app@{$digest}"));
        $this->assertSame(['type' => 'id', 'id' => '01JABCDEFGHJKMNPQRSTVWXYZ0'], ImageName::reference('01jabcdefghjkmnpqrstvwxyz0'));
        $this->assertNull(ImageName::reference('app@sha256:short'));
        $this->assertNull(ImageName::reference('UPPER:1'));
    }

    public function test_names_from_file_names(): void
    {
        $this->assertSame(['myapp', 'latest'], ImageName::fromFilename('myapp.tar.gz'));
        $this->assertSame(['my-app', 'latest'], ImageName::fromFilename('C:\\Users\\me\\My App.tar'));
        $this->assertSame(['q-interpreter', 'latest'], ImageName::fromFilename('Q Interpreter.wasm'));
        $this->assertSame(['image', 'latest'], ImageName::fromFilename('.tar'));
    }

    public function test_registry_repositories_are_per_owner(): void
    {
        $this->assertSame('runr-uploads/platform/app', ImageName::registryRepository('platform', 'app'));
        $this->assertSame('runr-uploads/6f1c1b5e-0000-4000-8000-000000000001/team/web', ImageName::registryRepository('6F1C1B5E-0000-4000-8000-000000000001', 'team/web'));
    }
}
