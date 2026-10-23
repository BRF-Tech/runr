<?php

namespace App\Services\OrgTransfer;

/**
 * What the user decides for an import (docs/org-export.md#import): the target region of each
 * source region, new names for projects and bindings (slug collisions), projects left out, the
 * passphrase of the package's secrets, and what apply does besides creating the organization's
 * entries — restore binding data (all, or all but some bindings'), import images, import the run
 * history, invite members, deploy.
 *
 * The passphrase is never stored: toStored() leaves it out.
 */
final class ImportChoices
{
    /**
     * @param  array<string, string>  $regionMap  source region id => target region id
     * @param  array<string, string>  $renames  source project id => new name
     * @param  array<string, string>  $bindingRenames  source binding id => new name
     * @param  list<string>  $skipProjects  source project ids
     * @param  list<string>  $skipBindingData  source binding ids whose data is not restored
     */
    public function __construct(
        public readonly array $regionMap = [],
        public readonly array $renames = [],
        public readonly array $bindingRenames = [],
        public readonly array $skipProjects = [],
        public readonly ?string $passphrase = null,
        public readonly bool $restoreData = true,
        public readonly bool $images = true,
        public readonly bool $inviteMembers = false,
        public readonly bool $deploy = false,
        public readonly array $skipBindingData = [],
        public readonly bool $history = true,
    ) {}

    /** From request input / stored options; missing keys keep the $base values. */
    public static function from(array $input, ?self $base = null): self
    {
        $base ??= new self;
        $strings = fn ($v) => is_array($v) ? array_filter(array_map(fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), fn ($x) => $x !== '') : [];

        return new self(
            regionMap: array_key_exists('region_map', $input) ? $strings($input['region_map']) : $base->regionMap,
            renames: array_key_exists('renames', $input) ? $strings($input['renames']) : $base->renames,
            bindingRenames: array_key_exists('binding_renames', $input) ? $strings($input['binding_renames']) : $base->bindingRenames,
            skipProjects: array_key_exists('skip_projects', $input) ? array_values($strings($input['skip_projects'])) : $base->skipProjects,
            passphrase: isset($input['passphrase']) && is_string($input['passphrase']) && $input['passphrase'] !== '' ? $input['passphrase'] : $base->passphrase,
            restoreData: array_key_exists('restore_data', $input) ? (bool) $input['restore_data'] : $base->restoreData,
            images: array_key_exists('images', $input) ? (bool) $input['images'] : $base->images,
            inviteMembers: array_key_exists('invite_members', $input) ? (bool) $input['invite_members'] : $base->inviteMembers,
            deploy: array_key_exists('deploy', $input) ? (bool) $input['deploy'] : $base->deploy,
            skipBindingData: array_key_exists('skip_binding_data', $input) ? array_values($strings($input['skip_binding_data'])) : $base->skipBindingData,
            history: array_key_exists('history', $input) ? (bool) $input['history'] : $base->history,
        );
    }

    /** What the transfer row keeps (options): everything but the passphrase. */
    public function toStored(): array
    {
        return [
            'region_map' => (object) $this->regionMap,
            'renames' => (object) $this->renames,
            'binding_renames' => (object) $this->bindingRenames,
            'skip_projects' => $this->skipProjects,
            'restore_data' => $this->restoreData,
            'images' => $this->images,
            'invite_members' => $this->inviteMembers,
            'deploy' => $this->deploy,
            'skip_binding_data' => $this->skipBindingData,
            'history' => $this->history,
        ];
    }

    public function withPassphrase(?string $passphrase): self
    {
        return new self($this->regionMap, $this->renames, $this->bindingRenames, $this->skipProjects, $passphrase,
            $this->restoreData, $this->images, $this->inviteMembers, $this->deploy, $this->skipBindingData, $this->history);
    }

    /** Whether the data of a source binding is restored (restore_data, and not left out by skip_binding_data). */
    public function restoresDataOf(string $sourceBindingId): bool
    {
        return $this->restoreData && ! in_array($sourceBindingId, $this->skipBindingData, true);
    }
}
