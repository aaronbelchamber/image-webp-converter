<?php

namespace IWC\Tests\fixtures;

/**
 * Builds real image fixtures for the test suite — no pre-baked binary blobs
 * checked into git except where genuinely unavoidable. Everything is
 * generated at test-run time so the fixtures are as inspectable as the
 * tests that consume them.
 */
class FixtureFactory {
    /** Transparent PNG (truecolor+alpha, IHDR color type 6): opaque red marker block top-left, fully transparent elsewhere. */
    public static function transparentPngWithMarker(string $path, int $width = 40, int $height = 20): void {
        $img = imagecreatetruecolor($width, $height);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $red_opaque = imagecolorallocatealpha($img, 255, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 3, 3, $red_opaque);
        imagepng($img, $path);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
    }

    /** Same shape as the original smoke test's fixture: semi-transparent red ellipse over a fully transparent background. */
    public static function transparentPngEllipse(string $path, int $size = 40): void {
        $img = imagecreatetruecolor($size, $size);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefill($img, 0, 0, $transparent);
        $red_semi = imagecolorallocatealpha($img, 255, 0, 0, 40);
        imagefilledellipse($img, (int) ($size / 2), (int) ($size / 2), (int) ($size * 0.75), (int) ($size * 0.75), $red_semi);
        imagepng($img, $path);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
    }

    /** Fully opaque PNG (IHDR color type 2), solid fill. */
    public static function opaquePng(string $path, int $size = 20, array $rgb = [0, 0, 255]): void {
        $img = imagecreatetruecolor($size, $size);
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $color);
        imagepng($img, $path);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
    }

    /** Plain (non-CMYK, no EXIF) JPEG, solid fill. */
    public static function plainJpeg(string $path, int $size = 30, array $rgb = [0, 255, 0], int $quality = 90): void {
        $img = imagecreatetruecolor($size, $size);
        $color = imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
        imagefill($img, 0, 0, $color);
        imagejpeg($img, $path, $quality);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
    }

    /**
     * A syntactically minimal (but real) 26-byte PNG prefix — just the
     * signature + IHDR chunk header/dimensions/bit-depth/color-type — for
     * exercising iwc_png_has_alpha(), which only ever reads those first 26
     * bytes. No IDAT/IEND is included because the function under test never
     * reads past offset 25; building a fully valid file would add nothing
     * to what this proves.
     */
    public static function rawPngHeaderOnly(string $path, int $colorType, int $width = 10, int $height = 10): void {
        $signature = "\x89PNG\r\n\x1a\n";
        $chunkType = 'IHDR';
        $ihdrData = pack('N', $width) . pack('N', $height) . chr(8) . chr($colorType) . chr(0) . chr(0) . chr(0);
        $length = pack('N', strlen($ihdrData));
        file_put_contents($path, $signature . $length . $chunkType . $ihdrData);
    }

    /**
     * A real, fully valid indexed PNG (IHDR color type 3) using a tRNS
     * chunk for transparency — the documented false-negative case for
     * iwc_png_has_alpha(), which only recognizes color types 4 and 6.
     */
    public static function indexedPngWithTrns(string $path, int $size = 10): void {
        $img = imagecreate($size, $size);
        $transparent_index = imagecolorallocate($img, 255, 0, 255);
        imagecolortransparent($img, $transparent_index);
        $opaque = imagecolorallocate($img, 0, 128, 0);
        imagefilledrectangle($img, 0, 0, $size - 1, $size - 1, $opaque);
        imagepng($img, $path);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }
    }

    public static function isImagickAvailable(): bool {
        return class_exists('Imagick');
    }

    /**
     * A minimal, hand-built JPEG containing only the markers
     * getimagesize() actually parses (SOI, a bare-bones SOF0 with the
     * requested component count, EOI) — no real scan/quantization data.
     * getimagesize() (and thus the plugin's CMYK detection, which is
     * purely `channels === 4` from that call) never decodes pixels, so
     * this is a faithful fixture for proving the *detection* path without
     * needing Imagick (which real CMYK JPEG generation would otherwise
     * require) to build it.
     */
    public static function rawJpegWithComponentCount(string $path, int $components, int $width = 16, int $height = 16): void {
        $soi = "\xFF\xD8";
        $componentBytes = '';
        for ($i = 1; $i <= $components; $i++) {
            $componentBytes .= chr($i) . chr(0x11) . chr(0);
        }
        $sof0Payload = chr(8) . pack('n', $height) . pack('n', $width) . chr($components) . $componentBytes;
        $sof0 = "\xFF\xC0" . pack('n', strlen($sof0Payload) + 2) . $sof0Payload;
        $eoi = "\xFF\xD9";
        file_put_contents($path, $soi . $sof0 . $eoi);
    }

    /**
     * A real JPEG with a genuine EXIF Orientation tag, written via Imagick
     * (GD has no EXIF writer). The image has an asymmetric marker block
     * (opaque red, top-left quadrant) on an otherwise blue canvas so a
     * rotation/flip test can assert the marker actually moved, not just
     * that conversion didn't crash.
     *
     * @return bool true if the fixture was written and its EXIF round-tripped correctly.
     */
    /**
     * A JPEG carrying a real EXIF Orientation tag, with a red marker block in
     * its top-left quarter so a rotation or flip can be detected by sampling.
     *
     * The EXIF segment is assembled byte by byte rather than delegated to
     * Imagick. Imagick's setImageProperty('exif:Orientation', ...) sets an
     * in-memory property that is never serialised into an APP1 segment, so the
     * tag did not survive writeImage() and every orientation test failed on
     * the fixture rather than on the code under test. Building it here also
     * drops the Imagick requirement, so these tests now run anywhere GD and
     * ext-exif are present -- including a plain developer workstation.
     *
     * Byte literals are built with chr() rather than "\x.." escapes: the
     * latter are a standing hazard in a file that gets edited by tooling,
     * where one lost backslash silently turns the byte 0xFF into the two-byte
     * UTF-8 encoding of U+00FF and the JPEG stops being a JPEG.
     */
    public static function orientedJpegWithMarker(string $path, int $orientation, int $width = 40, int $height = 20): bool {
        $img = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($img, 0, 0, 255);
        $red = imagecolorallocate($img, 255, 0, 0);
        imagefilledrectangle($img, 0, 0, $width - 1, $height - 1, $blue);
        imagefilledrectangle($img, 0, 0, (int) ($width / 4), (int) ($height / 4), $red);

        ob_start();
        imagejpeg($img, null, 95);
        $jpeg = (string) ob_get_clean();
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($img);
        }

        $soi = chr(0xFF) . chr(0xD8);
        if (strncmp($jpeg, $soi, 2) !== 0) {
            return false;
        }

        // Inserted immediately after SOI, ahead of GD's JFIF APP0.
        file_put_contents($path, $soi . self::exifOrientationSegment($orientation) . substr($jpeg, 2));

        if (!function_exists('exif_read_data')) {
            return false;
        }
        $exif = @exif_read_data($path);
        return $exif && isset($exif['Orientation']) && (int) $exif['Orientation'] === $orientation;
    }

    /**
     * A minimal APP1 segment holding nothing but an Orientation tag.
     *
     * Layout: APP1 marker, segment length, the "Exif\0\0" identifier, then a
     * big-endian TIFF header pointing at a single-entry IFD0. The entry is
     * tag 0x0112 (Orientation), type 3 (SHORT), count 1 -- and because a SHORT
     * fits inside the entry's own 4-byte value field, the value sits inline in
     * the high two bytes rather than at an offset elsewhere in the file.
     */
    private static function exifOrientationSegment(int $orientation): string {
        $nul = chr(0);

        $tiff = 'MM'                        // big-endian byte order
            . pack('n', 0x002A)             // TIFF magic
            . pack('N', 8);                 // IFD0 starts 8 bytes in

        $ifd = pack('n', 1)                 // one entry
            . pack('n', 0x0112)             // Orientation
            . pack('n', 3)                  // type SHORT
            . pack('N', 1)                  // one value
            . pack('n', $orientation) . $nul . $nul
            . pack('N', 0);                 // no next IFD

        $payload = 'Exif' . $nul . $nul . $tiff . $ifd;

        // The length field counts its own two bytes, hence the +2.
        return chr(0xFF) . chr(0xE1) . pack('n', strlen($payload) + 2) . $payload;
    }

    /**
     * A real CMYK JPEG (getimagesize() channels === 4), generated via
     * Imagick's colorspace transform from a known sRGB color, so a
     * round-trip color-fidelity assertion is possible.
     */
    public static function cmykJpegFromKnownColor(string $path, string $hexColor = '#3366CC', int $size = 20): bool {
        if (!self::isImagickAvailable()) {
            return false;
        }
        $imagick = new \Imagick();
        $imagick->newImage($size, $size, new \ImagickPixel($hexColor));
        $imagick->setImageFormat('jpeg');
        $imagick->transformImageColorspace(\Imagick::COLORSPACE_CMYK);
        $imagick->writeImage($path);
        $imagick->clear();
        $imagick->destroy();

        $info = @getimagesize($path);
        return $info !== false && ($info['channels'] ?? 3) === 4;
    }
}
