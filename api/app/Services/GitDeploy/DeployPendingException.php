<?php

namespace App\Services\GitDeploy;

/** The version's supply-chain scan or signature is still running: the deploy waits for it (DeployChecks). */
class DeployPendingException extends ImportException {}
