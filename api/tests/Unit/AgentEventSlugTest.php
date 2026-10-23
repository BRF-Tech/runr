<?php

namespace Tests\Unit;

use App\Services\AgentMessageHandler;
use App\Services\GitDeploy\PreviewManifests;
use PHPUnit\Framework\TestCase;

/**
 * Which slugs of a region agent's event can name a project the event's project id found
 * (AgentMessageHandler::namesProject): the project's slug, the start of it, or — a tenant's long
 * slug a preview's Service name shortened in the middle — its start and the organization's end.
 */
class AgentEventSlugTest extends TestCase
{
    private const LONG = 'orders-of-the-very-long-department-of-reconciliations-acme-eu';

    public function test_the_slug_and_its_start_name_the_project(): void
    {
        $this->assertTrue(AgentMessageHandler::namesProject('shop', 'shop'));
        $this->assertTrue(AgentMessageHandler::namesProject(self::LONG, substr(PreviewManifests::serviceName(self::LONG, 7), 0, -strlen('-pr-7'))));
    }

    public function test_a_tenants_long_slug_cut_in_the_middle_names_the_project(): void
    {
        $service = PreviewManifests::serviceName(self::LONG, 7, 'acme-eu');
        $this->assertTrue(AgentMessageHandler::namesProject(self::LONG, substr($service, 0, -strlen('-pr-7'))));
    }

    public function test_other_slugs_do_not(): void
    {
        $this->assertFalse(AgentMessageHandler::namesProject('shop', 'nowhere'));
        $this->assertFalse(AgentMessageHandler::namesProject('shop', ''));
        $this->assertFalse(AgentMessageHandler::namesProject('shop-acme', 'billing-acme'), 'the same organization, another project');
        $this->assertFalse(AgentMessageHandler::namesProject(self::LONG, 'orders-of-the-very-long-globex'));
    }
}
