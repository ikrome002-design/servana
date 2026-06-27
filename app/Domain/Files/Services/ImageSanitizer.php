<?php

declare(strict_types=1);

namespace App\Domain\Files\Services;

/**
 * Safe server-side image re-encoding (Plan §9 rule 10, §65; Phase 10F).
 *
 * Decodes with GD (rejects malformed input), enforces dimension + pixel-count
 * limits, and re-encodes to an approved raster format — which strips EXIF and all
 * other metadata. Active SVG is never accepted (SVG is not a GD raster format and
 * is rejected upstream by the MIME allowlist). Never trusts the input MIME.
 */
final class ImageSanitizer
{
    public function __construct(
        private readonly int $maxWidth,
        private readonly int $maxHeight,
        private readonly int $maxPixels,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (int) config('files.image_max_width', 4096),
            (int) config('files.image_max_height', 4096),
            (int) config('files.image_max_pixels', 24_000_000),
        );
    }

    /**
     * Re-encode the raw image bytes to a clean, metadata-free image.
     *
     * @param  string  $binary  raw (untrusted) image bytes
     * @param  string  $detectedMime  server-detected MIME (image/png|jpeg|webp)
     * @return string clean re-encoded image bytes
     *
     * @throws \DomainException on malformed / oversized / unsupported input
     */
    public function sanitize(string $binary, string $detectedMime): string
    {
        // Validate dimensions BEFORE decoding the full bitmap (decompression bomb guard).
        $info = @getimagesizefromstring($binary);
        if ($info === false) {
            throw new \DomainException('Unreadable or malformed image.');
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > $this->maxWidth || $height > $this->maxHeight) {
            throw new \DomainException('Image dimensions out of bounds.');
        }
        if ($width * $height > $this->maxPixels) {
            throw new \DomainException('Image pixel count exceeds the limit.');
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            throw new \DomainException('Image could not be decoded.');
        }

        try {
            // Preserve alpha for PNG/WebP re-encoding.
            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $ok = match ($detectedMime) {
                'image/png' => imagepng($image),
                'image/jpeg' => imagejpeg($image, null, 85),
                'image/webp' => imagewebp($image),
                default => false,
            };
            $output = (string) ob_get_clean();
        } finally {
            imagedestroy($image);
        }

        if ($ok === false || $output === '') {
            throw new \DomainException('Image re-encoding failed.');
        }

        return $output;
    }
}
