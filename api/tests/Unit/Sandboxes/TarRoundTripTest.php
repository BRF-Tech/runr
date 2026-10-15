<?php

namespace Tests\Unit\Sandboxes;

use App\Services\Sandboxes\Tar\TarReader;
use App\Services\Sandboxes\Tar\TarWriter;
use PHPUnit\Framework\TestCase;

/**
 * ustar writer / reader round trip: short names, names that need the ustar prefix, names that
 * need a GNU long-name entry, directories, binary content, modes, block padding, PAX path
 * records and corrupted headers.
 */
class TarRoundTripTest extends TestCase
{
    public function test_files_and_directories_round_trip(): void
    {
        $binary = random_bytes(1500);
        $tar = (new TarWriter)
            ->addDirectory('workspace/src', 0755, 1700000000)
            ->addFile('workspace/src/main.py', "print('hi')\n", 0644, 1700000000)
            ->addFile('/workspace/data.bin', $binary, 0600, 1700000001)
            ->addFile('workspace/empty.txt', '')
            ->bytes();

        $this->assertSame(0, strlen($tar) % 512);

        $entries = TarReader::entries($tar);
        $this->assertSame(['workspace/src/', 'workspace/src/main.py', 'workspace/data.bin', 'workspace/empty.txt'], array_column($entries, 'name'));
        $this->assertSame(['dir', 'file', 'file', 'file'], array_column($entries, 'type'));
        $this->assertSame("print('hi')\n", $entries[1]['content']);
        $this->assertSame($binary, $entries[2]['content']);
        $this->assertSame(1500, $entries[2]['size']);
        $this->assertSame(0600, $entries[2]['mode']);
        $this->assertSame(0755, $entries[0]['mode']);
        $this->assertSame(1700000001, $entries[2]['mtime']);
        $this->assertSame('', $entries[3]['content']);
    }

    public function test_long_names_use_the_ustar_prefix(): void
    {
        $name = 'workspace/'.str_repeat('deep/', 25).'file.txt'; // > 100, < 255
        $this->assertGreaterThan(100, strlen($name));

        $entries = TarReader::entries((new TarWriter)->addFile($name, 'x')->bytes());

        $this->assertSame($name, $entries[0]['name']);
        $this->assertCount(1, $entries);
    }

    public function test_very_long_names_use_a_gnu_long_name_entry(): void
    {
        $name = 'workspace/'.str_repeat('a', 180).'/'.str_repeat('b', 120).'.txt';

        $tar = (new TarWriter)->addFile($name, 'content')->bytes();
        $entries = TarReader::entries($tar);

        $this->assertCount(1, $entries);
        $this->assertSame($name, $entries[0]['name']);
        $this->assertSame('content', $entries[0]['content']);
        $this->assertStringContainsString('././@LongLink', $tar);
    }

    public function test_pax_path_records_are_read(): void
    {
        $path = 'output/plot-with-a-pax-name.png';
        $record = ' path='.$path."\n";
        $len = strlen($record);
        $len += strlen((string) ($len + strlen((string) $len)));
        $pax = $len.$record;

        // A pax header entry ('x') before a file entry with a short name
        $tar = self::rawEntry('PaxHeader/plot', $pax, 'x').self::rawEntry('plot.png', 'PNG', '0').str_repeat("\0", 1024);

        $entries = TarReader::entries($tar);
        $this->assertSame($path, $entries[0]['name']);
        $this->assertSame('PNG', $entries[0]['content']);
    }

    public function test_corrupted_headers_are_refused(): void
    {
        $tar = (new TarWriter)->addFile('a.txt', 'x')->bytes();
        $tar[10] = 'Z';

        $this->expectException(\InvalidArgumentException::class);
        TarReader::entries($tar);
    }

    public function test_an_empty_archive_has_no_entries(): void
    {
        $this->assertSame([], TarReader::entries((new TarWriter)->bytes()));
        $this->assertSame([], TarReader::entries(''));
    }

    /** One ustar entry built by hand (header + padded content). */
    private static function rawEntry(string $name, string $content, string $type): string
    {
        $header = str_pad($name, 100, "\0")
            .str_pad(decoct(0644), 7, '0', STR_PAD_LEFT)."\0"
            .str_repeat('0', 7)."\0"
            .str_repeat('0', 7)."\0"
            .str_pad(decoct(strlen($content)), 11, '0', STR_PAD_LEFT)."\0"
            .str_pad(decoct(1700000000), 11, '0', STR_PAD_LEFT)."\0"
            .'        '
            .$type
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 32 + 32 + 8 + 8 + 155 + 12);

        $sum = array_sum(array_map('ord', str_split($header)));
        $header = substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
        $pad = strlen($content) % 512 === 0 ? '' : str_repeat("\0", 512 - strlen($content) % 512);

        return $header.$content.$pad;
    }
}
