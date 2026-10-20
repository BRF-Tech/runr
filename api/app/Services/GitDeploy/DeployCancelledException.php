<?php

namespace App\Services\GitDeploy;

/**
 * The deploy was cancelled after its checks passed and before its deployment was created
 * (DeploymentStarter's claim said no — e.g. a region migration rollback cancelled the queued
 * deploy that was starting): nothing was started, and nothing failed.
 */
class DeployCancelledException extends \RuntimeException {}
