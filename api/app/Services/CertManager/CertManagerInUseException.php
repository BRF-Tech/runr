<?php

namespace App\Services\CertManager;

use RuntimeException;

/** The region's custom domains still have cert-manager certificates: cert-manager stays. */
class CertManagerInUseException extends RuntimeException {}
