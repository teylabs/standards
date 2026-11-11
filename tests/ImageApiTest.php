<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tey\Standards\Conformance;
use Tey\Standards\Enforcement\Gate;

/** Rule media.image-api on a scratch app; tenantApp() and tenantConfiguration() come from TenantAwareTest, as in ChatViaChannelTest. */
function imageFindings(array $files): array
{
    $base = tenantApp($files);
    try {
        return array_map(fn (array $v): string => $v['target'].':'.$v['line'].' '.$v['check'], (new Conformance(tenantConfiguration($base)))->violations('media.image-api'));
    } finally {
        (new Filesystem)->deleteDirectory(dirname($base));
    }
}

test('media.image-api flags a raw new Imagick, a GD imagecreate call and direct Intervention ImageManager use', function () {
    expect(imageFindings([
        'Thumbnails.php' => <<<'PHP'
            use Intervention\Image\ImageManager;

            final class Thumbnails
            {
                public function make(string $path): void
                {
                    $image = new \Imagick($path);
                    $gd = imagecreatefromjpeg($path);
                    $canvas = \imagecreatetruecolor(10, 10);
                    $manager = new ImageManager(['driver' => 'gd']);
                    $other = ImageManager::gd();
                }
            }
            PHP,
        'Qualified.php' => <<<'PHP'
            final class Qualified
            {
                public function make(): void
                {
                    $manager = new \Intervention\Image\ImageManager([]);
                }
            }
            PHP,
    ]))->toBe([
        'src/Qualified.php:9 direct use of Intervention\'s ImageManager outside Media Library, with no @raw-image reason',
        'src/Thumbnails.php:11 a raw `new Imagick` outside Media Library, with no @raw-image reason',
        'src/Thumbnails.php:12 a GD `imagecreatefromjpeg()` call outside Media Library, with no @raw-image reason',
        'src/Thumbnails.php:13 a GD `imagecreatetruecolor()` call outside Media Library, with no @raw-image reason',
        'src/Thumbnails.php:14 direct use of Intervention\'s ImageManager outside Media Library, with no @raw-image reason',
        'src/Thumbnails.php:15 direct use of Intervention\'s ImageManager outside Media Library, with no @raw-image reason',
    ])->and(Gate::ADVISORY)->toContain('media.image-api');
});

test('media.image-api allows a pixel read with a nearby @raw-image reason, and only with a reason', function () {
    $reader = fn (string $comment): array => ['Palette.php' => "final class Palette\n{\n    public function dominant(string \$path): void\n    {\n        {$comment}\n        \$image = new \\Imagick(\$path);\n        \$pixel = \$image->getImagePixelColor(0, 0);\n    }\n}\n"];

    expect(imageFindings($reader('// @raw-image reads the dominant colour; the image API has no pixel access')))->toBe([])
        ->and(imageFindings($reader("// @raw-image reads pixels\n        // the API has no pixel access")))->toBe([])
        ->and(imageFindings($reader('// @raw-image')))->toHaveCount(1)
        ->and(imageFindings($reader('// @raw-imagery reads pixels')))->toHaveCount(1)
        ->and(imageFindings($reader("// @raw-image reads pixels\n\n\n")))->toHaveCount(1)
        ->and(imageFindings(['Inline.php' => "final class Inline\n{\n    public function read(\$p): void\n    {\n        \$gd = imagecreatefrompng(\$p); // @raw-image samples one pixel\n    }\n}\n"]))->toBe([]);
});

test('media.image-api allows vendor and Media Library code, by path or by its Spatie\MediaLibrary import', function () {
    expect(imageFindings([
        'vendor/acme/imaging/Resizer.php' => "final class Resizer\n{\n    public function make(\$p): void\n    {\n        \$image = new \\Imagick(\$p);\n    }\n}\n",
        'Support/MediaLibrary/ImagickGenerator.php' => <<<'PHP'
            final class ImagickGenerator
            {
                public function convert(string $path): void
                {
                    $image = new \Imagick($path);
                }
            }
            PHP,
        'Conversions/PdfPreview.php' => <<<'PHP'
            use Spatie\MediaLibrary\Conversions\ImageGenerators\ImageGenerator;

            final class PdfPreview extends ImageGenerator
            {
                public function convert(string $file): void
                {
                    $gd = imagecreatefromstring(file_get_contents($file));
                }
            }
            PHP,
    ]))->toBe([]);
});

test('media.image-api ignores mentions in comments and strings, and unrelated names', function () {
    expect(imageFindings(['Notes.php' => <<<'PHP'
        use App\Images\ImageManager;

        final class Notes
        {
            // new Imagick($path) and imagecreatefromjpeg() are for pixel reads only.
            public const DRIVER = 'Imagick';

            public function make(): void
            {
                $manager = new ImageManager();
                $this->imagecreate();
                $class = \Imagick::class;
            }
        }
        PHP]))->toBe([]);
});

test('media.image-api allows a custom driver for Laravel\'s image API, implemented or registered with Image::extend', function () {
    expect(imageFindings([
        'Images/HeicDriver.php' => <<<'PHP'
            use Illuminate\Contracts\Image\Driver;

            final class HeicDriver implements Driver
            {
                public function load(string $path): void
                {
                    $image = new \Imagick($path);
                }
            }
            PHP,
        'Providers/ImageServiceProvider.php' => <<<'PHP'
            use Illuminate\Support\Facades\Image;

            final class ImageServiceProvider
            {
                public function boot(): void
                {
                    Image::extend('heic', fn () => new class { public function load(string $p): void { $image = new \Imagick($p); } });
                }
            }
            PHP,
    ]))->toBe([])
        ->and(imageFindings(['Images/Other.php' => "use App\\Contracts\\Driver;\n\nfinal class Other implements Driver\n{\n    public function load(\$p): void\n    {\n        \$image = new \\Imagick(\$p);\n    }\n}\n"]))->toHaveCount(1);
});
