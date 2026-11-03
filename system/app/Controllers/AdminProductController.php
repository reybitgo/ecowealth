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
        $filters = [
            'q'      => Request::string('q'),
            'status' => Request::string('status'),
        ];

        $pager    = Paginator::fromRequest(Product::countAdmin($filters), 20);
        $products = Product::adminList($filters, $pager);
        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $products);

        View::page('admin', 'admin/products/index', [
            'page_title'  => 'Products',
            'current_nav' => 'admin.products',
            'products'    => $products,
            'pager'       => $pager,
            'filters'     => $filters,
            'stats'       => Product::stats(),
            'references'  => Product::referencesMap($ids),
            'available'   => StockService::availableMap($ids),
            'page_css'    => ['assets/vendor/libs/sweetalert2/sweetalert2.css'],
            'page_js'     => ['assets/vendor/libs/sweetalert2/sweetalert2.js'],
        ]);
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

        $name = (string) $product['name'];

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

        if (Request::wantsJson()) {
            json_response(['ok' => $ok, 'message' => $message, 'status' => $status], $ok ? 200 : 422);
        }

        flash_set($ok ? 'success' : 'danger', $message);
        redirect('admin/products');
    }

    /** @param array<string, string> $params */
    public function delete(array $params = []): void
    {
        $result = Product::delete((int) ($params['id'] ?? 0));

        $message = match (true) {
            $result['ok'] => 'Product deleted.',
            $result['reason'] === 'missing' => 'Product not found.',
            $result['reason'] === 'orders_and_cart'
                => 'Cannot delete: this product has been bought and is in a member’s cart.',
            $result['reason'] === 'orders'
                => 'Cannot delete: this product has been bought and is part of an order history.',
            default => 'Cannot delete: this product is in a member’s cart.',
        };

        if (Request::wantsJson()) {
            json_response(['ok' => $result['ok'], 'message' => $message], $result['ok'] ? 200 : 422);
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
