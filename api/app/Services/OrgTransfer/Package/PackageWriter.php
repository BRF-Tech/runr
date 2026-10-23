<?php

namespace App\Services\OrgTransfer\Package;

/**
 * Packs a staging directory into a package: manifest.json first (with every entry's size and
 * SHA-256, so the importer can read the manifest and plan without unpacking the rest), then the
 * entries in path order, the whole compressed with zstd.
 */
final class PackageWriter
{
    /**
     * @param  array<string, mixed>  $manifest  everything but `entries`, which is filled here
     * @param  callable(int $done, int $total): void|null  $progress  bytes packed
     * @return array{size: int, sha256: string, entries: int, manifest: array<string, mixed>}
     *
     * @throws PackageException
     */
    public function pack(string $stagingDir, array $manifest, string $target, ?callable $progress = null): array
    {
        $files = self::files($stagingDir);
        $total = array_sum(array_map(fn ($f) => $f['size'], $files));

        // Checksums first: the manifest leads the archive
        $entries = [];
        foreach ($files as $file) {
            $sha = hash_file('sha256', $file['path']);
            if ($sha === false) {
                throw new PackageException("Cannot read {$file['path']}.", 'package_write_failed');
            }
            $entries[] = ['path' => $file['name'], 'size' => $file['size'], 'sha256' => $sha];
        }
        $manifest['entries'] = $entries;
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $zstd = Zstd::writer($target, config('org_transfer.compression', 'zstd') !== 'none', (int) config('org_transfer.zstd_level', 3));
        try {
            $tar = new TarWriter($zstd->stream());
            $tar->addString(PackageFormat::MANIFEST, $json);

            $done = 0;
            foreach ($files as $i => $file) {
                $sha = $tar->addFile($file['name'], $file['path']);
                if ($sha !== $entries[$i]['sha256']) {
                    throw new PackageException("{$file['name']} changed while it was packed.", 'package_write_failed');
                }
                $done += $file['size'];
                if ($progress) {
                    $progress($done, $total);
                }
            }
            $tar->finish();
        } catch (\Throwable $e) {
            $zstd->close(true);
            @unlink($target);

            throw $e instanceof PackageException ? $e : new PackageException($e->getMessage(), 'package_write_failed', $e);
        }
        $zstd->close();

        clearstatcache(true, $target);

        return [
            'size' => (int) filesize($target),
            'sha256' => (string) hash_file('sha256', $target),
            'entries' => count($entries),
            'manifest' => $manifest,
        ];
    }

    /**
     * Regular files under $dir, relative names in sorted order; every name must be a safe package
     * path (the exporter writes nothing else).
     *
     * @return list<array{name: string, path: string, size: int}>
     */
    public static function files(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        $files = [];
        if (! is_dir($dir)) {
            return $files;
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink() || ! $file->isFile()) {
                continue;
            }
            $name = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            if ($name === PackageFormat::MANIFEST) {
                continue;
            }
            if (! PackageFormat::isSafePath($name)) {
                throw new PackageException("Unexpected file {$name} in the staging directory.", 'package_write_failed');
            }
            $files[] = ['name' => $name, 'path' => $file->getPathname(), 'size' => (int) $file->getSize()];
        }
        usort($files, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $files;
    }
}
