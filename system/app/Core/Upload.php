<?php
declare(strict_types=1);

/* ==============================================================
 *  Upload — validated file storage for product / package images
 *  and payment proofs (plan §3.7, §3.8).
 *
 *  Contract: MIME sniff with finfo (never the client's type),
 *  extension whitelist, size cap, random filename, stored under
 *  public/uploads/<area>/. The directory .htaccess disables PHP.
 *
 *    $result = Upload::image($_FILES['image'], 'products');
 *    if (!$result['ok']) { $errors['image'] = $result['error']; }
 *    else { $path = $result['path']; }   // "uploads/products/ab12….jpg"
 * ============================================================== */

final class Upload
{
    /** area => allowed MIME types (extension inferred from the MIME). */
    private const AREAS = [
        'products' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'packages' => ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'],
        'proofs'   => [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'application/pdf' => 'pdf',
        ],
    ];

    /**
     * Store an uploaded image (products/packages areas).
     *
     * @param array<string, mixed> $file One entry from $_FILES
     * @param array{max_bytes?: int} $options
     * @return array{ok: bool, path: ?string, error: ?string}
     */
    public static function image(array $file, string $area = 'products', array $options = []): array
    {
        return self::store($file, $area, $options);
    }

    /**
     * Store any allowed upload for an area.
     *
     * @param array<string, mixed> $file One entry from $_FILES
     * @param array{max_bytes?: int} $options
     * @return array{ok: bool, path: ?string, error: ?string}
     */
    public static function store(array $file, string $area, array $options = []): array
    {
        $area = (string) $area;

        if (!isset(self::AREAS[$area])) {
            throw new InvalidArgumentException('Unknown upload area: ' . $area);
        }

        $maxBytes = (int) ($options['max_bytes'] ?? UPLOAD_MAX_BYTES);

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return self::fail(self::uploadErrorMessage($error));
        }

        $tmp  = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);

        if ($tmp === '' || !is_file($tmp)) {
            return self::fail('The file did not upload correctly. Please try again.');
        }

        if ($size <= 0) {
            return self::fail('The uploaded file is empty.');
        }

        if ($size > $maxBytes) {
            return self::fail(sprintf('The file is too large. The limit is %s MB.', number_format($maxBytes / 1048576, 0)));
        }

        /* Sniff the real content type — the browser's guess is never trusted. */
        $mime = self::mime($tmp);
        if ($mime === null || !array_key_exists($mime, self::AREAS[$area])) {
            return self::fail('That file type is not allowed. Use ' . self::allowedLabel($area) . '.');
        }

        $extension = self::AREAS[$area][$mime];
        $name      = bin2hex(random_bytes(16)) . '.' . $extension;
        $directory = self::root() . $area;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            error_log('[ecowealth] upload directory not writable: ' . $directory);

            return self::fail('The upload folder is not writable. Please contact the administrator.');
        }

        $target = $directory . '/' . $name;

        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $target) : false;

        /* Test harness: a plain temp file is not an "uploaded file", so
         * allow a direct move when the app runs under APP_ENV=test. */
        if (!$moved && APP_ENV === 'test' && is_file($tmp)) {
            $moved = rename($tmp, $target);
        }

        if (!$moved) {
            return self::fail('The file could not be saved. Please try again.');
        }

        @chmod($target, 0644);

        return ['ok' => true, 'path' => 'uploads/' . $area . '/' . $name, 'error' => null];
    }

    /** Delete a stored file by its app-relative path ("uploads/products/x.jpg"). */
    public static function remove(?string $relativePath): bool
    {
        if ($relativePath === null || $relativePath === '') {
            return false;
        }

        $relative = str_replace('\\', '/', ltrim($relativePath, '/'));
        if (!str_starts_with($relative, 'uploads/') || str_contains($relative, '..')) {
            return false;
        }

        $file = self::root() . substr($relative, strlen('uploads/'));

        return is_file($file) && @unlink($file);
    }

    /**
     * Absolute base directory for stored files (trailing slash).
     * UPLOAD_ROOT lets the test harness point uploads at a scratch folder.
     */
    public static function root(): string
    {
        $root = defined('UPLOAD_ROOT') ? (string) UPLOAD_ROOT : dirname(__DIR__, 2) . '/public/uploads/';

        return rtrim(str_replace('\\', '/', $root), '/') . '/';
    }

    /** Absolute path of public/uploads/ (kept for callers that need the default). */
    public static function publicRoot(): string
    {
        return dirname(__DIR__, 2) . '/public/uploads/';
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    /** @return array{ok: bool, path: ?string, error: ?string} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'path' => null, 'error' => $message];
    }

    private static function mime(string $file): ?string
    {
        if (!class_exists('finfo')) {
            return null;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file);

        return is_string($mime) ? strtolower($mime) : null;
    }

    private static function allowedLabel(string $area): string
    {
        $extensions = array_values(array_unique(array_values(self::AREAS[$area])));

        return strtoupper(implode(', ', $extensions));
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
            UPLOAD_ERR_PARTIAL    => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE    => 'Please choose a file to upload.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the file.',
            UPLOAD_ERR_EXTENSION  => 'The upload was blocked by a server extension.',
            default               => 'The file could not be uploaded.',
        };
    }
}
