<?php
declare(strict_types=1);

/* ==============================================================
 *  GenealogyController — the sponsor chain, up and down
 *  (plan §2.5, §7 Phase 9; reference MemberController::genealogy)
 *
 *  Route (Appendix A): GET /genealogy   guard `member`
 *
 *  Shows:
 *    • the upline chain (max 10) — nearest sponsor first
 *    • direct recruits (level 1 of the downline)
 *    • the deeper team, grouped by level (max 10)
 *    • the member's own referral link
 *
 *  There is no binary tree anywhere: every row here comes from
 *  users.sponsor_id (plan §1.3). P9-T17 asserts the pages never
 *  mention a binary column.
 * ============================================================== */

final class GenealogyController
{
    /** @param array<string, string> $params */
    public function index(array $params = []): void
    {
        $user = auth_user();

        if ($user === null) {
            redirect('login');
        }

        $userId = (int) $user['id'];
        $member = Member::find($userId);

        if ($member === null) {
            View::error(404);
        }

        $sponsors = new SponsorService();
        $chain    = $sponsors->chain($userId, SponsorService::MAX_LEVELS);
        $team     = $sponsors->downline($userId, SponsorService::MAX_LEVELS);

        /* Group the downline by level for the template. */
        $byLevel = [];

        foreach ($team as $row) {
            $byLevel[(int) $row['level']][] = $row;
        }

        ksort($byLevel);

        $direct = [];

        foreach ($byLevel[1] ?? [] as $row) {
            $direct[] = $row;
        }

        $deeper = [];

        foreach ($byLevel as $level => $rows) {
            if ($level === 1) {
                continue;
            }

            $deeper[$level] = $rows;
        }

        $isAdmin = is_admin($user);

        View::page($isAdmin ? 'admin' : 'member', 'member/genealogy', [
            'page_title'    => 'Genealogy',
            'current_nav'   => $isAdmin ? 'admin.genealogy' : 'genealogy',
            'page_css'      => ['assets/css/membership.css'],
            'member'        => $member,
            'upline'        => $chain,
            'direct'        => $direct,
            'deeper'        => $deeper,
            'team_count'    => count($team),
            'levels_used'   => $byLevel === [] ? 0 : max(array_keys($byLevel)),
            'referral_code' => (string) ($member['referral_code'] ?? ''),
            'referral_link' => url('register?ref=' . rawurlencode((string) ($member['referral_code'] ?? ''))),
        ]);
    }
}
