<?php
declare(strict_types=1);

/* ==============================================================
 *  CronLock — one run at a time (Phase 7 follow-up)
 *
 *  A non-blocking advisory lock around a cron job. Overlapping runs are
 *  normal for a timer (Hostinger can fire while a slow run is still
 *  going), so the second caller is told "busy" and exits successfully
 *  instead of doing the work twice.
 *
 *      $lock = new CronLock('shop_expire');
 *      if (!$lock->acquire()) { … another run is in progress … }
 *      try { … } finally { $lock->release(); }
 *
 *  `flock` is advisory and released automatically when the process
 *  ends, so a killed run cannot leave a permanent lock.
 * ============================================================== */

final class CronLock
{
    private string $path;
    private string $directory;

    /** @var resource|null */
    private $handle = null;

    public function __construct(string $name, ?string $directory = null)
    {
        $name = preg_replace('/[^a-z0-9_-]+/i', '-', $name) ?? '';
        $name = trim($name, '-');

        if ($name === '') {
            throw new InvalidArgumentException('A cron lock needs a name.');
        }

        $this->directory = rtrim($directory ?? self::defaultDirectory(), '/\\');
        $this->path      = $this->directory . DIRECTORY_SEPARATOR . $name . '.lock';
    }

    /** Default folder: <storage>/locks (outside public/). */
    public static function defaultDirectory(): string
    {
        return rtrim((string) STORAGE_PATH, '/\\') . DIRECTORY_SEPARATOR . 'locks';
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Try to take the lock. Creates the folder on first use.
     * Returns false when another process (or another handle) holds it.
     */
    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }

        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The cron lock folder is not writable: ' . $this->directory);
        }

        $handle = @fopen($this->path, 'c');

        if ($handle === false) {
            throw new RuntimeException('The cron lock file could not be opened: ' . $this->path);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        /* Diagnostics only: who holds it and since when. */
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, sprintf("pid=%d started=%s\n", getmypid(), date('Y-m-d H:i:s P')));
        fflush($handle);

        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;

        /* The file stays on disk on purpose: deleting it would let two
         * processes lock different inodes of the same path. Its content
         * is only the last holder's diagnostics. */
    }

    /** Is the lock free right now? (takes and releases it for one instant) */
    public function isFree(): bool
    {
        return $this->status()['state'] === 'free';
    }

    /**
     * Diagnostic state for the doctor: free | busy | unavailable.
     *
     * @return array{state:string, detail:string}
     */
    public function status(): array
    {
        if ($this->handle !== null) {
            return ['state' => 'busy', 'detail' => 'held by this process'];
        }

        try {
            if (!$this->acquire()) {
                return ['state' => 'busy', 'detail' => 'another run holds it'];
            }

            $this->release();

            return ['state' => 'free', 'detail' => ''];
        } catch (Throwable $e) {
            return ['state' => 'unavailable', 'detail' => $e->getMessage()];
        }
    }
}
