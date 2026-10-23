<?php

/**
 * Tenant copy of database/migrations/2026_10_21_110002_add_runtime_patch_columns.php: the
 * organization's projects, deployments and versions record the runtime patch they run, and its
 * patch migrations (apply, pin, the automatic one at a deadline) live next to them. The patch
 * feed and the patches themselves are central (2026_10_21_110001).
 */
return require base_path('database/migrations/2026_10_21_110002_add_runtime_patch_columns.php');
