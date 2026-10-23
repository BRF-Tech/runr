<?php

namespace Tests\Feature\OrgTransfer;

use App\Models\Binding;
use App\Models\BindingAttachment;
use App\Models\ExecutorImage;
use App\Models\FaasConfig;
use App\Models\Gateway;
use App\Models\GatewayRoute;
use App\Models\Group;
use App\Models\KnativeRegion;
use App\Models\Observability\AlertChannel;
use App\Models\Observability\AlertRule;
use App\Models\Observability\StatusPage;
use App\Models\OrgTransfer;
use App\Models\OrgTransferItem;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\ProjectSecret;
use App\Models\Role;
use App\Models\User;
use App\Models\Version;
use App\Services\GitService;
use App\Services\Observability\StatusPageService;
use App\Services\OrgTransfer\ImportChoices;
use App\Services\OrgTransfer\OrgExporter;
use App\Services\OrgTransfer\OrgImporter;
use App\Services\OrgTransfer\OrgTransferPaths;
use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Package\PackageWriter;
use App\Services\OrgTransfer\Package\PassphraseBox;
use App\Services\OrgTransfer\Sections\SectionRegistry;
use App\Services\Secrets\ProjectSecrets;
use App\Support\Rbac\AccessManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsWithRoles;
use Tests\Concerns\FakesRegionSecrets;
use Tests\TestCase;

/**
 * An organization exported and imported again in this process (docs/org-export.md): the package
 * holds projects with every version and its code, configs, secrets (sealed), domains, bindings,
 * roles / groups, alert channels / rules, the status page and gateways; the import plans slug
 * collisions and region mapping, creates everything with the source's version numbers and code,
 * restores the secrets with the passphrase, leaves domains for a new DNS verification, and an
 * apply run again creates nothing twice. A package of a newer schema is refused.
 */
class OrgExportImportTest extends TestCase
{
    use ActsWithRoles, FakesRegionSecrets, RefreshDatabase;

    private const PASSPHRASE = 'correct horse battery staple';

    private User $owner;

    private KnativeRegion $region;

    protected function setUp(): void
    {
        parent::setUp();
        config(['org_transfer.compression' => 'none']);
        // a demo region keeps no values: secret values stay in RunR's database here (the
        // deprecated storage mode), as they do for a region without a cluster
        config(['secrets.storage' => 'database']);
        Queue::fake();
        SectionRegistry::reset();

        $this->owner = $this->userWithRole('owner');
        $this->actingAs($this->owner, 'sanctum');
        $this->region = KnativeRegion::create(['name' => 'eu-1', 'project_namespace' => 'eu-1', 'is_active' => true, 'is_demo' => true]);
    }

    /** The source organization: one FaaS project with two versions and everything around it. */
    private function seedOrganization(): Project
    {
        $project = Project::create(['name' => 'api', 'service_type' => 'faas', 'runtime' => 'python', 'description' => 'Orders API', 'created_by' => (string) $this->owner->id]);
        $project->forceFill(['knative_region_id' => $this->region->id])->saveQuietly();
        FaasConfig::create([
            'project_id' => $project->id, 'handler' => 'main.handler', 'trigger_type' => 'http',
            'cpu' => '250m', 'memory_mb' => 256, 'timeout_seconds' => 45, 'env_vars' => ['MODE' => 'prod'],
        ]);

        $git = app(GitService::class);
        $git->commitFiles($project, ['main.py' => "def handler(e):\n    return 'v1'\n"], 'first', (string) $this->owner->id);
        $git->commitFiles($project, ['main.py' => "def handler(e):\n    return 'v2'\n", 'lib/util.py' => 'X = 1'], 'second', (string) $this->owner->id);

        ProjectSecret::create(['project_id' => $project->id, 'name' => 'API_KEY', 'value' => 'sk_live_42']);
        ProjectDomain::create(['project_id' => $project->id, 'domain' => 'api.example.com', 'status' => ProjectDomain::STATUS_PENDING, 'verification_token' => 't', 'tls_mode' => ProjectDomain::TLS_NONE]);

        $binding = Binding::query()->create([
            'name' => 'stripe', 'resource_name' => 'stripe-abcdefgh', 'type' => 'external', 'plan' => 'external',
            'status' => Binding::STATUS_READY, 'credentials' => ['values' => ['STRIPE_TOKEN' => 'tok_9']],
        ]);
        BindingAttachment::create(['binding_id' => $binding->id, 'project_id' => $project->id, 'env_prefix' => 'PAY_']);

        app(AccessManager::class)->createRole($this->owner, ['key' => 'ops', 'name' => 'Ops', 'permissions' => ['projects.view', 'logs.view']]);
        $group = Group::create(['name' => 'SRE']);
        app(AccessManager::class)->setGroupRole($this->owner, $group, 'ops');

        $channel = AlertChannel::create(['name' => 'pager', 'type' => 'webhook', 'config' => ['url' => 'https://hooks.example.com/x?token=abc', 'secret' => 'whsec'], 'is_enabled' => true]);
        AlertRule::create(['name' => 'api errors', 'type' => 'error_rate', 'project_id' => $project->id, 'params' => ['threshold' => 5], 'severity' => 'critical', 'cooldown_minutes' => 30, 'channel_ids' => [$channel->id], 'is_enabled' => true, 'state' => 'ok']);
        app(StatusPageService::class)->save(['slug' => 'acme-status', 'title' => 'Acme status', 'is_public' => true]);

        $gateway = Gateway::create(['user_id' => $this->owner->id, 'knative_region_id' => $this->region->id, 'subdomain' => $this->owner->username, 'status' => 'active']);
        GatewayRoute::create(['gateway_id' => $gateway->id, 'project_id' => $project->id, 'path' => '/orders', 'methods' => ['GET'], 'sort_order' => 1, 'auth_mode' => 'key']);

        return $project;
    }

    private function export(array $options): OrgTransfer
    {
        $transfer = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'export', 'status' => 'queued', 'options' => $options,
            'destination' => ['type' => 'download'], 'created_by' => (string) $this->owner->id, 'created_by_name' => $this->owner->name,
        ]);
        app(OrgExporter::class)->run($transfer, $options['include_secrets'] ?? false ? self::PASSPHRASE : null);

        return $transfer->fresh();
    }

    /** An import transfer holding the export's package, verified and planned. */
    private function importOf(OrgTransfer $export, array $choices = []): OrgTransfer
    {
        $import = OrgTransfer::query()->create([
            'owner_key' => 'platform', 'kind' => 'import', 'status' => 'verifying', 'options' => ['filename' => $export->package_filename],
            'upload_size' => $export->package_size, 'uploaded_bytes' => $export->package_size,
            'created_by' => (string) $this->owner->id, 'created_by_name' => $this->owner->name,
        ]);
        OrgTransferPaths::ensure(OrgTransferPaths::dir($import));
        copy(OrgTransferPaths::package($export), OrgTransferPaths::package($import));
        app(OrgImporter::class)->verify($import, ImportChoices::from($choices));

        return $import->fresh();
    }

    private function apply(OrgTransfer $import, array $choices): OrgTransfer
    {
        $choices = ImportChoices::from($choices, ImportChoices::from((array) $import->options));
        $import->update(['status' => OrgTransfer::STATUS_APPLYING]);
        app(OrgImporter::class)->apply($import, $choices, $this->owner);

        return $import->fresh();
    }

    public function test_the_export_writes_a_verified_package_with_sealed_secrets(): void
    {
        $this->seedOrganization();

        $export = $this->export(['include_secrets' => true, 'include_members' => true]);

        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertTrue($export->isDownloadable());
        $this->assertSame(PackageFormat::SCHEMA_VERSION, $export->schema_version);
        $this->assertSame(hash_file('sha256', OrgTransferPaths::package($export)), $export->package_sha256);
        $this->assertDirectoryDoesNotExist(OrgTransferPaths::staging($export), 'staging removed');

        $manifest = (new PackageReader)->manifest(OrgTransferPaths::package($export));
        $this->assertSame(1, $manifest['contents']['projects']);
        $this->assertSame(2, $manifest['contents']['versions']);
        $this->assertSame(1, $manifest['contents']['members']);
        $this->assertContains('projects', $manifest['sections']);
        $paths = array_column($manifest['entries'], 'path');
        $this->assertContains(PackageFormat::SECRETS, $paths);
        $this->assertNotNull($manifest['encryption']);

        // nothing secret in clear anywhere in the package
        $raw = file_get_contents(OrgTransferPaths::package($export));
        foreach (['sk_live_42', 'tok_9', 'whsec', 'token=abc', self::PASSPHRASE] as $secret) {
            $this->assertStringNotContainsString($secret, $raw, $secret);
        }

        // the sealed part opens with the passphrase
        $dir = $this->testStorage.'/x';
        (new PackageReader)->extract(OrgTransferPaths::package($export), $dir);
        $secrets = json_decode(PassphraseBox::open(file_get_contents($dir.'/'.PackageFormat::SECRETS), self::PASSPHRASE), true);
        $projectId = Project::query()->value('id');
        $this->assertSame(['API_KEY' => 'sk_live_42'], $secrets['projects'][$projectId]);

        $project = json_decode(file_get_contents($dir.'/'.PackageFormat::projectFile($projectId)), true);
        $this->assertSame(['API_KEY'], $project['secrets'], 'names only in clear');
        $this->assertSame(['MODE' => 'prod'], $project['config']['env_vars']);
        $this->assertSame([1, 2], array_column($project['versions'], 'number'));
        $this->assertFileExists($dir.'/'.PackageFormat::codeFile($projectId, 2));

        $this->assertDatabaseHas('audit_events', ['action' => 'org.export.completed']);
    }

    public function test_an_import_into_the_same_organization_plans_collisions_and_creates_a_copy(): void
    {
        $source = $this->seedOrganization();
        $export = $this->export(['include_secrets' => true]);

        $import = $this->importOf($export);
        $this->assertSame('planned', $import->status, (string) $import->message);

        $plan = $import->plan;
        $this->assertFalse($plan['can_apply']);
        $this->assertSame('locked', $plan['contents']['secrets'], 'no passphrase yet');
        $row = $plan['projects'][0];
        $this->assertTrue($row['collision']);
        $this->assertSame('api-2', $row['suggested_name']);
        $this->assertContains('slug_taken', array_column($row['issues'], 'code'));
        $this->assertSame($this->region->id, $row['target_region_id'], 'same installation: the same region');
        $this->assertSame('stripe', $plan['bindings'][0]['name']);
        $this->assertContains('name_taken', array_column($plan['bindings'][0]['issues'], 'code'));
        $this->assertContains('dns', array_column($plan['manual_steps'], 'code'));

        $choices = ['renames' => [$source->id => 'api-2'], 'binding_renames' => [$plan['bindings'][0]['source_id'] => 'stripe-2'], 'passphrase' => self::PASSPHRASE];
        $planned = app(OrgImporter::class)->plan($import, ImportChoices::from($choices));
        $this->assertTrue($planned['can_apply'], json_encode($planned['projects'][0]['issues']));
        $this->assertSame('unlocked', $planned['contents']['secrets']);

        $done = $this->apply($import, $choices);

        $copy = Project::query()->where('name', 'api-2')->firstOrFail();
        $this->assertSame($this->region->id, $copy->knative_region_id);
        $this->assertSame('Orders API', $copy->description);
        $this->assertSame(45, $copy->faasConfig->timeout_seconds);
        $this->assertSame(['MODE' => 'prod'], $copy->faasConfig->env_vars);

        // versions with their numbers and code
        $this->assertSame([1, 2], Version::query()->where('project_id', $copy->id)->orderBy('number')->pluck('number')->all());
        $v2 = Version::query()->where('project_id', $copy->id)->where('number', 2)->first();
        $files = app(GitService::class)->getAllFilesAtVersion($copy, $v2->commit_id);
        $this->assertSame("def handler(e):\n    return 'v2'\n", $files['main.py']);
        $this->assertSame('X = 1', $files['lib/util.py']);
        $v1 = Version::query()->where('project_id', $copy->id)->where('number', 1)->first();
        $this->assertArrayNotHasKey('lib/util.py', app(GitService::class)->getAllFilesAtVersion($copy, $v1->commit_id));

        // secrets restored with the passphrase
        $this->assertSame('sk_live_42', ProjectSecret::query()->where('project_id', $copy->id)->where('name', 'API_KEY')->first()->value);

        // the binding copy with its values, attached with the same prefix
        $binding = Binding::query()->where('name', 'stripe-2')->firstOrFail();
        $this->assertSame(['STRIPE_TOKEN' => 'tok_9'], $binding->credential('values'));
        $this->assertDatabaseHas('binding_attachments', ['binding_id' => $binding->id, 'project_id' => $copy->id, 'env_prefix' => 'PAY_']);

        // the domain and the gateway path belong to the source project: manual steps, not taken
        $this->assertSame(1, ProjectDomain::query()->where('domain', 'api.example.com')->count());
        $this->assertSame('manual', OrgTransferItem::query()->where('org_transfer_id', $import->id)->where('section', 'domain')->value('status'));
        $this->assertSame('manual', OrgTransferItem::query()->where('org_transfer_id', $import->id)->where('section', 'gateway_route')->value('status'));

        // the alert rule points at the copy, the existing channel is reused; roles and groups exist
        $rule = AlertRule::query()->where('project_id', $copy->id)->first();
        $this->assertNotNull($rule);
        $this->assertSame([AlertChannel::query()->where('name', 'pager')->value('id')], $rule->channel_ids);
        $this->assertSame(1, Role::query()->where('key', 'ops')->count());
        $this->assertSame(1, Group::query()->where('name', 'SRE')->count());

        $this->assertSame('completed', $done->status, json_encode($done->report['items']));
        $this->assertSame(0, $done->report['counts']['failed']);
        $this->assertGreaterThan(5, $done->report['counts']['created']);
        $this->assertContains('domain_taken', array_column($done->report['manual_steps'], 'code'));

        // applying again creates nothing twice
        $projects = Project::query()->count();
        $versions = Version::query()->count();
        $this->apply($done, $choices);
        $this->assertSame($projects, Project::query()->count());
        $this->assertSame($versions, Version::query()->count());
        $this->assertSame(1, AlertRule::query()->where('project_id', $copy->id)->count());
    }

    public function test_a_restore_into_an_empty_organization_creates_everything_under_its_names(): void
    {
        $source = $this->seedOrganization();
        $export = $this->export(['include_secrets' => true]);

        // Disaster: the organization's entries are gone
        AlertRule::query()->delete();
        AlertChannel::query()->delete();
        GatewayRoute::query()->delete();
        Gateway::query()->delete();
        BindingAttachment::query()->delete();
        Binding::query()->delete();
        $source->delete();
        // through the model: its public route (status_page_routes) goes with it
        StatusPage::query()->get()->each->delete();

        $import = $this->importOf($export, ['passphrase' => self::PASSPHRASE]);
        $this->assertTrue($import->plan['can_apply'], json_encode($import->plan));
        $done = $this->apply($import, ['passphrase' => self::PASSPHRASE]);
        $this->assertSame('completed', $done->status, json_encode($done->report));

        $project = Project::query()->where('name', 'api')->firstOrFail();
        $this->assertNotSame($source->id, $project->id);
        $domain = ProjectDomain::query()->where('domain', 'api.example.com')->firstOrFail();
        $this->assertSame($project->id, $domain->project_id);
        $this->assertSame(ProjectDomain::STATUS_PENDING, $domain->status, 'verified again');

        $channel = AlertChannel::query()->where('name', 'pager')->firstOrFail();
        $this->assertTrue($channel->is_enabled, 'its secrets came with the package');
        $this->assertSame('https://hooks.example.com/x?token=abc', $channel->config['url']);

        $this->assertSame('acme-status', StatusPage::query()->value('slug'));
        // the importing member's gateway routes the restored project
        // ... with its authentication: a key route is never imported public
        $this->assertDatabaseHas('gateway_routes', ['project_id' => $project->id, 'path' => '/orders', 'auth_mode' => 'key']);
        $this->assertContains('dns', array_column($done->report['manual_steps'], 'code'));
        $this->assertDatabaseHas('audit_events', ['action' => 'org.import.completed']);
    }

    public function test_secret_values_held_in_the_region_are_exported_and_restored_into_the_copys_region(): void
    {
        // pass-through (the default storage): the values live in the region's Kubernetes Secret
        config(['secrets.storage' => 'region']);
        $this->fakeRegionSecrets();
        $region = $this->regionWithCluster('op');
        $source = Project::create(['name' => 'orders', 'service_type' => 'faas', 'runtime' => 'python', 'created_by' => (string) $this->owner->id]);
        $source->forceFill(['knative_region_id' => $region->id])->saveQuietly();
        FaasConfig::create(['project_id' => $source->id, 'handler' => 'main.handler', 'trigger_type' => 'http']);
        app(GitService::class)->commitFiles($source, ['main.py' => "def handler(e):\n    return 'ok'\n"], 'first', (string) $this->owner->id);
        app(ProjectSecrets::class)->setValues($source->fresh(), ['API_KEY' => 'sk_region_7'], $this->owner->id);
        $this->assertSame(['API_KEY' => 'sk_region_7'], $this->regionSecrets->values($region, 'orders-secret-env'));

        $export = $this->export(['include_secrets' => true]);
        $this->assertSame('completed', $export->status, (string) $export->message);
        $this->assertSame([], $export->report['warnings'] ?? [], 'every value read from the region');

        $import = $this->importOf($export);
        $choices = ['renames' => [$source->id => 'orders-2'], 'passphrase' => self::PASSPHRASE];
        $this->assertTrue(app(OrgImporter::class)->plan($import, ImportChoices::from($choices))['can_apply']);
        $done = $this->apply($import, $choices);
        $this->assertSame('completed', $done->status, json_encode($done->report));

        $copy = Project::query()->where('name', 'orders-2')->firstOrFail();
        $this->assertSame(['API_KEY'], ProjectSecret::query()->where('project_id', $copy->id)->pluck('name')->all());
        $this->assertSame(['API_KEY' => 'sk_region_7'], $this->regionSecrets->values($region, 'orders-2-secret-env'));
        $this->assertNotContains('secrets', array_column($done->report['manual_steps'], 'code'));
    }

    public function test_without_the_passphrase_secrets_are_manual_steps(): void
    {
        $source = $this->seedOrganization();
        $export = $this->export(['include_secrets' => true]);
        AlertChannel::query()->delete();
        $source->delete();
        Binding::query()->delete();

        $import = $this->importOf($export);
        $done = $this->apply($import, []);

        $project = Project::query()->where('name', 'api')->firstOrFail();
        $this->assertSame(0, ProjectSecret::query()->where('project_id', $project->id)->count());
        $secrets = collect($done->report['manual_steps'])->firstWhere('code', 'secrets');
        $this->assertContains('api: API_KEY', $secrets['items']);
        $channel = AlertChannel::query()->where('name', 'pager')->firstOrFail();
        $this->assertFalse($channel->is_enabled, 'without its webhook it stays off');
        $this->assertContains('channel_secrets', array_column($done->report['manual_steps'], 'code'));
    }

    public function test_the_plan_checks_the_runtime_version_among_the_installed_versions(): void
    {
        // a built-in runtime's manifest lists no versions: executor:sync puts them in executor_images
        ExecutorImage::create(['runtime' => 'python', 'version' => '3.12', 'docker_image' => 'ghcr.io/brf-tech/runr/exec-python:3.12', 'is_default' => true, 'is_active' => true]);
        $source = $this->seedOrganization();
        $source->forceFill(['runtime_version' => '3.12'])->saveQuietly();
        $export = $this->export([]);
        $source->delete();

        $plan = $this->importOf($export)->plan;
        $this->assertNotContains('runtime_version_missing', array_column($plan['projects'][0]['issues'], 'code'), json_encode($plan['projects'][0]['issues']));

        // a version this installation does not run blocks
        ExecutorImage::query()->where('version', '3.12')->update(['is_active' => false]);
        $plan = $this->importOf($export)->plan;
        $this->assertContains('runtime_version_missing', array_column($plan['projects'][0]['issues'], 'code'));
        $this->assertFalse($plan['can_apply']);
    }

    public function test_an_unmapped_region_blocks_the_plan(): void
    {
        $source = $this->seedOrganization();
        $export = $this->export([]);
        $source->delete();
        $this->region->delete();
        KnativeRegion::create(['name' => 'us-1', 'project_namespace' => 'us-1', 'is_active' => true, 'is_demo' => true]);
        KnativeRegion::create(['name' => 'ap-1', 'project_namespace' => 'ap-1', 'is_active' => true, 'is_demo' => true]);

        $import = $this->importOf($export);
        $this->assertFalse($import->plan['can_apply']);
        $this->assertContains('region_unmapped', array_column($import->plan['projects'][0]['issues'], 'code'));
        $this->assertCount(2, $import->plan['regions'][0]['options']);

        $target = KnativeRegion::query()->where('name', 'ap-1')->value('id');
        $plan = app(OrgImporter::class)->plan($import, ImportChoices::from(['region_map' => [$this->region->id => $target]]));
        $this->assertSame($target, $plan['projects'][0]['target_region_id']);
        $this->assertNotContains('region_unmapped', array_column($plan['projects'][0]['issues'], 'code'));
    }

    public function test_a_package_of_a_newer_schema_is_rejected(): void
    {
        $this->seedOrganization();
        $export = $this->export([]);

        // Rewrite the package with a higher schema version
        $dir = $this->testStorage.'/x';
        $manifest = (new PackageReader)->extract(OrgTransferPaths::package($export), $dir);
        $manifest['schema_version'] = PackageFormat::SCHEMA_VERSION + 1;
        unset($manifest['entries']);
        (new PackageWriter)->pack($dir, $manifest, OrgTransferPaths::package($export));

        try {
            $this->importOf($export);
            $this->fail('a newer schema was imported');
        } catch (PackageException $e) {
            $this->assertSame('schema_too_new', $e->errorCode);
        }
        $import = OrgTransfer::query()->where('kind', 'import')->latest()->first();
        $this->assertSame('failed', $import->status);
        $this->assertDatabaseHas('audit_events', ['action' => 'org.import.rejected']);
    }
}
