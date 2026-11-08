<?php
declare(strict_types=1);

/* ==============================================================
 *  PackageController — the member-facing package cards
 *  (plan §7 Phase 3: "members can view them")
 *
 *  Activation itself lands in Phase 9 (Membership); this page only
 *  presents the active packages, their price, product value, direct
 *  bonus, ladder and contents.
 *
 *  The page is one live surface (`shop.packages`). The cards (or the
 *  empty state) are a region; an admin package save or status change
 *  bumps it through Package::save()/setStatus().
 * ============================================================== */

final class PackageController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $userId = (int) (auth_user()['id'] ?? 0);

        /* The same URL doubles as the JSON feed. The cards have no forms,
         * so the session lock can be released for the feed immediately. */
        if (Live::isFeedRequest()) {
            $this->feed($userId);
            return;
        }

        View::page('member', 'shop/packages', $this->packagesContext() + [
            'page_title'  => 'Packages',
            'current_nav' => 'packages',
            'live'        => Live::meta('shop.packages', Live::scope($_GET, $userId)),
        ]);
    }

    /**
     * Everything the catalogue renders, built once for the page and its
     * feed. Public so the view tests can assert the real screen data.
     *
     * @return array<string, mixed>
     */
    public function packagesContext(): array
    {
        return ['packages' => PackageService::publicCards()];
    }

    /**
     * JSON feed for the open package catalogue.
     *
     * The 304 check runs before packagesContext(), so an unchanged
     * catalogue costs one revision lookup and no package queries.
     */
    private function feed(int $userId): void
    {
        session_write_close();

        $surface = 'shop.packages';

        try {
            $revision = Live::revision($surface);
            $etag     = Live::etag($surface, $revision, Live::scope($_GET, $userId));

            if (Live::matchesIfNoneMatch(Request::header('If-None-Match'), $etag)) {
                Live::notModified($etag);
            }

            Live::json($etag, $this->feedPayload($revision, $etag, $this->packagesContext()));
        } catch (Throwable $e) {
            error_log('[ecowealth] package catalogue live feed failed: ' . $e->getMessage());
            Live::jsonError('Could not refresh the packages. Please try again.', 500);
        }
    }

    /**
     * Render the catalogue payload. Public so tests can assert the
     * fragment without going through Live::json()'s exit.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function feedPayload(int $revision, string $etag, array $data): array
    {
        return [
            'ok'        => true,
            'surface'   => 'shop.packages',
            'revision'  => $revision,
            'etag'      => $etag,
            'fragments' => [
                'package-cards' => View::capture('shop/packages/_cards', [
                    'packages' => $data['packages'],
                ]),
            ],
            'meta'      => [
                'canonical' => url('packages'),
            ],
        ];
    }
}
