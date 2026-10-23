<?php

namespace App\Services\OrgTransfer\Package;

/**
 * Reads a package: manifest() decompresses only up to the manifest (the first entry), extract()
 * unpacks every entry into a directory and checks it against the manifest — each entry listed,
 * of the listed size and SHA-256, no entry missing or extra, only safe relative paths. A package
 * of a newer schema version is refused before anything is unpacked.
 */
final class PackageReader
{
    /** Largest manifest read into memory. */
    private const MANIFEST_MAX_BYTES = 32 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     *
     * @throws PackageException
     */
    public function manifest(string $package): array
    {
        $zstd = Zstd::reader($package);
        try {
            $tar = new TarReader($zstd->stream());
            $first = $tar->next();
            if ($first === null || $first['name'] !== PackageFormat::MANIFEST) {
                throw new PackageException(__('org_transfer.package.no_manifest'), 'not_a_package');
            }
            $manifest = self::decode($tar->contents(self::MANIFEST_MAX_BYTES));
        } catch (PackageException $e) {
            $zstd->close(true);

            throw $e;
        }
        // Left before the end of the stream: the decompressor's exit status says nothing
        $zstd->close(true);

        PackageFormat::assertReadable($manifest);

        return $manifest;
    }

    /**
     * Unpack $package into $dir (which must be empty or missing) and verify every entry.
     *
     * @param  callable(int $done, int $total): void|null  $progress  bytes unpacked
     * @return array<string, mixed> the manifest
     *
     * @throws PackageException
     */
    public function extract(string $package, string $dir, ?callable $progress = null): array
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new PackageException(__('org_transfer.package.extract_failed'), 'package_extract_failed');
        }

        $zstd = Zstd::reader($package);
        try {
            $tar = new TarReader($zstd->stream());
            $first = $tar->next();
            if ($first === null || $first['name'] !== PackageFormat::MANIFEST) {
                throw new PackageException(__('org_transfer.package.no_manifest'), 'not_a_package');
            }
            $manifest = self::decode($tar->contents(self::MANIFEST_MAX_BYTES));
            PackageFormat::assertReadable($manifest);

            $expected = [];
            $total = 0;
            foreach ($manifest['entries'] as $entry) {
                $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';
                if (! PackageFormat::isSafePath($path) || isset($expected[$path])) {
                    throw new PackageException(__('org_transfer.package.bad_entry', ['name' => mb_substr($path, 0, 100)]), 'package_invalid');
                }
                $expected[$path] = ['size' => (int) ($entry['size'] ?? -1), 'sha256' => strtolower((string) ($entry['sha256'] ?? ''))];
                $total += max(0, (int) ($entry['size'] ?? 0));
            }

            $seen = [];
            $done = 0;
            while (($entry = $tar->next()) !== null) {
                $name = $entry['name'];
                if (! isset($expected[$name]) || isset($seen[$name])) {
                    throw new PackageException(__('org_transfer.package.unexpected_entry', ['name' => mb_substr($name, 0, 100)]), 'package_invalid');
                }
                if ($entry['size'] !== $expected[$name]['size']) {
                    throw new PackageException(__('org_transfer.package.checksum_mismatch', ['name' => $name]), 'checksum_mismatch');
                }

                $target = $dir.'/'.$name;
                if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0700, true) && ! is_dir(dirname($target))) {
                    throw new PackageException(__('org_transfer.package.extract_failed'), 'package_extract_failed');
                }
                $out = @fopen($target, 'xb');
                if ($out === false) {
                    throw new PackageException(__('org_transfer.package.extract_failed'), 'package_extract_failed');
                }
                try {
                    $sha = $tar->copyTo($out);
                } finally {
                    fclose($out);
                }
                if (! hash_equals($expected[$name]['sha256'], $sha)) {
                    throw new PackageException(__('org_transfer.package.checksum_mismatch', ['name' => $name]), 'checksum_mismatch');
                }

                $seen[$name] = true;
                $done += $entry['size'];
                if ($progress) {
                    $progress($done, $total);
                }
            }

            if ($missing = array_diff(array_keys($expected), array_keys($seen))) {
                throw new PackageException(__('org_transfer.package.missing_entries', ['names' => implode(', ', array_slice($missing, 0, 5))]), 'package_invalid');
            }
        } catch (PackageException $e) {
            $zstd->close(true);

            throw $e;
        }
        $zstd->close();

        return $manifest;
    }

    /** @return array<string, mixed> */
    private static function decode(string $json): array
    {
        try {
            $manifest = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PackageException(__('org_transfer.package.not_a_package'), 'not_a_package', $e);
        }

        return is_array($manifest) ? $manifest : throw new PackageException(__('org_transfer.package.not_a_package'), 'not_a_package');
    }
}
