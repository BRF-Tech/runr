<?php

namespace Tests\Unit;

use App\Console\Commands\E2eTestCommand;
use PHPUnit\Framework\TestCase;

/**
 * The e2e region skips Knative's tag-to-digest resolution for the registry RunR's own images are
 * loaded into Kind under: a release tag that is not published yet (the universal wrapper's init
 * container) would otherwise fail the Revision.
 */
class E2eTagResolutionTest extends TestCase
{
    public function test_the_wrapper_registry_is_added_to_knatives_defaults(): void
    {
        $this->assertSame(
            'kind.local,ko.local,dev.local,ghcr.io',
            E2eTestCommand::e2eRegistriesSkippingTagResolving('ghcr.io/brf-tech/runr-wrapper:1.1.0'),
        );
    }

    public function test_a_registry_with_a_port_and_docker_hub_as_knative_names_them(): void
    {
        $this->assertSame(
            'kind.local,ko.local,dev.local,registry.example.com:5000',
            E2eTestCommand::e2eRegistriesSkippingTagResolving('registry.example.com:5000/runr/runr-wrapper:1.1.0'),
        );
        $this->assertSame(
            'kind.local,ko.local,dev.local,index.docker.io',
            E2eTestCommand::e2eRegistriesSkippingTagResolving('brf-tech/runr-wrapper:1.1.0'),
        );
    }

    public function test_a_default_registry_is_not_listed_twice(): void
    {
        $this->assertSame(
            'kind.local,ko.local,dev.local',
            E2eTestCommand::e2eRegistriesSkippingTagResolving('dev.local/runr-wrapper:1.1.0'),
        );
    }
}
