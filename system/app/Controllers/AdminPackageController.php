<?php
declare(strict_types=1);

/* ==============================================================
 *  AdminPackageController — associate package management
 *  (plan §7 Phase 3; reference: AdminController::packages/savePackage)
 *
 *  Packages are created and edited on dedicated pages, deactivated
 *  with a status toggle and never deleted. The level matrix stores
 *  levels 2–10 only: level 1 is the direct referral bonus, shown
 *  read-only in the form and saved once on the package row.
 * ============================================================== */

final class AdminPackageController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $filters = [
            'q'      => Request::string('q'),
            'status' => Request::string('status'),
        ];

        $pager    = Paginator::fromTableRequest(Package::countAdmin($filters));
        $packages = Package::adminList($filters, $pager);
        $ids      = array_map(static fn (array $row): int => (int) $row['id'], $packages);

        View::page('admin', 'admin/packages/index', [
            'page_title'  => 'Packages',
            'current_nav' => 'admin.packages',
            'packages'    => $packages,
            'pager'       => $pager,
            'filters'     => $filters,
            'stats'       => Package::stats(),
            'levels'      => Package::levelsMap($ids),
            'contents'    => Package::contentsMap($ids),
            'page_css'    => ['assets/vendor/libs/sweetalert2/sweetalert2.css'],
            'page_js'     => ['assets/vendor/libs/sweetalert2/sweetalert2.js'],
        ]);
    }

    /** @param array<string, string> $params */
    public function create(array $params = []): void
    {
        $payload = old_payload();

        View::page('admin', 'admin/packages/form', [
            'page_title'      => 'New package',
            'current_nav'     => 'admin.packages',
            'package'         => null,
            'current'         => $payload['input'] ?? self::defaults(),
            'levels'          => $payload['levels'] ?? self::blankLevels(),
            'contents'        => $payload['contents'] ?? [],
            'content_options' => PackageService::contentOptions(null),
        ]);
    }

    /** @param array<string, string> $params */
    public function edit(array $params = []): void
    {
        $package = Package::find((int) ($params['id'] ?? 0));

        if ($package === null) {
            View::error(404);
        }

        $payload  = old_payload();
        $packageId = (int) $package['id'];

        View::page('admin', 'admin/packages/form', [
            'page_title'      => 'Edit package',
            'current_nav'     => 'admin.packages',
            'package'         => $package,
            'current'         => $payload['input'] ?? $package,
            'levels'          => $payload['levels'] ?? self::levelsForForm($packageId),
            'contents'        => $payload['contents'] ?? self::contentsForForm($packageId),
            'content_options' => PackageService::contentOptions($packageId),
        ]);
    }

    /** @param array<string, string> $params */
    public function save(array $params = []): void
    {
        $id       = (int) Request::post('package_id', 0);
        $existing = $id > 0 ? Package::find($id) : null;

        if ($id > 0 && $existing === null) {
            View::error(404);
        }

        $input = [
            'name'                  => Request::string('name'),
            'price'                 => Request::string('price'),
            'product_value'         => Request::string('product_value'),
            'direct_referral_bonus' => Request::string('direct_referral_bonus'),
            'sort_order'            => Request::string('sort_order'),
            'description'           => Request::string('description'),
            'perks'                 => Request::string('perks'),
            'status'                => Request::string('status', 'active'),
            'indirect_enabled'      => Request::post('indirect_enabled') === '1' ? '1' : '0',
        ];

        $levelsInput = is_array($_POST['levels'] ?? null) ? $_POST['levels'] : [];
        $levels      = PackageService::levelsFromInput($levelsInput);

        $contentsInput = PackageService::contentsFromInput(
            is_array($_POST['content_product_id'] ?? null) ? $_POST['content_product_id'] : [],
            is_array($_POST['content_quantity'] ?? null) ? $_POST['content_quantity'] : []
        );

        $errors = Package::validate($input);
        $errors += Package::validateLevels($levelsInput);

        $normalized = Package::normalizeContents($contentsInput, $id > 0 ? $id : null);
        $errors    += $normalized['errors'];

        /* Image uploads only run once everything else validates. */
        $image = $existing['image'] ?? null;
        $image = $image === null ? null : (string) $image;

        if ($errors === []) {
            $file        = $_FILES['image'] ?? null;
            $hasUpload   = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $removeImage = Request::post('remove_image') === '1';

            if ($hasUpload) {
                $upload = UploadService::replace($image, $file, 'packages');

                if ($upload['ok']) {
                    $image = $upload['path'];
                } else {
                    $errors['image'] = (string) $upload['error'];
                }
            } elseif ($removeImage) {
                if ($image !== null) {
                    UploadService::remove($image);
                }
                $image = null;
            }
        }

        if ($errors !== []) {
            errors_set($errors);
            old_payload_set(['input' => $input, 'levels' => $levels, 'contents' => $contentsInput]);
            redirect($id > 0 ? 'admin/packages/' . $id . '/edit' : 'admin/packages/new');
        }

        $input['image']            = $image;
        $input['indirect_enabled'] = $input['indirect_enabled'] === '1';

        Package::save($input, $levels, $normalized['rows'], $id > 0 ? $id : null);
        old_payload_clear();

        flash_set('success', $id > 0 ? 'Package updated.' : 'Package created.');
        redirect('admin/packages');
    }

    /** @param array<string, string> $params */
    public function status(array $params = []): void
    {
        $id      = (int) ($params['id'] ?? 0);
        $status  = Request::string('status');
        $package = Package::find($id);

        if ($package === null) {
            if (Request::wantsJson()) {
                json_response(['ok' => false, 'message' => 'Package not found.'], 404);
            }
            View::error(404);
        }

        $name = (string) $package['name'];

        try {
            Package::setStatus($id, $status);
            $ok      = true;
            $message = $status === 'inactive'
                ? '“' . $name . '” is now disabled — it is hidden from the packages page.'
                : '“' . $name . '” is now enabled and visible to members.';
        } catch (Throwable $e) {
            error_log('[ecowealth] package status failed: ' . $e->getMessage());
            $ok      = false;
            $message = 'Could not update the package status. Please try again.';
        }

        if (Request::wantsJson()) {
            json_response(['ok' => $ok, 'message' => $message, 'status' => $status], $ok ? 200 : 422);
        }

        flash_set($ok ? 'success' : 'danger', $message);
        redirect('admin/packages');
    }

    /* ----------------------------------------------------------
     *  Form defaults
     * ---------------------------------------------------------- */

    /** @return array<string, mixed> */
    private static function defaults(): array
    {
        return [
            'name'                  => '',
            'price'                 => '',
            'product_value'         => '',
            'direct_referral_bonus' => '',
            'sort_order'            => '0',
            'description'           => '',
            'perks'                 => '',
            'status'                => 'active',
            'indirect_enabled'      => '1',
            'image'                 => null,
        ];
    }

    /** @return array<int, string> */
    private static function blankLevels(): array
    {
        return array_fill_keys(range(Package::LEVEL_MIN, Package::LEVEL_MAX), '');
    }

    /** @return array<int, string> */
    private static function levelsForForm(int $packageId): array
    {
        $levels = [];

        foreach (Package::levels($packageId) as $level => $amount) {
            $levels[$level] = $amount > 0 ? number_format($amount, 2, '.', '') : '';
        }

        return $levels;
    }

    /** @return list<array{product_id: int, quantity: int}> */
    private static function contentsForForm(int $packageId): array
    {
        $rows = [];

        foreach (Package::contents($packageId) as $content) {
            $rows[] = ['product_id' => $content['product_id'], 'quantity' => $content['quantity']];
        }

        return $rows;
    }
}
