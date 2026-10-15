<?php

// Roles & permissions (docs/permissions.md)
return [
    'forbidden' => 'You do not have permission to do this (requires :permission).',
    'route_not_mapped' => 'This endpoint has no permission rule and is refused.',
    'platform_admin_required' => 'Only platform administrators can do this.',
    'not_a_member' => 'This user is not a member of the organization.',
    'cannot_change_own_role' => 'You cannot change your own role.',
    'unknown_role' => 'This role does not exist.',
    'last_owner' => 'The organization needs at least one owner.',
    'owner_only' => 'Only owners can grant or revoke the Owner role.',
    'cannot_grant_role' => 'You cannot grant or revoke the role ":role": it has permissions you do not have.',
    'owner_not_assignable' => 'The Owner role can only be given to members directly.',
    'cannot_grant_permissions' => 'You cannot give out permissions you do not have.',
    'unknown_permissions' => 'Unknown permissions: :permissions.',
    'unknown_subject' => 'This user or group does not exist in the organization.',
    'role_key_taken' => 'A role with this name already exists.',
    'role_deleted' => 'Role deleted. Its members now have the default role.',
    'builtin_read_only' => 'Built-in roles cannot be changed.',
    'group_deleted' => 'Group deleted.',
    'member_role_updated' => 'Role updated.',
    'access_revoked' => 'Access removed.',
    'token_abilities_invalid' => 'A token can only carry permissions you have: :abilities.',
    'mapping_deleted' => 'Group mapping deleted.',
    'unknown_provider' => 'This sign-in provider does not exist.',
    'mapping_needs_target' => 'Choose a group, a role, or both.',
];
