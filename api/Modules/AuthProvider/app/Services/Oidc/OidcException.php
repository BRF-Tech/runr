<?php

namespace Modules\AuthProvider\Services\Oidc;

use RuntimeException;

/** The identity provider's discovery document, keys, token or user info was refused (logged, not shown). */
class OidcException extends RuntimeException {}
