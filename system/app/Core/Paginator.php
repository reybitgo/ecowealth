<?php
declare(strict_types=1);

/* ==============================================================
 *  Paginator — page maths for admin tables and member lists
 *  (plan §3.6: 20 rows by default, ?page=N).
 *
 *    $pager = Paginator::fromRequest($total, 20);
 *    // SQL: ... LIMIT {$pager->perPage} OFFSET {$pager->offset}
 *    // View: require partials/pagination.php with $pager
 * ============================================================== */

final class Paginator
{
    public const DEFAULT_PER_PAGE = 20;
    public const MAX_PER_PAGE     = 200;

    public readonly int $total;
    public readonly int $perPage;
    public readonly int $page;
    public readonly int $pages;
    public readonly int $offset;
    public readonly int $from;
    public readonly int $to;

    public function __construct(int $total, int $perPage = self::DEFAULT_PER_PAGE, int $page = 1)
    {
        $this->total   = max(0, $total);
        $this->perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $this->pages   = max(1, (int) ceil($this->total / $this->perPage));
        $this->page    = max(1, min($this->pages, $page));
        $this->offset  = ($this->page - 1) * $this->perPage;
        $this->from    = $this->total === 0 ? 0 : $this->offset + 1;
        $this->to      = min($this->total, $this->offset + $this->perPage);
    }

    /** Build from a total and an explicit (possibly invalid) page number. */
    public static function make(int $total, ?int $page = null, int $perPage = self::DEFAULT_PER_PAGE): self
    {
        return new self($total, $perPage, $page ?? 1);
    }

    /** Build from the current `?page=` query parameter. */
    public static function fromRequest(int $total, int $perPage = self::DEFAULT_PER_PAGE, string $param = 'page'): self
    {
        $page = Request::query($param);

        return new self($total, $perPage, is_scalar($page) ? (int) $page : 1);
    }

    public function hasPrev(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->pages;
    }

    public function summary(): string
    {
        if ($this->total === 0) {
            return 'No records';
        }

        return sprintf('Showing %s–%s of %s', number_format($this->from), number_format($this->to), number_format($this->total));
    }

    /**
     * Page numbers around the current page, for the pagination partial.
     *
     * @return list<int>
     */
    public function window(int $span = 2): array
    {
        if ($this->total === 0) {
            return [];
        }

        $start = max(1, $this->page - $span);
        $end   = min($this->pages, $this->page + $span);

        return $end < $start ? [] : range($start, $end);
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'total'   => $this->total,
            'perPage' => $this->perPage,
            'page'    => $this->page,
            'pages'   => $this->pages,
            'offset'  => $this->offset,
            'from'    => $this->from,
            'to'      => $this->to,
        ];
    }

    /** URL for a page number, keeping the current query string. */
    public function url(int $page, string $param = 'page'): string
    {
        $query = $_GET;
        unset($query[$param]);
        $query[$param] = $page;

        return url(ltrim(Request::path(), '/') . '?' . http_build_query($query));
    }
}
