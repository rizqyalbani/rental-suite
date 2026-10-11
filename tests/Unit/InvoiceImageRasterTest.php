<?php

namespace Tests\Unit;

use App\Support\InvoiceImageRaster;
use Tests\TestCase;

class InvoiceImageRasterTest extends TestCase
{
    public function test_png_convert_without_trim_keeps_dimensions(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagepng') || ! function_exists('getimagesizefromstring')) {
            $this->markTestSkipped('GD is not available.');
        }

        putenv('INVOICE_IMAGE_MARGIN_PX=0');

        $im = imagecreatetruecolor(120, 80);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 0, 0, 0);
        imagefill($im, 0, 0, $white);
        imagefilledrectangle($im, 30, 20, 90, 60, $black);

        ob_start();
        imagepng($im);
        $pngBinary = ob_get_clean();
        imagedestroy($im);

        $this->assertIsString($pngBinary);
        $jpgBinary = InvoiceImageRaster::fromPngBlobWithoutTrim($pngBinary);
        $this->assertIsString($jpgBinary);
        $this->assertNotSame('', $jpgBinary);

        $info = getimagesizefromstring($jpgBinary);
        $this->assertIsArray($info);
        $this->assertSame(120, $info[0]);
        $this->assertSame(80, $info[1]);
    }

    public function test_png_trim_removes_near_white_margins(): void
    {
        if (! class_exists(\Imagick::class)) {
            $this->markTestSkipped('Imagick is not available.');
        }

        putenv('INVOICE_IMAGE_MARGIN_PX=0');
        putenv('INVOICE_IMAGE_TRIM_FUZZ=5%');

        $source = new \Imagick;
        $source->newImage(120, 80, new \ImagickPixel('white'));
        $draw = new \ImagickDraw;
        $draw->setFillColor(new \ImagickPixel('black'));
        $draw->rectangle(30, 20, 90, 60);
        $source->drawImage($draw);
        $source->setImageFormat('png');
        $pngBinary = $source->getImageBlob();
        $source->clear();

        $jpgBinary = InvoiceImageRaster::fromPngBlobWithTrim($pngBinary);
        $this->assertIsString($jpgBinary);
        $this->assertNotSame('', $jpgBinary);

        $result = new \Imagick;
        $result->readImageBlob($jpgBinary);
        $this->assertLessThan(120, $result->getImageWidth());
        $this->assertLessThan(80, $result->getImageHeight());
        $result->clear();
    }
}
