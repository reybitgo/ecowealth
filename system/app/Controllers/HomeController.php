<?php
declare(strict_types=1);

/* ==============================================================
 *  HomeController — the app's front door (plan §3.3, D23)
 *
 *  GET / is a redirect only: members land on their dashboard,
 *  visitors on the login page. The public marketing page lives
 *  outside the app (repo-root index.html) until the EcoWealth
 *  frontend rebuild, so no landing view ships inside public/.
 * ============================================================== */

final class HomeController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        if (auth_check()) {
            redirect('dashboard');
        }

        redirect('login');
    }
}
