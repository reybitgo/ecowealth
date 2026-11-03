<?php
declare(strict_types=1);

/* ==============================================================
 *  UploadService — domain wrapper around Core\Upload (plan §3.8)
 *
 *  Core\Upload knows how to validate and store one file; this
 *  service holds the app-level policy around it:
 *    • which areas exist (products, packages, proofs)
 *    • replacing an image deletes the previous file only after the
 *      new one is stored, so a failed upload never loses the old
 *    • seeded images under assets/ are never touched
 * ============================================================== */

final class UploadService
{
    /**
     * Store one image for a catalogue area.
     *
     * @param array<string, mixed> $file One entry from $_FILES
     * @return array{ok: bool, path: ?string, error: ?string}
     */
    public static function image(array $file, string $area = 'products', array $options = []): array
    {
        return Upload::image($file, $area, $options);
    }

    /**
     * Store a replacement image and remove the old file on success.
     *
     * @param string|null         $oldPath Previous image_url, if any
     * @param array<string, mixed> $file    One entry from $_FILES
     * @return array{ok: bool, path: ?string, error: ?string}
     */
    public static function replace(?string $oldPath, array $file, string $area = 'products'): array
    {
        $result = self::image($file, $area);

        if ($result['ok'] && $oldPath !== null) {
            self::remove($oldPath);
        }

        return $result;
    }

    /** Delete a stored file (only inside uploads/ — assets are left alone). */
    public static function remove(?string $path): bool
    {
        return Upload::remove($path);
    }

    /** Public URL for an image_url column value ("assets/…" or "uploads/…"). */
    public static function url(?string $path): ?string
    {
        $path = trim((string) $path);

        return $path === '' ? null : url($path);
    }
}
