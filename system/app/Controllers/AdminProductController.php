<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminProductController — product catalogue management
 *  (plan §7 Phase 2; reference: AdminController::shopProducts,
 *  saveShopProduct, productStatus, deleteShopProduct)
 *
 *  Every action is admin-guarded by the router; POSTs are CSRF
 *  checked centrally. XHR requests get JSON (the status / delete
 *  buttons use it when JS is available); plain forms fall back to
 *  a flash + redirect so the page works without JavaScript.
 * ============================================================== */

final class AdminProductController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        /* The same URL is both the page and its feed: X-Live: 1 turns
         * the request into JSON fragments. That keeps Paginator::url()
         * and the per-page form pointing at /admin/products rather than
         * a separate feed path. */
        if (Live::isFeedRequest()) {
            $this->feed();
            return;
        }

        $data = $this->listContext();

        View::page('admin', 'admin/products/index', $data + [
            'page_title'  => 'Products',
            'current_nav' => 'admin.products',
            'live'        => $this->liveMeta(),
            'page_css'    => ['assets/vendor/libs/sweetalert2/sweetalert2.css'],
            'page_js'     => ['assets/vendor/libs/sweetalert2/sweetalert2.js'],
        ]);
    }

    /**
     * Build the list data once for both the page and its JSON feed.
     *
     * @return array{
     *   products: list<array<string, mixed>>,
     *   pager: Paginator,
     *   filters: array{q: string, status: string},
     *   stats: array{total:int, active:int, inactive:int, out_of_stock:int},
     *   references: array<int, array{orders:int, carts:int, packages:int}>,
     *   available: array<int, int>
     * }
     */
    private function listContext(): array
    {
        $filters = [
            'q'      => Request::string('q'),
            'status' => Request::string('status'),
        ];

        $pager    = Paginator::fromTableRequest(Product::countAdmin($filters));
        $products = Product::adminList($filters, $pager);
        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $products);

        return [
            'products'   => $products,
            'pager'      => $pager,
            'filters'    => $filters,
            'stats'      => Product::stats(),
            'references' => Product::referencesMap($ids),
            'available'  => StockService::availableMap($ids),
        ];
    }

    /**
     * Live-update metadata for the initial page render.
     *
     * When the revision table is unavailable the page still renders,
     * with live updates switched off, instead of failing the screen.
     *
     * @return array{surface:string, enabled:bool, revision:int, etag:string, interval:int}
     */
    private function liveMeta(): array
    {
        $surface = 'admin.products';
        $meta    = [
            'surface'  => $surface,
            'enabled'  => true,
            'revision' => 0,
            'etag'     => '',
            'interval' => 30000,
        ];

        try {
            $revision        = Live::revision($surface);
            $meta['revision'] = $revision;
            $meta['etag']     = Live::etag(
                $surface,
                $revision,
                Live::scope($_GET, (int) (auth_user()['id'] ?? 0))
            );
        } catch (Throwable $e) {
            error_log('[ecowealth] live revision unavailable: ' . $e->getMessage());
            $meta['enabled'] = false;
        }

        return $meta;
    }

    /**
     * JSON feed for the open list page.
     *
     * The 304 check runs before listContext(), so an unchanged surface
     * costs one primary-key lookup and no page data queries. The session
     * is closed first: two tabs polling at once must not serialize on
     * PHP's default file session lock.
     */
    private function feed(): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* Prime the token while the session is still writable; the row
         * fragments render csrf_field() after the lock is released. */
        csrf_token();
        session_write_close();

        $surface = 'admin.products';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->listContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] product live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the product list. Please try again.', 500);
        }
    }

    /**
     * Render the feed payload. Public so the view tests can assert the
     * fragments without going through json_response()'s exit.
     *
     * @param array{
     *   products: list<array<string, mixed>>,
     *   pager: Paginator,
     *   filters: array{q: string, status: string},
     *   stats: array{total:int, active:int, inactive:int, out_of_stock:int},
     *   references: array<int, array{orders:int, carts:int, packages:int}>,
     *   available: array<int, int>
     * } $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'         => true,
            'surface'    => 'admin.products',
            'revision'   => $revision,
            'etag'       => $etag,
            'fragments'  => [
                'product-stats' => View::capture('admin/products/_stats', [
                    'stats' => $data['stats'],
                ]),
                'product-rows' => View::capture('admin/products/_rows', [
                    'products'   => $data['products'],
                    'references' => $data['references'],
                    'available'  => $data['available'],
                    'filters'    => $data['filters'],
                ]),
                'product-pagination' => View::capture('admin/products/_pagination', [
                    'pager'       => $data['pager'],
                    'pager_label' => 'Products',
                ]),
            ],
            'meta' => [
                'page'      => $data['pager']->page,
                'pages'     => $data['pager']->pages,
                'total'     => $data['pager']->total,
                'per_page'  => $data['pager']->perPage,
                'canonical' => $this->canonicalListUrl($data['pager']),
            ],
        ];
    }

    /** The list URL with an out-of-range page clamped to a real page. */
    private function canonicalListUrl(Paginator $pager): string
    {
        $query = [];

        foreach ($_GET as $key => $value) {
            if (is_scalar($value) && $key !== 'XTransformPort') {
                $query[(string) $key] = (string) $value;
            }
        }

        unset($query['page']);

        if ($pager->page > 1) {
            $query['page'] = (string) $pager->page;
        }

        $qs = http_build_query($query);

        return url('admin/products' . ($qs !== '' ? '?' . $qs : ''));
    }

    /** @param array<string, string> $params */
    public function create(array $params = []): void
    {
        $this->forgetInputWhenClean();

        View::page('admin', 'admin/products/form', [
            'page_title'  => 'New product',
            'current_nav' => 'admin.products',
            'product'     => null,
            'current'     => [
                'name'              => '',
                'sku'               => '',
                'price'             => '',
                'stock'             => '0',
                'sort_order'        => '0',
                'short_description' => '',
                'description'       => '',
                'status'            => 'active',
                'image_url'         => null,
            ],
        ]);
    }

    /** @param array<string, string> $params */
    public function edit(array $params = []): void
    {
        $product = Product::find((int) ($params['id'] ?? 0));

        if ($product === null) {
            View::error(404);
        }

        $this->forgetInputWhenClean();

        View::page('admin', 'admin/products/form', [
            'page_title'  => 'Edit product',
            'current_nav' => 'admin.products',
            'product'     => $product,
            'current'     => $product,
        ]);
    }

    /** @param array<string, string> $params */
    public function save(array $params = []): void
    {
        $id       = (int) Request::post('product_id', 0);
        $existing = $id > 0 ? Product::find($id) : null;

        if ($id > 0 && $existing === null) {
            View::error(404);
        }

        $input = [
            'name'              => Request::string('name'),
            'sku'               => Request::string('sku'),
            'price'             => Request::string('price'),
            'stock'             => Request::string('stock'),
            'sort_order'        => Request::string('sort_order'),
            'short_description' => Request::string('short_description'),
            'description'       => Request::string('description'),
            'status'            => Request::string('status', 'active'),
        ];

        $errors   = Product::validate($input, $id > 0 ? $id : null);
        $imageUrl = $existing['image_url'] ?? null;

        /* Uploads only run once the text fields are valid, so a rejected
         * form never leaves an orphan file behind. */
        if ($errors === []) {
            $file       = $_FILES['image'] ?? null;
            $hasUpload  = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $removeImage = Request::post('remove_image') === '1';

            if ($hasUpload) {
                $upload = UploadService::replace($imageUrl === null ? null : (string) $imageUrl, $file, 'products');

                if ($upload['ok']) {
                    $imageUrl = $upload['path'];
                } else {
                    $errors['image'] = (string) $upload['error'];
                }
            } elseif ($removeImage) {
                if ($imageUrl !== null) {
                    UploadService::remove((string) $imageUrl);
                }
                $imageUrl = null;
            }
        }

        if ($errors !== []) {
            errors_set($errors);
            old_set($input);
            redirect($id > 0 ? 'admin/products/' . $id . '/edit' : 'admin/products/new');
        }

        $input['image_url'] = $imageUrl;
        Product::save($input, $id > 0 ? $id : null);

        old_clear();
        flash_set('success', $id > 0 ? 'Product updated.' : 'Product created.');

        /* The form keeps its native POST/redirect flow. Queue every
         * affected surface so the landing page broadcasts to other tabs. */
        Live::publish(['admin.products', 'admin.dashboard']);

        redirect('admin/products');
    }

    /** @param array<string, string> $params */
    public function status(array $params = []): void
    {
        $id      = (int) ($params['id'] ?? 0);
        $status  = Request::string('status');
        $product = Product::find($id);

        if ($product === null) {
            if (Request::wantsJson()) {
                json_response(['ok' => false, 'message' => 'Product not found.'], 404);
            }
            View::error(404);
        }

        $name    = (string) $product['name'];
        $changed = (string) $product['status'] !== $status;

        try {
            Product::setStatus($id, $status);
            $ok      = true;
            $message = $status === 'inactive'
                ? '“' . $name . '” is now disabled — it is hidden from the shop.'
                : '“' . $name . '” is now enabled and visible in the shop.';
        } catch (Throwable $e) {
            error_log('[ecowealth] product status failed: ' . $e->getMessage());
            $ok      = false;
            $message = 'Could not update the product status. Please try again.';
        }

        $changed = $ok && $changed;

        if (Request::wantsJson()) {
            /* The client broadcasts and refreshes only when this is true. */
            json_response([
                'ok'          => $ok,
                'changed'     => $changed,
                'message'     => $message,
                'status'      => $status,
                'invalidates' => $changed ? ['admin.products', 'admin.dashboard'] : [],
            ], $ok ? 200 : 422);
        }

        if ($changed) {
            Live::publish(['admin.products', 'admin.dashboard']);
        }

        flash_set($ok ? 'success' : 'danger', $message);
        redirect('admin/products');
    }

    /** @param array<string, string> $params */
    public function delete(array $params = []): void
    {
        $result = Product::delete((int) ($params['id'] ?? 0));

        /* Name every reference that blocks the delete — the remedies differ
         * (order history is permanent, a cart line can be emptied, a package
         * can be edited). */
        $parts = [];
        if (($result['orders'] ?? 0) > 0) {
            $parts[] = 'order history';
        }
        if (($result['carts'] ?? 0) > 0) {
            $parts[] = 'a member’s cart';
        }
        if (($result['packages'] ?? 0) > 0) {
            $parts[] = 'a package';
        }

        $message = match (true) {
            $result['ok'] => 'Product deleted.',
            $result['reason'] === 'missing' => 'Product not found.',
            default => 'Cannot delete: this product appears in ' . implode(' and ', $parts) . '.',
        };

        if (Request::wantsJson()) {
            json_response([
                'ok'          => $result['ok'],
                'message'     => $message,
                'invalidates' => $result['ok'] ? ['admin.products', 'admin.dashboard'] : [],
            ], $result['ok'] ? 200 : 422);
        }

        if ($result['ok']) {
            Live::publish(['admin.products', 'admin.dashboard']);
        }

        flash_set($result['ok'] ? 'success' : 'danger', $message);
        redirect('admin/products');
    }

    /**
     * A form opened without pending validation errors should show the
     * database values, not leftover input from an earlier failed submit.
     * (errors_all() primes the per-request cache the view reads next.)
     */
    private function forgetInputWhenClean(): void
    {
        if (errors_all() === []) {
            old_clear();
        }
    }
}
