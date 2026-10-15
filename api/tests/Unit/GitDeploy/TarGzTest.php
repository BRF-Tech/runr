<?php

namespace Tests\Unit\GitDeploy;

use App\Services\GitDeploy\ImportException;
use App\Services\GitDeploy\Providers\TarGz;
use Tests\TestCase;

/** Provider source archives: streamed inflate + tar parsing, top directory, pax names, filters. */
class TarGzTest extends TestCase
{
    public function test_files_come_back_without_the_top_directory(): void
    {
        $big = str_repeat("0123456789abcdef\n", 9000); // spans several inflate chunks
        $archive = TarGz::create(['main.py' => "def handler(e, c):\n    pass\n", 'lib/util.py' => 'x = 1', 'big.txt' => $big, 'empty' => '']);

        $result = TarGz::extract($archive);

        $this->assertSame(['main.py', 'lib/util.py', 'big.txt', 'empty'], array_keys($result['files']));
        $this->assertSame($big, $result['files']['big.txt']);
        $this->assertSame('', $result['files']['empty']);
        $this->assertSame([], $result['skipped']);
    }

    public function test_only_kept_paths_are_held(): void
    {
        $archive = TarGz::create(['services/a/main.py' => 'a', 'services/b/main.py' => 'b', 'runr.yaml' => 'name: a']);

        $result = TarGz::extract($archive, fn (string $path) => str_starts_with($path, 'services/a/') || $path === 'runr.yaml');

        $this->assertSame(['services/a/main.py', 'runr.yaml'], array_keys($result['files']));
    }

    public function test_pax_long_names_and_global_headers(): void
    {
        $long = 'repo-abc/'.str_repeat('d', 120).'/handler.py';
        $tar = self::entry('pax_global_header', "52 comment=a10867b14bb761a232cd80139fbd4c0d33264240\n", 'g')
            .self::entry('PaxHeader/x', self::pax('path', $long), 'x')
            .self::entry(substr($long, 0, 100), "def handler(): pass\n")
            .self::entry('repo-abc/link', '', '2')
            .str_repeat("\0", 1024);

        $result = TarGz::extract(gzencode($tar));

        $this->assertSame([str_repeat('d', 120).'/handler.py'], array_keys($result['files']));
        $this->assertSame([['path' => 'link', 'reason' => 'symlink']], $result['skipped']);
    }

    public function test_something_else_than_an_archive_is_refused(): void
    {
        $this->expectException(ImportException::class);

        TarGz::extract(gzencode(str_repeat('<html>not a tar</html>', 40)));
    }

    public function test_kept_files_above_the_ceiling_are_refused(): void
    {
        $this->expectException(ImportException::class);

        TarGz::extract(TarGz::create(['a.txt' => str_repeat('x', 2000)]), null, 1000);
    }

    private static function entry(string $name, string $content, string $type = '0'): string
    {
        $header = str_pad($name, 100, "\0")
            ."0000644\0"."0000000\0"."0000000\0"
            .str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT)."\0"
            .str_pad(decoct(1760000000), 11, '0', STR_PAD_LEFT)."\0"
            .'        '.$type.str_repeat("\0", 100)."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155);
        $header = str_pad($header, 512, "\0");
        $checksum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, str_pad(decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ", 148, 8);

        return $header.$content.str_repeat("\0", (512 - strlen($content) % 512) % 512);
    }

    private static function pax(string $key, string $value): string
    {
        // "<length> <key>=<value>\n", the length counting its own digits
        $record = " {$key}={$value}\n";
        $length = strlen($record) + 1;
        while ($length !== strlen($record) + strlen((string) $length)) {
            $length = strlen($record) + strlen((string) $length);
        }

        return $length.$record;
    }
}
