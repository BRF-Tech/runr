<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\GitService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * GitService: every project of an organization shares one working tree and index, and the API's
 * Octane workers, Horizon and the scheduler write to it at the same time. Two concurrent saves
 * met in `git add` ("index.lock: File exists", a 500 for one of them), so writes take a lock per
 * repository. (The test's storage directory is its own: Tests\TestCase.)
 */
class GitServiceLockTest extends TestCase
{
    private function lockKey(GitService $git): string
    {
        return 'runr-git-internal:'.md5($git->repoPath());
    }

    public function test_a_write_holds_the_repository_lock_and_releases_it(): void
    {
        $git = new GitService;
        $taken = null;

        $result = $git->exclusive(function () use ($git, &$taken) {
            // another worker would not get the lock now
            $taken = ! Cache::lock($this->lockKey($git), 5)->get();

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertTrue($taken, 'the lock was free while the write ran');
        $this->assertTrue(Cache::lock($this->lockKey($git), 5)->get(), 'the lock stayed taken after the write');
    }

    public function test_the_lock_is_reentrant_within_one_process(): void
    {
        $git = new GitService;

        // VersionImporter commits through GitService inside its own exclusive() block
        $this->assertSame('inner', $git->exclusive(fn () => $git->exclusive(fn () => 'inner')));
    }

    public function test_the_lock_is_released_when_the_write_fails(): void
    {
        $git = new GitService;

        try {
            $git->exclusive(fn () => throw new \RuntimeException('git failed'));
            $this->fail('the exception was swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('git failed', $e->getMessage());
        }

        $this->assertTrue(Cache::lock($this->lockKey($git), 5)->get());
    }

    public function test_writes_commit_through_the_lock(): void
    {
        $git = new GitService;
        $project = new Project;
        $project->id = 'p-lock';

        $first = $git->writeFiles($project, ['.config.json' => '{"a":1}'], 'config: a');
        $second = $git->writeFiles($project, ['.config.json' => '{"a":2}'], 'config: b');

        $this->assertNotSame($first, $second);
        $this->assertSame('{"a":2}', $git->getFileAtVersion($project, $second, '.config.json'));
        $this->assertTrue(Cache::lock($this->lockKey($git), 5)->get(), 'a write left the lock taken');
    }
}
