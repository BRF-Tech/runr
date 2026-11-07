<?php

namespace App\Services\Runtimes\Sources;

/**
 * What one read of a source returned: its revision (the catalog's manifest digest, the commit of
 * a Git source) and, unless $unchanged, the runtime entries and the patches it carries.
 *
 * An entry: path (runtimes/<id>/runtime.yaml), data (the decoded manifest, "@file" build files
 * inlined) or problem (why the file could not be read), files (the runtime's uploaded files: its
 * directory, for dockerfile versions).
 */
final class SourceSnapshot
{
    /**
     * @param  list<array{path: string, data: array|null, files: array<string, string>, problem: string|null}>  $entries
     * @param  list<array>|null  $patches  patch-feed v1 entries of a signed catalog
     */
    public function __construct(
        public readonly string $revision,
        public readonly array $entries = [],
        public readonly ?array $patches = null,
        public readonly bool $signatureVerified = false,
        public readonly bool $unchanged = false,
    ) {}

    public static function unchanged(string $revision, bool $signatureVerified): self
    {
        return new self($revision, [], null, $signatureVerified, true);
    }
}
