<?php

namespace ErnestDefoe\TrustLevels\Api;

use Carbon\Carbon;
use ErnestDefoe\TrustLevels\Access\Exempt;
use ErnestDefoe\TrustLevels\Config;
use ErnestDefoe\TrustLevels\LevelWriter;
use ErnestDefoe\TrustLevels\Spam\Spam;
use Flarum\Http\RequestUtil;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET  /api/trust-levels/users/{id}: the progress tab. The member themselves
 *      or staff with the manage permission; anyone else gets a 404, so it
 *      can't be used to learn someone's level.
 * POST /api/trust-levels/users/{id} {level?, locked?, note?, silenced?}: staff only, never
 *      on their own account; Leader and raises that grant a level group's permissions
 *      need an admin.
 */
class MemberController implements RequestHandlerInterface
{
    public function __construct(
        private Progress $progress,
        private LevelWriter $writer,
        private Spam $spam,
        private ConnectionInterface $db,
        private Config $config,
        private Exempt $exempt,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();

        $user = User::query()->find((int) ($request->getAttribute('routeParameters')['id'] ?? 0));
        $staff = $actor->hasPermission('ernestdefoe-trust-levels.manage');

        if (! $user || (! $staff && $actor->id !== $user->id)) {
            return Json::error(404, 'not_found');
        }

        if ($request->getMethod() === 'POST') {
            // Nobody changes their own level, lock or hold: a moderator could
            // otherwise make themselves Leader and take its permissions.
            if (! $staff || $actor->id === $user->id) {
                return Json::error(403, 'permission_denied');
            }

            if (! $actor->isAdmin() && $this->grantsWhatOnlyAdminsMay($user, Json::body($request))) {
                return Json::error(403, 'admin_only');
            }

            $this->update($user, $actor, Json::body($request));
            $user->refresh();
        }

        return new JsonResponse(['data' => $this->progress->for($user, $staff)]);
    }

    /**
     * Leader, and any raise that hands over a level group's permissions, are
     * an admin's call. Moderators can still lower, lock and lift holds.
     */
    private function grantsWhatOnlyAdminsMay(User $user, array $body): bool
    {
        if (! array_key_exists('level', $body) || ! is_numeric($body['level'])) {
            return false;
        }

        $from = (int) $user->getAttribute('trust_level');
        $to = max(0, min(Config::MAX, (int) $body['level']));

        if ($to <= $from) {
            return false;
        }

        if ($to === Config::MAX) {
            return true;
        }

        $groups = array_values(array_filter($this->config->groups(), fn ($level) => $level > $from && $level <= $to, ARRAY_FILTER_USE_KEY));

        return $groups && $this->db->table('group_permission')->whereIn('group_id', $groups)->exists();
    }

    private function update(User $user, User $actor, array $body): void
    {
        if (array_key_exists('note', $body)) {
            $note = trim((string) $body['note']);
            $this->db->table('users')->where('id', $user->id)->update(['trust_note' => $note === '' ? null : mb_substr($note, 0, 200)]);
        }

        if (array_key_exists('level', $body) && is_numeric($body['level'])) {
            $level = max(0, min(Config::MAX, (int) $body['level']));
            $this->writer->set($user, $level, 'staff', $actor);
        }

        if (array_key_exists('locked', $body) && (bool) $body['locked'] !== (bool) $user->getAttribute('trust_locked')) {
            $locked = (bool) $body['locked'];
            // A lock is a placement too: a member locked before their first check
            // would otherwise be picked up as unplaced by every run.
            $this->db->table('users')->where('id', $user->id)->update(['trust_locked' => $locked]);
            // At the level they count as now (a member from before the install is Basic),
            // never by resetting them to the 0 stored before their first check.
            $this->db->table('users')->where('id', $user->id)->whereNull('trust_level_at')
                ->update(['trust_level' => $this->exempt->level($user), 'trust_level_at' => Carbon::now()]);
            $this->writer->log($user->id, $locked ? 'locked' : 'unlocked', null, (int) $user->getAttribute('trust_level'), $actor->id);
        }

        if (($body['silenced'] ?? null) === false && $user->getAttribute('trust_silenced_at')) {
            $this->spam->unsilence($user, $actor);
        }
    }
}
