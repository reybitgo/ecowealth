<?php
declare(strict_types=1);

/* ==============================================================
 *  SqlScript — parse and run a .sql file, one statement at a time
 *  (plan IR-1, §5.5 and D-IR-02)
 *
 *  The app's SQL sources are ordinary MySQL scripts: comments in the
 *  header of every file, `;` inside seed strings ("plan''s"), backslash
 *  escapes ("Associate status\nShop access"), semantics-bearing quotes
 *  and backticked identifiers. Naively splitting on `;` mangles them;
 *  this parser is a small state machine that understands the four
 *  MySQL quoting rules and the three comment forms.
 *
 *      $script = SqlScript::fromFile('database/install.sql');
 *      $script->statements();   // [{sql, line}, …]
 *      $script->run($pdo);      // executes them, stops at the first error
 *      $script->checksum();     // sha256 of normalised content
 *      $script->containsDatabaseSwitch();   // CREATE DATABASE / USE?
 *
 *  Rules (each one is covered by a case in tests/Cases/SqlScriptTest.php):
 *    • '…', "…" and `…` are tracked; doubled quotes and backslash
 *      escapes are honoured, so a `;` inside any of them never splits.
 *    • `--` starts a comment only when followed by whitespace or the end
 *      of the file; `#` only at the start of a line; C-style slash-star
 *      blocks and MySQL's executable version comments are comments.
 *      All are replaced by whitespace so two tokens never fuse.
 *    • Line numbers are preserved for error context.
 *    • DELIMITER is rejected: the bundle has no procedures or triggers,
 *      and supporting the command would invite fragile parsing.
 *    • A script that ends inside a string or a block comment is rejected:
 *      a truncated file must not run its first statements silently.
 *    • Empty statements are dropped; the BOM is stripped.
 *    • A hard statement cap keeps a malformed file from looping.
 *
 *  Execution stops at the first failing statement and throws a
 *  DatabaseToolException carrying the statement index, its line, the
 *  SQLSTATE/driver code and a 200-character snippet. There is no
 *  "continue on error" mode: half-applying a schema must be visible.
 * ============================================================== */

final class SqlScript
{
    /** A malformed file must not be able to make the parser loop forever. */
    public const MAX_STATEMENTS = 20000;

    /** Snippet length kept in an error context (plan §5.5). */
    private const SNIPPET_LENGTH = 200;

    /** @var list<array{sql:string, line:int}>|null */
    private ?array $statements = null;

    private function __construct(
        private readonly string $sql,
        private readonly string $source,
        private readonly string $label
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw DatabaseToolException::usage(
                'The SQL file is not readable: ' . $path,
                ['path' => $path],
                'Pass the path of an existing .sql file.'
            );
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw DatabaseToolException::usage(
                'The SQL file could not be read: ' . $path,
                ['path' => $path]
            );
        }

        return new self(self::normalise($sql), $path, basename($path));
    }

    public static function fromString(string $sql): self
    {
        return new self(self::normalise($sql), '(inline)', 'SQL script');
    }

    /** Where the script came from: a path, or "(inline)" for fromString(). */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Split the script into statements with their starting line.
     *
     * @return list<array{sql:string, line:int}>
     */
    public function statements(): array
    {
        return $this->statements ??= $this->parse();
    }

    /**
     * Execute every statement in order.
     *
     * PDO::query() is used rather than PDO::exec(): the install bundle
     * ends with verification SELECTs, and a SELECT executed through
     * exec() leaves its result pending, which makes the next statement
     * fail with MySQL 2014 ("commands out of sync"). The cursor is
     * closed after every statement, so a caller can keep using the same
     * connection.
     *
     * @param callable(int,string,int):void|null $onStatement ($index, $sql, $line)
     * @return array{statements:int, elapsed_ms:int}
     * @throws DatabaseToolException on the first failing statement
     */
    public function run(PDO $pdo, ?callable $onStatement = null): array
    {
        $started = microtime(true);
        $count   = 0;

        foreach ($this->statements() as $index => $statement) {
            $number = $index + 1;
            $count++;

            try {
                $result = $pdo->query($statement['sql']);

                if (!$result instanceof PDOStatement) {
                    $info    = $pdo->errorInfo();
                    $failure = new PDOException((string) ($info[2] ?? 'The statement failed.'));
                    $failure->errorInfo = is_array($info) ? $info : null;

                    throw $failure;
                }

                $result->closeCursor();
            } catch (PDOException $e) {
                throw $this->statementFailure($e, $number, $statement);
            }

            if ($onStatement !== null) {
                $onStatement($number, $statement['sql'], $statement['line']);
            }
        }

        return [
            'statements' => $count,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * SHA-256 of the normalised content.
     *
     * Line endings, the BOM and trailing whitespace are normalised first,
     * so a Windows checkout and a Linux checkout of the same file hash
     * identically. The install lock records this value (IR-3).
     */
    public function checksum(): string
    {
        $lines = preg_split('/\n/', $this->sql) ?: [];
        $lines = array_map(static fn (string $line): string => rtrim($line, " \t"), $lines);
        $content = trim(implode("\n", $lines), "\n");

        return hash('sha256', $content . "\n");
    }

    /**
     * Does the script contain an executable CREATE DATABASE/SCHEMA or USE?
     *
     * Comments and string literals are ignored, so the prose in a file
     * header ("this file deliberately names no database") does not count
     * and neither does a seed value that happens to contain the word.
     */
    public function containsDatabaseSwitch(): bool
    {
        foreach ($this->statements() as $statement) {
            $code = self::codeOnly($statement['sql']);

            if (preg_match('/\bCREATE\s+(?:DATABASE|SCHEMA)\b/i', $code) === 1) {
                return true;
            }

            if (preg_match('/^\s*USE\b/i', $code) === 1) {
                return true;
            }
        }

        return false;
    }

    /** ------------------------------------------------------------------
     *  Parser
     *  ---------------------------------------------------------------- */

    /** @return list<array{sql:string, line:int}> */
    private function parse(): array
    {
        $sql        = $this->sql;
        $length     = strlen($sql);
        $statements = [];
        $buffer     = '';
        $line       = 1;
        $startLine  = 1;
        $hasCode    = false;
        $lineCode   = false;
        $state      = 'code';
        $stateLine  = 1;
        $quote      = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'line-comment') {
                if ($char === "\n") {
                    $state    = 'code';
                    $line++;
                    $lineCode = false;

                    /* A comment separates tokens: "SELECT 1 -- note\nFROM t". */
                    if ($hasCode) {
                        $buffer .= "\n";
                    }
                }

                continue;
            }

            if ($state === 'block-comment') {
                if ($char === '*' && $next === '/') {
                    $state = 'code';
                    $i++;
                    continue;
                }

                if ($char === "\n") {
                    $line++;
                    $lineCode = false;

                    if ($hasCode) {
                        $buffer .= "\n";
                    }
                }

                continue;
            }

            if ($state === 'string') {
                $buffer .= $char;

                if ($char === "\n") {
                    $line++;
                }

                if ($quote !== '`' && $char === '\\' && $next !== '') {
                    /* Backslash escape: the escaped byte is literal. */
                    $buffer .= $next;
                    if ($next === "\n") {
                        $line++;
                    }
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        /* Doubled quote stays inside the string. */
                        $buffer .= $next;
                        $i++;

                        continue;
                    }

                    $state = 'code';
                    $quote = '';
                }

                continue;
            }

            /* ----------------------------------------------------------
             *  Code state
             * -------------------------------------------------------- */

            if ($char === "'" || $char === '"' || $char === '`') {
                $buffer   .= $char;
                $state     = 'string';
                $stateLine = $line;
                $quote     = $char;
                $lineCode  = true;

                if (!$hasCode) {
                    $hasCode   = true;
                    $startLine = $line;
                }

                continue;
            }

            if ($char === '-' && $next === '-' && ($i + 2 >= $length || self::isSpace($sql[$i + 2]))) {
                $state = 'line-comment';
                $i++;   // consume the second dash

                continue;
            }

            if ($char === '#' && !$lineCode) {
                $state = 'line-comment';

                continue;
            }

            if ($char === '/' && $next === '*') {
                $state     = 'block-comment';
                $stateLine = $line;
                $i++;

                /* Separate the tokens the comment sat between. */
                if ($hasCode && !str_ends_with($buffer, ' ') && !str_ends_with($buffer, "\n")) {
                    $buffer .= ' ';
                }

                continue;
            }

            if ($char === ';') {
                $statement = trim($buffer);
                $buffer    = '';
                $hasCode   = false;
                $lineCode  = false;

                if ($statement !== '') {
                    $statements[] = $this->statement($statement, $startLine);
                    $this->guardCap(count($statements), $startLine);
                }

                continue;
            }

            if ($char === "\n") {
                $line++;
                $lineCode = false;

                if ($hasCode) {
                    $buffer .= "\n";
                }

                continue;
            }

            if (!self::isSpace($char)) {
                $lineCode = true;

                if (!$hasCode) {
                    $hasCode   = true;
                    $startLine = $line;
                }
            }

            $buffer .= $char;
        }

        if ($state === 'string' || $state === 'block-comment') {
            $kinds = [
                'string'        => ['unterminated_string', 'quoted string'],
                'block-comment' => ['unterminated_comment', 'block comment'],
            ];

            throw DatabaseToolException::sql(
                sprintf(
                    '%s: unterminated %s starting at line %d.',
                    $this->label,
                    $kinds[$state][1],
                    $stateLine
                ),
                [
                    'source' => $this->source,
                    'line'   => $stateLine,
                    'reason' => $kinds[$state][0],
                ],
                'Fix the SQL file: a truncated script must not run its earlier statements silently.'
            );
        }

        $statement = trim($buffer);

        if ($statement !== '') {
            $statements[] = $this->statement($statement, $startLine);
            $this->guardCap(count($statements), $startLine);
        }

        return $statements;
    }

    /** @return array{sql:string, line:int} */
    private function statement(string $sql, int $line): array
    {
        if (preg_match('/^DELIMITER\b/i', $sql) === 1) {
            throw DatabaseToolException::sql(
                sprintf(
                    '%s: DELIMITER is not supported (statement at line %d).',
                    $this->label,
                    $line
                ),
                [
                    'source' => $this->source,
                    'line'   => $line,
                    'reason' => 'delimiter',
                ],
                'The install/reset scripts contain plain statements only; remove the DELIMITER block '
                    . '(procedures and triggers are out of scope).'
            );
        }

        return ['sql' => $sql, 'line' => $line];
    }

    private function guardCap(int $count, int $line): void
    {
        if ($count <= self::MAX_STATEMENTS) {
            return;
        }

        throw DatabaseToolException::usage(
            sprintf(
                '%s contains more than %d statements; refusing to run it.',
                $this->label,
                self::MAX_STATEMENTS
            ),
            ['source' => $this->source, 'line' => $line, 'limit' => self::MAX_STATEMENTS],
            'Split the file into smaller scripts.'
        );
    }

    /** ------------------------------------------------------------------
     *  Helpers
     *  ---------------------------------------------------------------- */

    /**
     * A copy of one statement with every quoted segment blanked out.
     *
     * Used by containsDatabaseSwitch(): a word inside a seed value can
     * never be mistaken for a statement, while a keyword outside quotes
     * is still found.
     */
    private static function codeOnly(string $sql): string
    {
        $output = '';
        $length = strlen($sql);
        $state  = 'code';
        $quote  = '';

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($state === 'string') {
                if ($quote !== '`' && $char === '\\' && $next !== '') {
                    $i++;

                    continue;
                }

                if ($char === $quote) {
                    if ($next === $quote) {
                        $i++;

                        continue;
                    }

                    $state  = 'code';
                    $output .= ' ';
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $state = 'string';
                $quote = $char;

                continue;
            }

            $output .= $char;
        }

        return $output;
    }

    private function statementFailure(PDOException $e, int $number, array $statement): DatabaseToolException
    {
        $sqlstate   = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        error_log(sprintf(
            '[ecowealth] %s: statement %d (line %d) failed: %s',
            $this->source,
            $number,
            $statement['line'],
            $e->getMessage()
        ));

        $error = $driverCode > 0 ? sprintf(' with MySQL error %d', $driverCode) : '';

        return DatabaseToolException::sql(
            sprintf('%s: statement %d (line %d) failed%s.', $this->label, $number, $statement['line'], $error),
            [
                'source'      => $this->source,
                'statement'   => $number,
                'line'        => $statement['line'],
                'sqlstate'    => $sqlstate,
                'driver_code' => $driverCode,
                'sql'         => self::snippet($statement['sql']),
            ],
            'The script stops at the first failing statement; nothing after it ran. '
                . 'Fix the cause (a missing table, a non-empty target, an invalid definition) and run it '
                . 'again on a clean schema. The full MySQL error was written to the PHP error log.'
        );
    }

    private static function snippet(string $sql): string
    {
        $sql = (string) preg_replace('/\s+/', ' ', $sql);

        return strlen($sql) > self::SNIPPET_LENGTH
            ? substr($sql, 0, self::SNIPPET_LENGTH) . '…'
            : $sql;
    }

    private static function normalise(string $sql): string
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) {
            $sql = substr($sql, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $sql);
    }

    private static function isSpace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r"
            || $char === "\v" || $char === "\f";
    }
}
