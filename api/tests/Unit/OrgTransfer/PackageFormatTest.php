<?php

namespace Tests\Unit\OrgTransfer;

use App\Services\OrgTransfer\Package\PackageException;
use App\Services\OrgTransfer\Package\PackageFormat;
use App\Services\OrgTransfer\Package\PackageReader;
use App\Services\OrgTransfer\Package\PackageWriter;
use App\Services\OrgTransfer\Package\TarReader;
use App\Services\OrgTransfer\Package\TarWriter;
use App\Services\OrgTransfer\Package\Zstd;
use Tests\TestCase;

/**
 * The organization package (docs/org-export.md#package-format): a tar with manifest.json first,
 * entries checked against the manifest's sizes and SHA-256, zstd-compressed; packages of a newer
 * schema version are refused before anything is unpacked, unsafe paths never extracted.
 */
class PackageFormatTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = $this->testStorage.'/pkg';
        mkdir($this->dir.'/staging/projects/p1/code', 0777, true);
        config(['org_transfer.compression' => 'none']);
    }

    private function manifest(array $extra = []): array
    {
        return array_merge(['format' => PackageFormat::FORMAT, 'schema_version' => PackageFormat::SCHEMA_VERSION, 'runr_version' => 'v0.5.11', 'source' => ['organization' => 'Acme']], $extra);
    }

    private function stage(): void
    {
        file_put_contents($this->dir.'/staging/organization.json', '{"roles":[]}');
        file_put_contents($this->dir.'/staging/projects/p1/project.json', json_encode(['id' => 'p1', 'name' => 'api']));
        file_put_contents($this->dir.'/staging/projects/p1/code/v1.tar', str_repeat('x', 5000));
    }

    public function test_tar_round_trip_with_long_names_and_padding(): void
    {
        $path = $this->dir.'/t.tar';
        $out = fopen($path, 'wb');
        $writer = new TarWriter($out, 1700000000);
        $long = 'projects/'.str_repeat('a', 120).'/project.json';
        $sha1 = $writer->addString('a.txt', 'hello');
        $sha2 = $writer->addString($long, str_repeat('z', 513));
        $writer->finish();
        fclose($out);

        $this->assertSame(0, filesize($path) % 512);

        $in = fopen($path, 'rb');
        $reader = new TarReader($in);
        $first = $reader->next();
        $this->assertSame(['name' => 'a.txt', 'size' => 5], $first);
        $this->assertSame('hello', $reader->contents(100));
        $second = $reader->next();
        $this->assertSame($long, $second['name'], 'PAX path of a name over 100 bytes');
        $this->assertSame(513, $second['size']);
        $sink = fopen('php://memory', 'w+b');
        $this->assertSame($sha2, $reader->copyTo($sink));
        $this->assertNull($reader->next());
        fclose($in);
        $this->assertSame(hash('sha256', 'hello'), $sha1);
    }

    public function test_unread_entries_are_skipped(): void
    {
        $path = $this->dir.'/t.tar';
        $out = fopen($path, 'wb');
        $writer = new TarWriter($out);
        $writer->addString('one', str_repeat('1', 700));
        $writer->addString('two', 'second');
        $writer->finish();
        fclose($out);

        $reader = new TarReader(fopen($path, 'rb'));
        $reader->next();
        $this->assertSame('two', $reader->next()['name']);
        $this->assertSame('second', $reader->contents(10));
    }

    public function test_a_package_starts_with_its_manifest_and_unpacks_verified(): void
    {
        $this->stage();
        $package = $this->dir.'/package';

        $packed = (new PackageWriter)->pack($this->dir.'/staging', $this->manifest(), $package);
        $this->assertSame(3, $packed['entries']);
        $this->assertSame(hash_file('sha256', $package), $packed['sha256']);

        $reader = new PackageReader;
        $manifest = $reader->manifest($package);
        $this->assertSame(['organization.json', 'projects/p1/code/v1.tar', 'projects/p1/project.json'], array_column($manifest['entries'], 'path'));
        $this->assertSame(hash('sha256', str_repeat('x', 5000)), $manifest['entries'][1]['sha256']);

        $done = [];
        $reader->extract($package, $this->dir.'/out', function ($d, $t) use (&$done) {
            $done[] = [$d, $t];
        });
        $this->assertSame(str_repeat('x', 5000), file_get_contents($this->dir.'/out/projects/p1/code/v1.tar'));
        $this->assertSame(end($done)[0], end($done)[1], 'progress reaches the total');
    }

    public function test_a_changed_entry_fails_its_checksum(): void
    {
        $this->stage();
        $package = $this->dir.'/package';
        (new PackageWriter)->pack($this->dir.'/staging', $this->manifest(), $package);

        // Flip one byte of the v1.tar content inside the (uncompressed) tar
        $bytes = file_get_contents($package);
        $pos = strpos($bytes, str_repeat('x', 100));
        $bytes[$pos] = 'y';
        file_put_contents($package, $bytes);

        try {
            (new PackageReader)->extract($package, $this->dir.'/out');
            $this->fail('a damaged entry was accepted');
        } catch (PackageException $e) {
            $this->assertSame('checksum_mismatch', $e->errorCode);
        }
    }

    public function test_a_newer_schema_is_refused_with_a_clear_message(): void
    {
        $this->stage();
        $package = $this->dir.'/package';
        (new PackageWriter)->pack($this->dir.'/staging', $this->manifest(['schema_version' => PackageFormat::SCHEMA_VERSION + 1, 'runr_version' => 'v9.0.0']), $package);

        try {
            (new PackageReader)->manifest($package);
            $this->fail('a newer schema was accepted');
        } catch (PackageException $e) {
            $this->assertSame('schema_too_new', $e->errorCode);
            $this->assertStringContainsString('v9.0.0', $e->getMessage());
        }

        $this->expectException(PackageException::class);
        (new PackageReader)->extract($package, $this->dir.'/out');
    }

    public function test_entries_the_manifest_does_not_list_or_unsafe_paths_are_refused(): void
    {
        $package = $this->dir.'/evil.tar';
        $out = fopen($package, 'wb');
        $tar = new TarWriter($out);
        $manifest = $this->manifest(['entries' => [['path' => '../escape.txt', 'size' => 1, 'sha256' => hash('sha256', 'x')]]]);
        $tar->addString(PackageFormat::MANIFEST, json_encode($manifest));
        $tar->addString('../escape.txt', 'x');
        $tar->finish();
        fclose($out);

        try {
            (new PackageReader)->extract($package, $this->dir.'/out');
            $this->fail('an unsafe path was accepted');
        } catch (PackageException $e) {
            $this->assertSame('package_invalid', $e->errorCode);
        }
        $this->assertFileDoesNotExist($this->dir.'/escape.txt');

        // An extra entry the manifest does not list
        $package = $this->dir.'/extra.tar';
        $out = fopen($package, 'wb');
        $tar = new TarWriter($out);
        $tar->addString(PackageFormat::MANIFEST, json_encode($this->manifest(['entries' => []])));
        $tar->addString('projects/x.json', '{}');
        $tar->finish();
        fclose($out);

        $this->expectException(PackageException::class);
        (new PackageReader)->extract($package, $this->dir.'/out2');
    }

    public function test_not_a_package(): void
    {
        file_put_contents($this->dir.'/junk', str_repeat('not a tar', 200));

        $this->expectException(PackageException::class);
        (new PackageReader)->manifest($this->dir.'/junk');
    }

    public function test_safe_paths(): void
    {
        foreach (['manifest.json', 'projects/01abc/code/v12.tar', 'bindings/data/b-1.dump'] as $ok) {
            $this->assertTrue(PackageFormat::isSafePath($ok), $ok);
        }
        foreach (['', '/etc/passwd', '../x', 'a/../b', 'a//b', '.hidden', 'a/.git/config', 'a\\b', 'a b'] as $bad) {
            $this->assertFalse(PackageFormat::isSafePath($bad), $bad);
        }
    }

    public function test_zstd_compressed_round_trip(): void
    {
        if (! Zstd::available()) {
            $this->markTestSkipped('zstd (extension or binary) is not available here; the API image has it.');
        }
        config(['org_transfer.compression' => 'zstd']);
        $this->stage();
        $package = $this->dir.'/package.tar.zst';

        (new PackageWriter)->pack($this->dir.'/staging', $this->manifest(), $package);
        $this->assertTrue(Zstd::isCompressed($package));
        $this->assertLessThan(5000, filesize($package), 'compressed');

        $manifest = (new PackageReader)->extract($package, $this->dir.'/out');
        $this->assertSame(PackageFormat::SCHEMA_VERSION, $manifest['schema_version']);
        $this->assertFileExists($this->dir.'/out/projects/p1/project.json');
    }

    public function test_filename(): void
    {
        $at = new \DateTimeImmutable('2026-10-21 12:00:05');
        $this->assertSame('runr-org-acme-co-20261021-120005.tar.zst', PackageFormat::filename('Acme Co', $at));
        $this->assertSame('runr-org-organization-20261021-120005.tar', PackageFormat::filename(null, $at, false));
    }
}
