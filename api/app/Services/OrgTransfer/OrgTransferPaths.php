<?php

namespace App\Services\OrgTransfer;

use App\Models\OrgTransfer;

/**
 * Files of a transfer on the storage volume the API, Horizon and the scheduler share
 * (config org_transfer.path, default storage/app/private/org-transfers):
 *
 *   <root>/<transfer id>/package     the package (an export's result, an import's upload)
 *   <root>/<transfer id>/staging/    an export's entries before they are packed
 *   <root>/<transfer id>/extracted/  an import's unpacked, verified entries
 *   <root>/<transfer id>/work/       binding dumps and images on their way to / from a region
 */
final class OrgTransferPaths
{
    public static function root(): string
    {
        $path = (string) config('org_transfer.path', '');

        return rtrim($path !== '' ? $path : storage_path('app/private/org-transfers'), '/\\');
    }

    public static function dir(OrgTransfer $transfer): string
    {
        return self::root().'/'.self::id($transfer);
    }

    public static function package(OrgTransfer $transfer): string
    {
        return self::dir($transfer).'/package';
    }

    public static function staging(OrgTransfer $transfer): string
    {
        return self::dir($transfer).'/staging';
    }

    public static function extracted(OrgTransfer $transfer): string
    {
        return self::dir($transfer).'/extracted';
    }

    public static function work(OrgTransfer $transfer): string
    {
        return self::dir($transfer).'/work';
    }

    public static function ensure(string $dir): string
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException("Cannot create {$dir}.");
        }

        return $dir;
    }

    /** Remove a transfer's files (all of them, or one of its sub-directories). */
    public static function remove(OrgTransfer $transfer, ?string $sub = null): void
    {
        $dir = self::dir($transfer).($sub !== null ? '/'.$sub : '');
        self::removeTree($dir);
    }

    /**
     * Remove $dir and what it holds — only a directory under the transfers root (never a path
     * outside it, never through a symbolic link).
     */
    public static function removeTree(string $dir): void
    {
        $root = realpath(self::root());
        $real = realpath($dir);
        if ($root === false || $real === false || $real === $root || ! str_starts_with($real, $root.DIRECTORY_SEPARATOR)) {
            return;
        }

        if (is_file($real) || is_link($dir)) {
            @unlink($dir);

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($real);
    }

    /** Transfer directories on disk whose row is gone (a deleted organization): name => path. */
    public static function orphans(): array
    {
        $root = self::root();
        if (! is_dir($root)) {
            return [];
        }

        $dirs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if (preg_match('/^[0-9A-Za-z]{26}$/', $entry) && is_dir($root.'/'.$entry)) {
                $dirs[strtolower($entry)] = $root.'/'.$entry;
            }
        }
        if ($dirs === []) {
            return [];
        }

        $known = OrgTransfer::query()->whereIn('id', array_merge(array_keys($dirs), array_map('strtoupper', array_keys($dirs))))
            ->pluck('id')->map(fn ($id) => strtolower((string) $id))->all();

        return array_diff_key($dirs, array_flip($known));
    }

    private static function id(OrgTransfer $transfer): string
    {
        $id = (string) $transfer->getKey();
        if (! preg_match('/^[0-9A-Za-z]{26}$/', $id)) {
            throw new \InvalidArgumentException('Not a transfer id.');
        }

        return strtolower($id);
    }
}
