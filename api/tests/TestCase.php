<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** storage_path() of the running test, a throwaway directory */
    protected string $testStorage;

    /**
     * Every test gets a storage directory of its own (as GitDeployFixtures does for the git
     * deploy tests): the internal git repositories (GitService::repoPath(), storage/git), the
     * project remotes and builds a test writes never land in the checkout's storage/, where git
     * would also find the checkout's own repository around them.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->testStorage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'runr-test-storage-'.bin2hex(random_bytes(5));
        mkdir($this->testStorage.'/app', 0777, true);
        mkdir($this->testStorage.'/framework/cache', 0777, true);
        $this->app->useStoragePath($this->testStorage);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (isset($this->testStorage) && str_contains($this->testStorage, 'runr-test-storage-') && is_dir($this->testStorage)) {
            self::removeDirectory($this->testStorage);
        }
    }

    protected static function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            @chmod($item->getPathname(), 0777);
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
