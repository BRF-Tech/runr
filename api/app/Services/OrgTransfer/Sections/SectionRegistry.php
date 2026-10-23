<?php

namespace App\Services\OrgTransfer\Sections;

/**
 * The sections of an organization package, in the order they export and import
 * (docs/org-export.md#sections). Features that keep organization data of their own register a
 * section from their service provider:
 *
 *   SectionRegistry::extend(TriggersSection::class, 35);   // after projects (30), before gateways (40)
 *
 * Built-in order: organization 10, bindings 20, projects 30, triggers 32, network 34,
 * environments 36, gateways 40, observability 50, binding data 60, images 70, run history 85,
 * deploy 90 (the Billing module adds its cost limits at 38 and its bills at 86).
 */
final class SectionRegistry
{
    /** @var array<class-string<TransferSection>, int> */
    private const BUILT_IN = [
        OrganizationSection::class => 10,
        BindingsSection::class => 20,
        ProjectsSection::class => 30,
        TriggersSection::class => 32,
        NetworkSection::class => 34,
        EnvironmentsSection::class => 36,
        GatewaysSection::class => 40,
        ObservabilitySection::class => 50,
        BindingDataSection::class => 60,
        ImagesSection::class => 70,
        HistorySection::class => 85,
        DeploySection::class => 90,
    ];

    /** @var array<class-string<TransferSection>, int> */
    private static array $extensions = [];

    /** @param class-string<TransferSection> $class */
    public static function extend(string $class, int $order): void
    {
        self::$extensions[$class] = $order;
    }

    /** Tests: back to the built-in sections. */
    public static function reset(): void
    {
        self::$extensions = [];
    }

    /** @return list<TransferSection> */
    public function sections(): array
    {
        $all = self::BUILT_IN + self::$extensions;
        asort($all);

        return array_map(fn (string $class) => app($class), array_keys($all));
    }

    /** Keys of the registered sections. */
    public function keys(): array
    {
        return array_map(fn (TransferSection $s) => $s->key(), $this->sections());
    }

    /** Whether $key is a section this installation knows. */
    public function knows(string $key): bool
    {
        return in_array($key, $this->keys(), true);
    }
}
