<?php

namespace App\Services\GitDeploy;

use RuntimeException;

/** A pushed commit cannot become a version (limits, missing handler file, EOL runtime, ...). */
class ImportException extends RuntimeException {}
