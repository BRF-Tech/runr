<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\ImportException;
use App\Services\GitDeploy\SourceTree;
use Tests\TestCase;

/** Which files of a commit become the version — the CLI's rules (cli/internal/projectfile). */
class SourceTreeTest extends TestCase
{
    public function test_default_ignores_hidden_files_and_the_project_file(): void
    {
        $tree = SourceTree::fromRepository([
            'main.py' => "def handler(e, c): pass\n",
            'node_modules/a/b.js' => 'x',
            '__pycache__/main.pyc' => 'x',
            'lib.pyc' => 'x',
            '.env' => 'SECRET=1',
            '.env.local' => 'SECRET=2',
            '.eslintrc' => '{}',
            '.github/workflows/ci.yml' => 'on: push',
            'runr.yaml' => "name: orders\n",
            'runr.yml' => "name: other\n",
        ]);

        $this->assertSame(['main.py'], array_keys($tree->files));
        $this->assertSame("name: orders\n", $tree->runrYaml);
        $this->assertEqualsCanonicalizing(
            ['.eslintrc', '.github/workflows/ci.yml'],
            array_column(array_filter($tree->skipped, fn ($s) => $s['reason'] === 'hidden'), 'path'),
        );
    }

    public function test_ignore_patterns_of_runr_yaml_and_runrignore(): void
    {
        $tree = SourceTree::fromRepository([
            'main.py' => 'x',
            'README.md' => 'x',
            'docs/a.md' => 'x',
            'docs/b.txt' => 'x',
            'tests/test.py' => 'x',
            'notes.txt' => 'x',
            'runr.yaml' => "ignore: [\"*.md\", \"tests\"]\n",
            '.runrignore' => "# comment\ndocs/*.txt\nnotes.txt\n",
        ]);

        $this->assertSame(['main.py'], array_keys($tree->files));
    }

    public function test_the_root_directory_and_its_project_file(): void
    {
        $repo = [
            'services/orders/main.py' => 'orders',
            'services/orders/runr.yaml' => "name: orders\n",
            'services/billing/main.py' => 'billing',
            'runr.yaml' => "name: root\n",
        ];

        $tree = SourceTree::fromRepository($repo, 'services/orders/');
        $this->assertSame(['main.py' => 'orders'], $tree->files);
        $this->assertSame("name: orders\n", $tree->runrYaml);

        unset($repo['services/orders/runr.yaml']);
        $this->assertSame("name: root\n", SourceTree::fromRepository($repo, 'services/orders')->runrYaml);
    }

    public function test_a_function_is_a_flat_set_of_text_files(): void
    {
        (new SourceTree(['main.py' => 'x', 'util.py' => 'y']))->assertFunctionFiles();

        $this->assertImportFails(new SourceTree(['main.py' => 'x', 'lib/a.py' => 'y']), 'lib/a.py');
        $this->assertImportFails(new SourceTree(['main.py' => 'x', 'logo.png' => "\x89PNG\0\0"]), 'logo.png is not UTF-8 text');
        $this->assertImportFails(new SourceTree(['main.py' => 'x', '__init__.py' => '']), '__init__.py is empty');
    }

    public function test_the_code_limit_counts_names_and_contents(): void
    {
        config(['git-deploy.max_import_bytes' => 100]);

        (new SourceTree(['a.py' => str_repeat('x', 96)]))->assertWithinLimits();
        $this->assertImportFails(new SourceTree(['a.py' => str_repeat('x', 97)]), 'the limit is 100 bytes');

        config(['git-deploy.max_import_files' => 1]);
        $this->assertImportFails(new SourceTree(['a.py' => 'x', 'b.py' => 'y']), 'at most 1');
    }

    public function test_cli_pattern_matching(): void
    {
        $this->assertTrue(SourceTree::ignored('a/b/node_modules/x.js', SourceTree::DEFAULT_IGNORE));
        $this->assertTrue(SourceTree::ignored('docs/x.md', ['docs/*.md']));
        $this->assertFalse(SourceTree::ignored('docs/sub/x.md', ['docs/*.md']));
        $this->assertTrue(SourceTree::ignored('docs/sub/x.md', ['docs/sub']));
        $this->assertTrue(SourceTree::ignored('x.md', ['/*.md/']));
        $this->assertFalse(SourceTree::ignored('main.py', ['# *.py', '']));
        $this->assertFalse(SourceTree::isManaged('.config.json'));
        $this->assertFalse(SourceTree::isManaged('runr.yaml'));
        $this->assertTrue(SourceTree::isManaged('handler.py'));
    }

    private function assertImportFails(SourceTree $tree, string $message): void
    {
        try {
            $tree->assertFunctionFiles();
            $this->fail('expected the import to fail');
        } catch (ImportException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }
}
