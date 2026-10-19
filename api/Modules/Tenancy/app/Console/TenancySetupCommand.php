<?php

namespace Modules\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Tenancy\Services\SingleTenantConversion;
use Modules\Tenancy\Services\TenantSchema;

/**
 * Converts a single-organization installation into the first organization of a multi-tenant one
 * (SingleTenantConversion, docs/tenancy.md). Safe to run again: a conversion that stopped
 * continues where it stopped, a finished one, a fresh installation and an installation that
 * already has organizations are left as they are.
 */
class TenancySetupCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'tenancy:setup
        {--name=Default : Name of the organization the installation becomes}
        {--slug= : Its slug (project names, git paths and remote URLs); default: the git remote owner of the installation (GIT_REMOTE_OWNER, "runr"), so existing git remotes keep working}
        {--dry-run : Show what the conversion would do and change nothing}
        {--no-promote : Do not make the organization\'s owners and admins platform administrators}
        {--admin=* : Also make these accounts (username or e-mail) platform administrators}
        {--force : Run in production without asking}';

    protected $description = 'Convert a single-organization installation into the first organization (tenant) of a multi-tenant one';

    public function handle(): int
    {
        if (! config('tenancy.enabled')) {
            $this->error('TENANCY_ENABLED is not set to true. Aborting.');

            return self::FAILURE;
        }

        $central = config('tenancy.database.central_connection', 'central');
        if (! Schema::connection($central)->hasTable('tenants') || ! Schema::connection($central)->hasColumn('users', 'is_admin')) {
            $this->error('The Tenancy migrations have not run: php artisan migrate --path=Modules/Tenancy/Database/Migrations --force');

            return self::FAILURE;
        }

        $conversion = new SingleTenantConversion(function (string $level, string $message) {
            match ($level) {
                'warn' => $this->warn($message),
                'info' => $this->info($message),
                default => $this->line($message),
            };
        });

        $name = (string) $this->option('name');
        $slug = (string) ($this->option('slug') ?: config('git-deploy.owner', 'runr'));

        if ($this->option('dry-run')) {
            $this->printPlan($conversion->plan($name, $slug));

            return self::SUCCESS;
        }

        $status = $conversion->status();
        $result = match ($status) {
            SingleTenantConversion::STATUS_NOTHING => $this->nothingToDo('No single-organization data (no projects, no organization roles): nothing to convert. Create organizations in Settings → Tenants or with php artisan tenancy:create-tenant.'),
            SingleTenantConversion::STATUS_MULTI_TENANT => $this->nothingToDo('This installation already has organizations: nothing to convert. Pending tenant migrations: php artisan tenancy:migrate.'),
            SingleTenantConversion::STATUS_DONE => $conversion->runAddedSteps() !== []
                ? $this->nothingToDo('This installation was converted on '.($conversion->state()['completed_at'] ?? '?').': the steps added since have run.')
                : $this->nothingToDo('This installation was converted on '.($conversion->state()['completed_at'] ?? '?').' (organization '.($conversion->target()->slug ?? '?').'): nothing to do. Pending tenant migrations: php artisan tenancy:migrate.'),
            default => null,
        };

        if ($result === null) {
            if (! $this->confirmToProceed('This converts the installation into a multi-tenant one.')) {
                return self::FAILURE;
            }

            try {
                $tenant = $conversion->run($name, $slug, ! $this->option('no-promote'));
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                $this->line('Run the command again once the cause is fixed: it continues where it stopped.');

                return self::FAILURE;
            }

            $this->newLine();
            $this->info("Converted: the installation is organization {$tenant->name} ({$tenant->slug}).");
            $this->printQueueStreams($conversion->queueStreams($tenant));
            $this->printPlatformImages($conversion->platformImageProjects($tenant));
            $result = self::SUCCESS;
        }

        return $this->promoteNamedAccounts() && $result === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    private function nothingToDo(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    /** @param array<string, array{from: string, to: string}> $streams */
    private function printQueueStreams(array $streams): void
    {
        if ($streams === []) {
            return;
        }

        $this->line('Queue streams carry the organization\'s slug: redeploy these projects and point their producers at the new stream:');
        foreach ($streams as $project => $stream) {
            $this->line("  {$project}: {$stream['from']} → {$stream['to']}");
        }
    }

    /** @param array<string, string> $images */
    private function printPlatformImages(array $images): void
    {
        if ($images === []) {
            return;
        }

        $this->line('CaaS projects running an image uploaded as the platform keep running and redeploying with it; to change their image settings, upload the image in the organization (Settings → Images) and select it:');
        foreach ($images as $project => $image) {
            $this->line("  {$project}: {$image}");
        }
    }

    /** --admin: accounts made platform administrators in any case (also a recovery path). */
    private function promoteNamedAccounts(): bool
    {
        $names = array_filter((array) $this->option('admin'));
        if ($names === []) {
            return true;
        }

        $central = DB::connection(config('tenancy.database.central_connection', 'central'));
        if (! Schema::connection($central->getName())->hasColumn('users', 'is_admin')) {
            $this->error('users.is_admin is missing: run the Tenancy migrations first.');

            return false;
        }

        $ok = true;
        foreach ($names as $name) {
            $updated = $central->table('users')->where(fn ($q) => $q->where('username', $name)->orWhere('email', $name))->update(['is_admin' => true]);
            if ($updated > 0) {
                $this->info("Platform administrator: {$name}");
            } else {
                $this->error("No account {$name}.");
                $ok = false;
            }
        }

        return $ok;
    }

    /** @param array<string, mixed> $plan */
    private function printPlan(array $plan): void
    {
        $this->info('Dry run — nothing is changed.');
        $this->line('Status: '.$plan['status']);

        if (! in_array($plan['status'], [SingleTenantConversion::STATUS_CONVERT, SingleTenantConversion::STATUS_RESUME], true)) {
            $this->line('Nothing to convert.');

            return;
        }

        $tenant = $plan['tenant'];
        $this->line($tenant['exists']
            ? "Organization: {$tenant['name']} ({$tenant['slug']}), id {$tenant['id']} — continues after: ".(implode(', ', $plan['steps_done']) ?: 'nothing')
            : "Organization to create: {$tenant['name']} ({$tenant['slug']})");
        $this->line('Tenant migrations: '.implode(', ', array_map(fn ($p) => str_replace(base_path().DIRECTORY_SEPARATOR, '', $p), TenantSchema::paths())));

        $this->newLine();
        $this->line('Members (organization role):');
        foreach ($plan['members'] as $username => $role) {
            $this->line("  {$username}: {$role}");
        }
        $this->line('New platform administrators: '.(implode(', ', $plan['promote']) ?: 'none'));

        $this->newLine();
        $this->line('Regions (quota CPU m / memory MB, 0 = no limit):');
        foreach ($plan['regions'] as $region => $quota) {
            $this->line("  {$region}: {$quota['cpu_quota_m']} / {$quota['memory_quota_mb']}");
        }

        $this->newLine();
        $this->line('Tables copied into the organization\'s schema (rows):');
        $empty = 0;
        foreach ($plan['tables'] as $table => $info) {
            if ($info['rows'] === 0 && $info['missing_required'] === []) {
                $empty++;

                continue;
            }
            $notes = [];
            if ($info['central_only']) {
                $notes[] = 'central-only columns not copied: '.implode(', ', $info['central_only']);
            }
            if ($info['missing_required']) {
                $notes[] = 'BLOCKED, required columns without a value: '.implode(', ', $info['missing_required']);
            }
            $this->line("  {$table}: {$info['rows']}".($notes ? ' — '.implode('; ', $notes) : ''));
        }
        $this->line("  ({$empty} more table(s) without rows)");
        $this->line('Organization settings: '.(implode(', ', $plan['settings']) ?: 'none'));

        $this->newLine();
        $this->line('Central rows that get the organization\'s id:');
        foreach ($plan['central_rows'] as $table => $count) {
            $this->line("  {$table}: {$count}");
        }
        $this->line("  API tokens bound to the organization: {$plan['api_tokens']}");

        $this->newLine();
        foreach (['internal' => 'Git repository', 'remotes' => 'Git remotes'] as $key => $label) {
            $move = $plan['git'][$key];
            $this->line($move['move'] ? "{$label}: {$move['from']} → {$move['to']}" : "{$label}: nothing to move");
        }

        $this->newLine();
        $this->printQueueStreams($plan['queue_streams']);
        $this->printPlatformImages($plan['platform_images']);
    }
}
