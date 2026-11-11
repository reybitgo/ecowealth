<?php
declare(strict_types=1);

/* ==============================================================
 *  DatabaseToolException — a typed failure from the install/reset tools
 *  (plan IR-1, §5.1 and §5.10)
 *
 *  Every refusal, preflight failure and SQL error in the database
 *  tooling is one of these instead of a bare RuntimeException, so a
 *  caller (CLI, web page, test) can branch on `kind` without matching
 *  message text:
 *
 *      try {
 *          $installer->install($options);
 *      } catch (DatabaseToolException $e) {
 *          if ($e->kind === DatabaseToolException::KIND_LOCKED) { … }
 *      }
 *
 *  `context` carries structured facts for logs and reports (statement
 *  number, line, driver code, database name …). It never carries a DSN
 *  or a password, and `getMessage()` is safe to print in a browser or a
 *  terminal: raw MySQL errors are written to error_log() where they are
 *  caught, never copied into the message.
 * ============================================================== */

final class DatabaseToolException extends RuntimeException
{
    public const KIND_USAGE          = 'usage';
    public const KIND_PREFLIGHT      = 'preflight';
    public const KIND_REFUSED        = 'refused';
    public const KIND_LOCKED         = 'locked';
    public const KIND_NOT_EMPTY      = 'not_empty';
    public const KIND_SQL            = 'sql';
    public const KIND_VERIFY         = 'verify';
    public const KIND_INVALID_NAME   = 'invalid_name';

    /**
     * @param array<string, mixed> $context structured facts, never a secret
     * @param string|null $hint operator-facing next step (shown after the message)
     */
    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_USAGE,
        public readonly array $context = [],
        public readonly ?string $hint = null
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $context */
    public static function usage(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_USAGE, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function preflight(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_PREFLIGHT, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function refused(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_REFUSED, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function locked(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_LOCKED, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function notEmpty(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_NOT_EMPTY, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function sql(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_SQL, $context, $hint);
    }

    /** @param array<string, mixed> $context */
    public static function verify(string $message, array $context = [], ?string $hint = null): self
    {
        return new self($message, self::KIND_VERIFY, $context, $hint);
    }

    /**
     * A database name that may not reach a DSN or a DDL statement.
     *
     * The name is echoed back only after control characters are replaced
     * and the value is truncated, so a hostile payload cannot be used to
     * forge log lines or an HTML fragment by accident.
     */
    public static function invalidName(string $database): self
    {
        $shown = self::excerpt($database);

        return new self(
            'The database name ' . ($shown === '' ? '(empty)' : '"' . $shown . '"')
                . ' is not allowed. Use 1–64 letters, digits or underscores.',
            self::KIND_INVALID_NAME,
            ['database' => $shown],
            'Database names are validated before they reach a DSN or a DDL statement; '
                . 'spaces, dots, quotes and semicolons cannot be used.'
        );
    }

    private static function excerpt(string $value, int $max = 64): string
    {
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $value);

        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }
}
