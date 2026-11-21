<?php
declare(strict_types=1);

/* ==============================================================
 *  Validator — small, explicit rule engine for form + service
 *  validation (plan §3.6, §3.7).
 *
 *    $errors = Validator::check($_POST, [
 *        'name'  => 'required|string|min:2|max:160',
 *        'price' => 'required|numeric|gt:0|decimal:2',
 *        'email' => 'required|email|unique:users,email',
 *    ], ['name' => 'Product name']);
 *
 *  Returns field => first message. Empty array means valid.
 *  Rules compile left to right; the first failure per field wins.
 *
 *  Rules: required · nullable · string · integer · numeric ·
 *         decimal[:places] · min:n · max:n · gt/gte/lt/lte:n ·
 *         in:a,b · email · url · date · confirmed · regex:… ·
 *         unique:table,column[,ignoreId]
 * ============================================================== */

final class Validator
{
    /** @var array<string, string> */
    private array $errors = [];

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules  field => rule string
     * @param array<string, string> $labels field => human label
     */
    private function __construct(
        private readonly array $data,
        private readonly array $rules,
        private readonly array $labels = []
    ) {
        $this->run();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @return array<string, string>
     */
    public static function check(array $data, array $rules, array $labels = []): array
    {
        return (new self($data, $rules, $labels))->errors();
    }

    /** Fluent form for controllers that need to add custom messages. */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        return new self($data, $rules, $labels);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function first(string $field): string
    {
        return $this->errors[$field] ?? '';
    }

    public function add(string $field, string $message): void
    {
        $this->errors[$field] ??= $message;
    }

    /* ----------------------------------------------------------
     *  Internals
     * ---------------------------------------------------------- */

    private function run(): void
    {
        foreach ($this->rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            $rules = array_values(array_filter(array_map('trim', explode('|', (string) $ruleString))));

            $nullable = in_array('nullable', $rules, true);
            $isEmpty  = $value === null || (is_string($value) && trim($value) === '');

            if ($nullable && $isEmpty) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule === 'nullable') {
                    continue;
                }

                [$name, $param] = array_pad(explode(':', $rule, 2), 2, null);
                $message = $this->apply($field, (string) $name, $param, $value, $rules);

                if ($message !== null) {
                    $this->errors[$field] ??= $message;
                    break;
                }
            }
        }
    }

    /**
     * Apply one rule. Returns an error message, or null when it passes.
     *
     * @param list<string> $allRules
     */
    private function apply(string $field, string $rule, ?string $param, mixed $value, array $allRules): ?string
    {
        $name = $this->labels[$field] ?? $this->humanize($field);

        switch ($rule) {
            case 'required':
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    return sprintf('%s is required.', $name);
                }
                return null;

            case 'string':
                if (!is_scalar($value) || is_bool($value)) {
                    return sprintf('%s must be text.', $name);
                }
                return null;

            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    return sprintf('%s must be a whole number.', $name);
                }
                return null;

            case 'numeric':
                if (!is_numeric($value)) {
                    return sprintf('%s must be a number.', $name);
                }
                return null;

            case 'decimal':
                $places = $param === null ? 2 : max(0, (int) $param);
                if (!is_numeric($value)
                    || preg_match('/^-?\d+(\.\d{1,' . $places . '})?$/', (string) $value) !== 1) {
                    return sprintf(
                        '%s must be a number with at most %d decimal place%s.',
                        $name,
                        $places,
                        $places === 1 ? '' : 's'
                    );
                }
                return null;

            case 'min':
            case 'max':
                $limit = (float) ($param ?? 0);
                $bound = $rule === 'min' ? 'at least' : 'at most';

                if ($this->isNumericField($allRules, $value)) {
                    $number = (float) $value;
                    if (($rule === 'min' && $number < $limit) || ($rule === 'max' && $number > $limit)) {
                        return sprintf('%s must be %s %s.', $name, $bound, $this->number($limit));
                    }
                    return null;
                }

                $length = mb_strlen((string) $value);
                if (($rule === 'min' && $length < (int) $limit) || ($rule === 'max' && $length > (int) $limit)) {
                    return sprintf(
                        '%s must be %s %d character%s.',
                        $name,
                        $bound,
                        (int) $limit,
                        (int) $limit === 1 ? '' : 's'
                    );
                }
                return null;

            case 'gt':
            case 'gte':
            case 'lt':
            case 'lte':
                if (!is_numeric($value)) {
                    return sprintf('%s must be a number.', $name);
                }
                $number = (float) $value;
                $limit  = (float) ($param ?? 0);
                $ok = match ($rule) {
                    'gt'  => $number > $limit,
                    'gte' => $number >= $limit,
                    'lt'  => $number < $limit,
                    'lte' => $number <= $limit,
                };

                if (!$ok) {
                    $words = ['gt' => 'greater than', 'gte' => 'at least', 'lt' => 'less than', 'lte' => 'at most'];
                    return sprintf('%s must be %s %s.', $name, $words[$rule], $this->number($limit));
                }
                return null;

            case 'in':
                $allowed = array_map('trim', explode(',', (string) $param));
                if (!in_array((string) $value, $allowed, true)) {
                    return sprintf('%s must be one of: %s.', $name, implode(', ', $allowed));
                }
                return null;

            case 'email':
                if (!filter_var((string) $value, FILTER_VALIDATE_EMAIL)) {
                    return sprintf('%s must be a valid email address.', $name);
                }
                return null;

            case 'url':
                if (!filter_var((string) $value, FILTER_VALIDATE_URL)) {
                    return sprintf('%s must be a valid URL.', $name);
                }
                return null;

            case 'date':
                if (strtotime((string) $value) === false) {
                    return sprintf('%s must be a valid date.', $name);
                }
                return null;

            case 'confirmed':
                if ((string) $value !== (string) ($this->data[$field . '_confirmation'] ?? '')) {
                    return sprintf('%s confirmation does not match.', $name);
                }
                return null;

            case 'regex':
                if ($param === null || @preg_match($param, (string) $value) !== 1) {
                    return sprintf('%s is not in the expected format.', $name);
                }
                return null;

            case 'unique':
                return $this->unique($value, (string) $param, $name);
        }

        return null;
    }

    private function humanize(string $field): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $field));
    }

    /**
     * Do min/max read this field as an amount or as text?
     *
     * An explicit `string` rule wins: `required|string|max:200` is a
     * character limit even when the member typed a numeric password.
     * The numeric family keeps amount semantics, and a field with no
     * type rule at all still falls back to the value it happens to
     * carry (`required|max:1000` means the amount 1,000).
     *
     * Reading the value alone used to win over `string`, so a numeric
     * password, note or phone number was compared against the rule's
     * limit as a number: a 4-character password hit "Password must be
     * at most 200" — a message that reads like the amount beside it —
     * `min:8` let "1234" through, and a phone number "09171234567"
     * failed "Phone must be at most 40".
     *
     * @param list<string> $rules
     */
    private function isNumericField(array $rules, mixed $value): bool
    {
        foreach ($rules as $rule) {
            $name = explode(':', $rule, 2)[0];

            if ($name === 'string') {
                return false;
            }

            if (in_array($name, ['numeric', 'integer', 'decimal', 'gt', 'gte', 'lt', 'lte'], true)) {
                return true;
            }
        }

        return is_numeric($value) && !is_bool($value);
    }

    private function number(float $value): string
    {
        $formatted = rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function unique(mixed $value, string $param, string $name): ?string
    {
        $parts    = array_map('trim', explode(',', $param));
        $table    = $parts[0] ?? '';
        $column   = $parts[1] ?? '';
        $ignoreId = isset($parts[2]) && $parts[2] !== '' ? (int) $parts[2] : null;

        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            return null; // Misconfigured rule: never block a form on it.
        }

        $sql  = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column . '` = ?';
        $args = [$value];

        if ($ignoreId !== null) {
            $sql    .= ' AND `id` <> ?';
            $args[]  = $ignoreId;
        }

        $stmt = db()->prepare($sql);
        $stmt->execute($args);

        return (int) $stmt->fetchColumn() > 0 ? sprintf('%s is already taken.', $name) : null;
    }
}
