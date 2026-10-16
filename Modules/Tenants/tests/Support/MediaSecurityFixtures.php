<?php

namespace Modules\Tenants\Tests\Support;

use Illuminate\Http\UploadedFile;

final class MediaSecurityFixtures
{
    public static function validJpeg(int $width = 200, int $height = 200): UploadedFile
    {
        return UploadedFile::fake()->image('valid.jpg', $width, $height);
    }

    public static function validPng(int $width = 200, int $height = 200): UploadedFile
    {
        return UploadedFile::fake()->image('valid.png', $width, $height);
    }

    public static function validWebp(int $width = 200, int $height = 200): UploadedFile
    {
        return UploadedFile::fake()->image('valid.webp', $width, $height);
    }

    public static function textPlain(): UploadedFile
    {
        return UploadedFile::fake()->create('notes.txt', 10, 'text/plain');
    }

    public static function zeroByteImage(): UploadedFile
    {
        return UploadedFile::fake()->create('empty.jpg', 0, 'image/jpeg');
    }

    public static function phpAsJpeg(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'phpjpg');
        file_put_contents($path, "<?php echo 'evil'; ?>");

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    public static function svgFile(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        return new UploadedFile($path, 'photo.svg', 'image/svg+xml', null, true);
    }

    public static function htmlFile(): UploadedFile
    {
        return UploadedFile::fake()->create('page.html', 20, 'text/html');
    }

    public static function doubleExtension(): UploadedFile
    {
        return UploadedFile::fake()->image('photo.jpg.php', 100, 100);
    }

    public static function oversized(int $kilobytes): UploadedFile
    {
        return UploadedFile::fake()->create('big.jpg', $kilobytes, 'image/jpeg');
    }

    public static function hugeDimensions(): UploadedFile
    {
        return UploadedFile::fake()->image('huge.jpg', 5000, 5000);
    }
}
