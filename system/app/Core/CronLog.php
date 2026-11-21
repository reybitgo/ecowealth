<?php
declare(strict_types=1);

/* ==============================================================
 *  CronLog — one append-only line per run (Phase 7 follow-up)
 *
 *  Follows the reference cron style (dmp/cart-system/cron/*.php): one
 *  monthly file, `[timestamp] [LEVEL] message`, appended with LOCK_EX.
 *
 *      <storage>/logs/shop_expire-2026-11.log
 *
 *  The folder lives outside public/, so a log is never web-readable.
 *  A failed write never breaks the job — it returns false so the
 *  caller can warn on stderr instead.
 * ============================================================== */

final class CronLog
{
    private string $directory;
    private string $prefix;

    public function __construct(?string $directory = null, string $prefix = 'shop_expire')
    {
        $this->directory = rtrim($directory ?? self::defaultDirectory(), '/\\');
        $this->prefix    = preg_replace('/[^a-z0-9_-]+/i', '-', $prefix) ?: 'cron';
    }

    /** Default folder: <storage>/logs (outside public/). */
    public static function defaultDirectory(): string
    {
        return rtrim((string) STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . 'logs';
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /** The file a run at $timestamp belongs to (one file per month). */
    public function fileFor(?int $timestamp = null): string
    {
        return $this->directory . DIRECTORY_SEPARATOR
            . $this->prefix . '-' . date('Y-m', $timestamp ?? time()) . '.log';
    }

    /**
     * Append one line. Newlines are flattened so one run is one line.
     *
     * @param string $level INFO | OK | WARN | ERROR
     */
    public function write(string $level, string $message): bool
    {
        $level   = strtoupper(trim($level));
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);

        if ($message === '') {
            return false;
        }

        if (!$this->ensureDirectory()) {
            return false;
        }

        $line = sprintf(
            '[%s] [%-5s] %s%s',
            date('Y-m-d H:i:s P'),
            $level,
            $message,
            PHP_EOL
        );

        return @file_put_contents($this->fileFor(), $line, FILE_APPEND | LOCK_EX) !== false;
    }

    /** The newest line in the current month's file (doctor / tests). */
    public function latestLine(): ?string
    {
        $file = $this->fileFor();

        if (!is_file($file)) {
            return null;
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false || $lines === [] ? null : (string) end($lines);
    }

    public function ensureDirectory(): bool
    {
        if (is_dir($this->directory)) {
            return true;
        }

        return @mkdir($this->directory, 0775, true) || is_dir($this->directory);
    }

    /**
     * Is the folder writable? (doctor)
     *
     * Windows reports directories as non-writable in some sandboxed /
     * redirected profiles even though writing a file inside works, so
     * this checks by actually writing a throw-away probe file.
     */
    public function isWritable(): bool
    {
        if (!$this->ensureDirectory()) {
            return false;
        }

        $probe = $this->directory . DIRECTORY_SEPARATOR . '.write-probe-' . bin2hex(random_bytes(4));
        $ok    = @file_put_contents($probe, 'ok') !== false;

        if ($ok) {
            @unlink($probe);
        }

        return $ok;
    }
}
