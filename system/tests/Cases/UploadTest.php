<?php
declare(strict_types=1);

/* ==============================================================
 *  UploadTest — validated file storage (plan §3.7, §3.8)
 *
 *  Uploads are written to UPLOAD_ROOT (a scratch folder under the
 *  system temp directory), so the harness never writes into the
 *  repository.
 * ============================================================== */

/** Write a scratch file and return its path. */
$tempFile = static function (string $contents, string $extension = 'png'): string {
    $path = sys_get_temp_dir() . '/ecowealth-upload-' . bin2hex(random_bytes(6)) . '.' . $extension;

    if (file_put_contents($path, $contents) === false) {
        fail('could not create the upload fixture at ' . $path);
    }

    return $path;
};

/** Build the $_FILES-shaped array the app receives. */
$file = static function (string $tmpPath, string $name, string $clientType = 'image/png', ?int $size = null) : array {
    return [
        'name'     => $name,
        'type'     => $clientType,
        'tmp_name' => $tmpPath,
        'error'    => UPLOAD_ERR_OK,
        'size'     => $size ?? (int) filesize($tmpPath),
    ];
};

return [
    'Upload stores a valid PNG with a random name' => function () use ($tempFile, $file): void {
        $tmp    = $tempFile(TEST_PNG_BYTES, 'png');
        $result = Upload::image($file($tmp, 'pixel.png'), 'products');

        assert_true($result['ok'], 'upload rejected: ' . (string) $result['error']);
        assert_true(
            preg_match('#^uploads/products/[0-9a-f]{32}\.png$#', (string) $result['path']) === 1,
            'unexpected stored path: ' . (string) $result['path']
        );

        $absolute = Upload::root() . substr((string) $result['path'], strlen('uploads/'));
        assert_true(is_file($absolute), 'stored file missing');

        assert_true(Upload::remove((string) $result['path']), 'remove() failed');
        assert_false(is_file($absolute), 'file should be gone');
    },

    'Upload rejects a PHP file renamed to .jpg' => function () use ($tempFile, $file): void {
        $tmp    = $tempFile('<?php echo "pwned";', 'jpg');
        $result = Upload::image($file($tmp, 'innocent.jpg', 'image/jpeg'), 'products');

        assert_false($result['ok'], 'a script masquerading as an image must be refused');
        assert_contains('not allowed', (string) $result['error']);
        @unlink($tmp);
    },

    'Upload rejects a file over the size cap' => function () use ($tempFile, $file): void {
        $tmp    = $tempFile(TEST_PNG_BYTES, 'png');
        $result = Upload::image($file($tmp, 'big.png', 'image/png', UPLOAD_MAX_BYTES + 1), 'products');

        assert_false($result['ok']);
        assert_contains('too large', (string) $result['error']);
        @unlink($tmp);
    },

    'Upload reports a missing file' => function (): void {
        $result = Upload::image(
            ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
            'products'
        );

        assert_false($result['ok']);
        assert_contains('choose a file', (string) $result['error']);
    },

    'Upload accepts a PDF payment proof' => function () use ($tempFile, $file): void {
        $tmp = $tempFile("%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", 'pdf');

        $result = Upload::store($file($tmp, 'receipt.pdf', 'application/pdf'), 'proofs');

        assert_true($result['ok'], 'PDF rejected: ' . (string) $result['error']);
        assert_true(str_starts_with((string) $result['path'], 'uploads/proofs/'));
        assert_true(Upload::remove((string) $result['path']));
    },

    'Upload refuses an unknown area' => function () use ($tempFile, $file): void {
        $tmp = $tempFile(TEST_PNG_BYTES, 'png');

        assert_throws(
            static fn () => Upload::image($file($tmp, 'pixel.png'), 'avatars'),
            InvalidArgumentException::class
        );
        @unlink($tmp);
    },

    'Upload::remove() refuses paths outside uploads/' => function (): void {
        assert_false(Upload::remove(null));
        assert_false(Upload::remove(''));
        assert_false(Upload::remove('../app/config.php'));
        assert_false(Upload::remove('uploads/../../app/config.php'));
        assert_false(Upload::remove('/etc/passwd'));
    },
];
