<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Real image files for upload tests without the GD extension (UploadedFile::fake()->image()
 * needs GD; the CI image has none and the API itself never uses it).
 */
final class FakeImages
{
    /** A valid 1×1 PNG. */
    private const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public static function png(string $name = 'image.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode(self::PNG_1X1, true));
    }
}
