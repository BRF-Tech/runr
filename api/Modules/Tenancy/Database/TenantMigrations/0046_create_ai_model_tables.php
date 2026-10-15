<?php

/**
 * Tenant copy of database/migrations/2026_10_10_300002_create_ai_model_tables.php: models,
 * snapshots, model services, project bindings, uploads, the Hugging Face token and model usage
 * belong to the organization, so every tenant schema has its own tables (same definition).
 */
return require base_path('database/migrations/2026_10_10_300002_create_ai_model_tables.php');
