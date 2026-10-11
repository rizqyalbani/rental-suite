<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class InvoiceImageRaster
{
    public static function fromPngFileWithoutTrim(string $pngPath): ?string
    {
        if (! is_file($pngPath)) {
            return null;
        }

        $pngBinary = @file_get_contents($pngPath);

        return is_string($pngBinary) && $pngBinary !== ''
          ? self::fromPngBlobWithoutTrim($pngBinary)
          : null;
    }

    public static function fromPngBlobWithoutTrim(string $pngBinary): ?string
    {
        if (class_exists(\Imagick::class)) {
            self::ensureImagickTempDir();
            $im = null;

            try {
                $im = new \Imagick;
                $im->readImageBlob($pngBinary);
                $im->setImageBackgroundColor(new \ImagickPixel('white'));
                $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $flattened = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                if ($flattened instanceof \Imagick) {
                    $im->clear();
                    $im = $flattened;
                }
                self::applyImagickPadding($im);

                return self::finalizeTrimmedImagickJpeg($im);
            } catch (\Throwable $e) {
                Log::warning('Invoice PNG raster convert failed.', [
                    'message' => $e->getMessage(),
                ]);
            } finally {
                if ($im instanceof \Imagick) {
                    $im->clear();
                }
            }
        }

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $gd = @imagecreatefromstring($pngBinary);
        if ($gd === false) {
            return null;
        }

        $paddingPx = self::marginPaddingPx();
        if ($paddingPx > 0) {
            $w = imagesx($gd);
            $h = imagesy($gd);
            $canvas = imagecreatetruecolor($w + ($paddingPx * 2), $h + ($paddingPx * 2));
            if ($canvas === false) {
                imagedestroy($gd);

                return null;
            }
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopy($canvas, $gd, $paddingPx, $paddingPx, 0, 0, $w, $h);
            imagedestroy($gd);
            $gd = $canvas;
        }

        ob_start();
        $written = imagejpeg($gd, null, self::jpegQuality());
        $jpgBinary = ob_get_clean();
        imagedestroy($gd);

        return $written !== false && is_string($jpgBinary) && $jpgBinary !== '' ? $jpgBinary : null;
    }

    public static function fromPdfBlob(string $pdfContent): ?string
    {
        if (! class_exists(\Imagick::class)) {
            return null;
        }

        self::ensureImagickTempDir();
        $im = null;

        try {
            $im = new \Imagick;
            $im->setResolution(144, 144);
            $im->setBackgroundColor(new \ImagickPixel('white'));
            $im->readImageBlob($pdfContent);

            // A tall receipt can spill past one A4 sheet, so Dompdf paginates
            // it onto a 2nd/3rd PDF page. Rasterizing only page 0 (the old
            // behavior) silently dropped everything after the first page
            // break — Total/Paid/Change/footer gone. Flatten every page,
            // trim each one's own margin whitespace so the page-break seam
            // is as tight as possible, then stack them into one image.
            $fuzz = self::imagickTrimFuzz();
            foreach ($im as $frame) {
                if ($frame->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
                    $frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
                }
                $frame->setImageBackgroundColor(new \ImagickPixel('white'));
                $frame->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                $frame->trimImage($fuzz);
                $frame->setImagePage(0, 0, 0, 0);
            }
            $im->resetIterator();
            $stacked = $im->appendImages(true);
            $stacked->setImagePage(0, 0, 0, 0);
            $im->clear();
            $im = $stacked;

            return self::finalizeTrimmedImagickJpeg(self::trimImagick($im));
        } catch (\Throwable $e) {
            Log::warning('Invoice PDF raster trim failed.', [
                'message' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($im instanceof \Imagick) {
                $im->clear();
            }
        }
    }

    public static function fromPngFileWithTrim(string $pngPath): ?string
    {
        if (! is_file($pngPath)) {
            return null;
        }

        $pngBinary = @file_get_contents($pngPath);

        return is_string($pngBinary) && $pngBinary !== ''
          ? self::fromPngBlobWithTrim($pngBinary)
          : null;
    }

    public static function fromPngBlobWithTrim(string $pngBinary): ?string
    {
        if (class_exists(\Imagick::class)) {
            self::ensureImagickTempDir();
            $im = null;

            try {
                $im = new \Imagick;
                $im->readImageBlob($pngBinary);

                return self::finalizeTrimmedImagickJpeg(self::trimImagick($im));
            } catch (\Throwable $e) {
                Log::warning('Invoice PNG raster trim failed.', [
                    'message' => $e->getMessage(),
                ]);
            } finally {
                if ($im instanceof \Imagick) {
                    $im->clear();
                }
            }
        }

        return self::fromPngBlobWithGdTrim($pngBinary);
    }

    private static function ensureImagickTempDir(): void
    {
        $tmp = storage_path('app/tmp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0755, true);
        }
        if (is_dir($tmp)) {
            @putenv('MAGICK_TEMPORARY_PATH='.$tmp);
            @putenv('MAGICK_TMPDIR='.$tmp);
            @putenv('TMPDIR='.$tmp);
        }
    }

    private static function fromPngBlobWithGdTrim(string $pngBinary): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $gd = @imagecreatefromstring($pngBinary);
        if ($gd === false) {
            return null;
        }

        $gd = self::trimGdNearWhite($gd);
        if ($gd === false) {
            return null;
        }

        $paddingPx = self::marginPaddingPx();
        if ($paddingPx > 0) {
            $w = imagesx($gd);
            $h = imagesy($gd);
            $canvas = imagecreatetruecolor($w + ($paddingPx * 2), $h + ($paddingPx * 2));
            if ($canvas === false) {
                imagedestroy($gd);

                return null;
            }
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $white);
            imagecopy($canvas, $gd, $paddingPx, $paddingPx, 0, 0, $w, $h);
            imagedestroy($gd);
            $gd = $canvas;
        }

        ob_start();
        $written = imagejpeg($gd, null, self::jpegQuality());
        $jpgBinary = ob_get_clean();
        imagedestroy($gd);

        return $written !== false && is_string($jpgBinary) && $jpgBinary !== '' ? $jpgBinary : null;
    }

    private static function trimImagick(\Imagick $im): \Imagick
    {
        $im->trimImage(self::imagickTrimFuzz());
        $im->setImagePage(0, 0, 0, 0);
        self::applyImagickPadding($im);

        return $im;
    }

    private static function imagickTrimFuzz(): float
    {
        $fuzz = self::trimFuzz();
        if (str_ends_with($fuzz, '%')) {
            $percent = (float) rtrim($fuzz, '%');
            $range = \Imagick::getQuantumRange();
            $maxQuantum = (float) ($range['quantumRangeLong'] ?? 65535);

            return max(0.0, ($percent / 100.0) * $maxQuantum);
        }

        return max(0.0, (float) $fuzz);
    }

    private static function finalizeTrimmedImagickJpeg(\Imagick $im): ?string
    {
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(self::jpegQuality());
        $jpgBinary = $im->getImageBlob();

        return is_string($jpgBinary) && $jpgBinary !== '' ? $jpgBinary : null;
    }

    private static function applyImagickPadding(\Imagick $im): void
    {
        $paddingPx = self::marginPaddingPx();
        if ($paddingPx > 0) {
            $im->borderImage(new \ImagickPixel('white'), $paddingPx, $paddingPx);
        }
    }

    private static function trimGdNearWhite(\GdImage|false $gd): \GdImage|false
    {
        if ($gd === false) {
            return false;
        }

        $w = imagesx($gd);
        $h = imagesy($gd);
        $minX = $w;
        $minY = $h;
        $maxX = 0;
        $maxY = 0;
        $thr = self::gdTrimThreshold();

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb = imagecolorat($gd, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if (! ($r >= 255 - $thr && $g >= 255 - $thr && $b >= 255 - $thr)) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                    $maxX = max($maxX, $x);
                    $maxY = max($maxY, $y);
                }
            }
        }

        if ($maxX <= $minX || $maxY <= $minY) {
            return $gd;
        }

        $trimmedW = $maxX - $minX + 1;
        $trimmedH = $maxY - $minY + 1;
        $trimmed = imagecreatetruecolor($trimmedW, $trimmedH);
        if ($trimmed === false) {
            return $gd;
        }

        imagecopy($trimmed, $gd, 0, 0, $minX, $minY, $trimmedW, $trimmedH);
        imagedestroy($gd);

        return $trimmed;
    }

    private static function marginPaddingPx(): int
    {
        return max(0, (int) env('INVOICE_IMAGE_MARGIN_PX', 8));
    }

    private static function trimFuzz(): string
    {
        $trimFuzz = trim((string) env('INVOICE_IMAGE_TRIM_FUZZ', '5%'));

        return $trimFuzz !== '' ? $trimFuzz : '5%';
    }

    private static function jpegQuality(): int
    {
        return (int) min(98, max(82, (int) env('INVOICE_JPEG_QUALITY', 92)));
    }

    private static function gdTrimThreshold(): int
    {
        return max(0, min(40, (int) env('INVOICE_IMAGE_TRIM_GD_THRESHOLD', 12)));
    }
}
